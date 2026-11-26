<?php

declare(strict_types=1);

namespace app\command;

use think\console\Output;
use app\enum\MarketExchangeEnum;
use app\enum\MarketDataTypeEnum;
use app\lib\MarketHelper;
use app\lib\MarketRestHuobi;
use Workerman\Connection\AsyncTcpConnection;

/**
 * 火币 (Huobi) 行情直连采集服务
 *   php think market:huobi start -t 1
 * 
 * 现货文档:https://huobiapi.github.io/docs/spot/v1/cn/#185368440e
 * 合约文档(U本位合约):https://huobiapi.github.io/docs/usdt_swap/v1/cn/#14f70b1746-2
 */
class MarketHuobi extends MarketCollectorAbstract
{

    protected function exchange(): MarketExchangeEnum
    {
        return MarketExchangeEnum::HUOBI;
    }

    private static function huobiDepthSub(string $symbol, int $type): array
    {
        $levels = 20;

        return $type == MarketDataTypeEnum::SPOT->value
            ? ['sub' => "market.{$symbol}.mbp.refresh.{$levels}"]
            : ['sub' => "market.{$symbol}.depth.size_{$levels}.high_freq", 'data_type' => 'snapshot'];
    }

    protected function startCollectors(Output $output, int $type, array $symbols): void
    {
        $this->connectHuobi($output, $type, $symbols);
        // 标记价格频道不在 linear-swap-ws（行情）地址下，需单独连 ws_index（仅合约有该数据源）
        if ($type != MarketDataTypeEnum::SPOT->value) {
            $this->connectHuobiMarkPrice($output, $type, $symbols);
        }
    }

    /**
     * 建立火币 WebSocket 连接并处理消息
     */
    private function connectHuobi(Output $output, int $type, array $symbols)
    {



        $typeName = ($type == MarketDataTypeEnum::SPOT->value) ? 'Spot' : 'Swap';
        $wsUrls = (array)\think\facade\Config::get('market.endpoints.huobi.ws', []);
        $remote_url = ($type == MarketDataTypeEnum::SPOT->value) ? $wsUrls['spot'] : $wsUrls['swap'];

        // 周期映射: 平台标准(秒) => 火币标准
        $periodMap = MarketRestHuobi::PERIOD_MAP;
        // 反向映射: 火币标准 => 平台标准(秒)
        $revPeriodMap = array_flip($periodMap);

        if (!\Workerman\Worker::$daemonize) {
            $output->writeln("<info>[Market]</info> Huobi-{$typeName} connecting to {$remote_url}");
        }

        $con = new AsyncTcpConnection($remote_url);
        $con->transport = 'ssl';

        $connName = "Huobi-{$typeName}";
        // 两个时间分开记：$lastMsgTime 任何下行帧都刷（含火币每几秒一次的 ping），只用来在日志里区分
        // 「连接还活着」；$lastDataTime 只在收到行情数据帧（带 ch）时刷，看门狗按它判断。
        // 不能拿前者判断：连接在、订阅却没了（重连后订阅被拒、火币后端切换丢了订阅）时 ping 照发，
        // 看门狗会一直以为正常，行情停几个小时也不重连
        $lastMsgTime  = time();
        $lastDataTime = time();
        $subErrors    = 0;

        \Workerman\Timer::add(30, \app\lib\TaskLiveness::wrap('ws_watchdog:' . $typeName, function () use ($con, &$lastMsgTime, &$lastDataTime, $output, $connName) {
            $this->pingDb();
            $this->watchdogCheck($con, $connName, $lastDataTime, $lastMsgTime, $output);
        }));

        $con->onConnect = function ($con) use ($output, $symbols, $type, $typeName, $periodMap, $connName, &$lastDataTime, &$subErrors) {
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<info>[Market]</info> Huobi-{$typeName} WebSocket Connected.");
            }
            // 刚连上还没有数据帧，从现在起给 90 秒；订阅全被拒的话 90 秒后看门狗会再重连一次
            $lastDataTime = time();
            $subErrors = 0;
            business_log('market_collect_stat', sprintf(
                '[%s] 已连接，发出订阅 %d 条（%d 个交易对 × %d 个频道）',
                $connName, count($symbols) * (count($periodMap) + 3), count($symbols), count($periodMap) + 3
            ));
            foreach ($symbols as $symbol) {
                // 1. 订阅实时详情 (Ticker)
                $subDetail = "market.{$symbol}.detail";
                $con->send(json_encode(['sub' => $subDetail, 'id' => $symbol]));
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<comment>[Sub]</comment> {$subDetail}");
                }

                // 2. 订阅多周期 K 线
                foreach ($periodMap as $stdPeriod => $huobiPeriod) {
                    $subKline = "market.{$symbol}.kline.{$huobiPeriod}";
                    $con->send(json_encode(['sub' => $subKline, 'id' => "{$symbol}.{$huobiPeriod}"]));
                }

                // 3. 订阅深度 (OrderBook)
                // 默认只订 (20) 档而不是 step0 的 150 档——下游 updateDepth() 本来就只留
                // 前 20 档，多出来的 130 档从采集到跨机分发全程都是白跑，实测帧体能小 5~7 倍。
                // 现货合约用的是两套完全不同的频道，见 huobiDepthSub() 注释。
                $subDepth = self::huobiDepthSub($symbol, $type);
                $con->send(json_encode($subDepth + ['id' => "depth.{$symbol}"]));

                // 4. 订阅成交历史 (Trades)
                $subTrade = "market.{$symbol}.trade.detail";
                $con->send(json_encode(['sub' => $subTrade, 'id' => "trade.{$symbol}"]));

                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<comment>[Sub]</comment> {$symbol} Ticker/Kline/Depth/Trade");
                }
            }
        };

        // onMessage 整段计入事件循环占用（gzdecode + json_decode 是这条路径上最大的一块 CPU，
        // 此前完全没有被任何指标覆盖）。包在**赋值处**而不是回调体内部，
        // 省掉一层闭包和一份逐字重复的 use 列表，见 EventLoopBusyTrait::timedHandler()
        $con->onMessage = $this->timedHandler('msg:' . $typeName, function ($con, $binary_data) use ($output, $type, $typeName, $revPeriodMap, $connName, &$lastMsgTime, &$lastDataTime, &$subErrors) {
            $lastMsgTime = time();
            $data_json = gzdecode($binary_data);
            if ($data_json === false) return;
            $data = json_decode($data_json, true, 512, JSON_BIGINT_AS_STRING);
            if (!$data) return;

            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<info>[Huobi-{$typeName}]</info> onMessage {$data_json}");
            }

            if (isset($data['ping'])) {
                $con->send(json_encode(['pong' => $data['ping']]));
                return;
            }

            // 订阅应答 {"id":..,"status":"ok|error","subbed"|"err-code"/"err-msg":..}
            if (($data['status'] ?? '') === 'error') {
                $this->logSubError($connName, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $subErrors);
                return;
            }

            if (isset($data['ch'])) {
                $lastDataTime = time();
                // 真正收到行情数据才算连接可用、清零退避；收到 ping 不算，否则订阅一直失败时退避永远长不起来
                $this->resetReconnectBackoff("huobi:{$typeName}");
                $channel = $data['ch'];
                $klineRegex = '/market\.(.*?)\.kline\.(.*)/';

                // 处理 成交历史数据
                if (strpos($channel, '.trade.detail') !== false && isset($data['tick']['data'])) {
                    preg_match('/market\.(.*?)\.trade/', $channel, $matches);
                    $symbol = $matches[1] ?? '';
                    if ($symbol) {
                        if (!\Workerman\Worker::$daemonize) {
                            $output->writeln("<info>[Huobi-{$typeName}]</info> {$symbol} Trade Update");
                        }
                        $this->handleTrade(MarketExchangeEnum::HUOBI, $type, $symbol, $data['tick']['data']);
                    }
                }
                // 处理 实时详情
                elseif (strpos($channel, '.detail') !== false && isset($data['tick'])) {
                    preg_match('/market\.(.*?)\.detail/', $channel, $matches);
                    $symbol = $matches[1] ?? '';
                    if ($symbol) {
                        $tick = $data['tick'];
                        if (!\Workerman\Worker::$daemonize) {
                            $output->writeln("<info>[Huobi-{$typeName}]</info> {$symbol} Price: <fg=yellow>{$tick['close']}</>");
                        }
                        $this->handlePrice(MarketExchangeEnum::HUOBI, $symbol, $type, $tick, $data['ts'] ?? 0);
                    }
                }
                // 处理 K 线数据
                elseif (preg_match($klineRegex, $channel, $matches) && isset($data['tick'])) {
                    $symbol = $matches[1] ?? '';
                    $huobiPeriod = $matches[2] ?? '';
                    $stdPeriod = $revPeriodMap[$huobiPeriod] ?? $huobiPeriod; // 转换为平台标准周期(秒)

                        if ($symbol && $stdPeriod) {
                            if (!\Workerman\Worker::$daemonize) {
                                $kTime = date('Y-m-d H:i:s', (int)$data['tick']['id']);
                                $output->writeln("<info>[Huobi-{$typeName}]</info> {$symbol} KLine({$stdPeriod}s) Update - <fg=cyan>{$kTime}</>");
                            }
                            $this->handleKline(MarketExchangeEnum::HUOBI, $type, $symbol, (string)$stdPeriod, $data['tick']);
                        }
                }
                // 处理 深度数据
                // 现货与合约的频道形状不同，两种都要认（见 huobiDepthSub()）：
                //   market.$s.mbp.refresh.20            现货 20 档
                //   market.$c.depth.size_20.high_freq   合约 20 档
                // `.depth.` 写得宽一点也顺带兼容旧的 depth.step0，回滚时不至于解析不出来
                elseif ((strpos($channel, '.mbp.refresh.') !== false || strpos($channel, '.depth.') !== false)
                    && isset($data['tick'])
                ) {
                    preg_match('/market\.(.*?)\.(?:depth|mbp)\./', $channel, $matches);
                    $symbol = $matches[1] ?? '';
                    if ($symbol) {
                        if (!\Workerman\Worker::$daemonize) {
                            $output->writeln("<info>[Huobi-{$typeName}]</info> {$symbol} Depth Update");
                        }

                        $tick = $data['tick'];
                        // mbp.refresh 的 tick 里**没有 ts**(只有 seqNum/bids/asks)，ts 只在消息顶层；
                        // 而 depth.step0 / depth.size_N.high_freq 的 tick 自带 ts。
                        // 不补这一下的话，updateDepth() 会退化成 `$depth['ts'] ?? microtime()`，
                        // 深度时间戳变成"收到那一刻"，于是 SpotMarketMaker/ContractMarketMaker 里
                        // 那段"深度快照过期就撤单停挂"的保护永久失效——WS 静默时会拿陈旧深度继续挂单被套利。
                        if (!isset($tick['ts']) && isset($data['ts'])) {
                            $tick['ts'] = $data['ts'];
                        }

                        $this->handleDepth(MarketExchangeEnum::HUOBI, $type, $symbol, $tick);
                    }
                }
            }
        });

        $con->onError = function ($con, $code, $msg) use ($output, $typeName) {
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<error>[Huobi-{$typeName}] WebSocket Error: {$msg} (code:{$code})</error>");
            }
        };

        $con->onClose = function ($con) use ($output, $typeName) {
            // 指数退避而非固定 5s：被 429 限流时固定间隔重连会一直硬撞、限流窗口永远刷不掉
            $delay = $this->nextReconnectDelay("huobi:{$typeName}");
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<comment>[Huobi-{$typeName}]</comment> WebSocket Closed. Reconnecting in {$delay}s...");
            }
            $con->reConnect($delay);
        };

        $con->connect();
    }

    /**
     * 建立火币标记价格 WebSocket 连接（仅合约）
     * 标记价格频道与普通行情（detail/kline/depth/trade）不在同一个 WS 地址下，
     * 行情走 linear-swap-ws，标记价格/指数/基差走 ws_index，需单独建连订阅
     */
    private function connectHuobiMarkPrice(Output $output, int $type, array $symbols)
    {
        $typeName = 'Swap';
        $remote_url = (string)\think\facade\Config::get('market.endpoints.huobi.ws.mark_price', 'ws://api.hbdm.com/ws_index');

        if (!\Workerman\Worker::$daemonize) {
            $output->writeln("<info>[Market]</info> Huobi-{$typeName}-MarkPrice connecting to {$remote_url}");
        }

        $con = new AsyncTcpConnection($remote_url);
        $con->transport = 'ssl';

        $connName = "Huobi-{$typeName}-MarkPrice";
        // 两个时间分开记，理由见 connectHuobi()
        $lastMsgTime  = time();
        $lastDataTime = time();
        $subErrors    = 0;

        \Workerman\Timer::add(30, \app\lib\TaskLiveness::wrap('ws_watchdog:' . $typeName, function () use ($con, &$lastMsgTime, &$lastDataTime, $output, $connName) {
            $this->pingDb();
            $this->watchdogCheck($con, $connName, $lastDataTime, $lastMsgTime, $output);
        }));

        $con->onConnect = function ($con) use ($output, $symbols, $typeName, $connName, &$lastDataTime, &$subErrors) {
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<info>[Market]</info> Huobi-{$typeName}-MarkPrice WebSocket Connected.");
            }
            $lastDataTime = time();
            $subErrors = 0;
            business_log('market_collect_stat', sprintf('[%s] 已连接，发出订阅 %d 条', $connName, count($symbols)));
            foreach ($symbols as $symbol) {
                // 频道名格式已用 REST /index/market/history/linear_swap_mark_price_kline 返回的 ch 字段核实，
                // 是 market.{contract_code}.mark_price.{period}，不带 .kline. 分段
                $subMarkPrice = "market.{$symbol}.mark_price.1min";
                $con->send(json_encode(['sub' => $subMarkPrice, 'id' => "mark_price.{$symbol}"]));
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<comment>[Sub]</comment> {$subMarkPrice}");
                }
            }
        };

        // onMessage 整段计入事件循环占用（gzdecode + json_decode 是这条路径上最大的一块 CPU，
        // 此前完全没有被任何指标覆盖）。包在**赋值处**而不是回调体内部，
        // 省掉一层闭包和一份逐字重复的 use 列表，见 EventLoopBusyTrait::timedHandler()
        $con->onMessage = $this->timedHandler('msg:' . $typeName, function ($con, $binary_data) use ($output, $type, $typeName, $connName, &$lastMsgTime, &$lastDataTime, &$subErrors) {
            $lastMsgTime = time();
            $data_json = gzdecode($binary_data);
            if ($data_json === false) return;
            $data = json_decode($data_json, true, 512, JSON_BIGINT_AS_STRING);
            if (!$data) return;

            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<info>[Huobi-{$typeName}-MarkPrice]</info> onMessage {$data_json} </>");
            }

            if (isset($data['ping'])) {
                $con->send(json_encode(['pong' => $data['ping']]));
                return;
            }

            if (($data['status'] ?? '') === 'error') {
                $this->logSubError($connName, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $subErrors);
                return;
            }

            if (isset($data['ch']) && isset($data['tick']['close'])) {
                $lastDataTime = time();
                $this->resetReconnectBackoff('huobi:mark_price');
                $channel = $data['ch'];
                // 频道名格式 market.{symbol}.mark_price.{period}，K线形态推送，当前未收盘K线的 close 即近实时标记价
                if (strpos($channel, '.mark_price.') !== false) {
                    preg_match('/market\.(.*?)\.mark_price\./', $channel, $matches);
                    $symbol = $matches[1] ?? '';
                    if ($symbol) {
                        if (!\Workerman\Worker::$daemonize) {
                            $output->writeln("<info>[Huobi-{$typeName}-MarkPrice]</info> {$symbol} MarkPrice: <fg=magenta>{$data['tick']['close']}</>");
                        }
                        $this->handleMarkPrice(MarketExchangeEnum::HUOBI, $symbol, $type, (string)$data['tick']['close'], (int)($data['ts'] ?? (microtime(true) * 1000)));
                    }
                }
            }
        });

        $con->onError = function ($con, $code, $msg) use ($output, $typeName) {
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<error>[Huobi-{$typeName}-MarkPrice] WebSocket Error: {$msg} (code:{$code})</error>");
            }
        };

        $con->onClose = function ($con) use ($output, $typeName) {
            $delay = $this->nextReconnectDelay('huobi:mark_price');
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<comment>[Huobi-{$typeName}-MarkPrice]</comment> WebSocket Closed. Reconnecting in {$delay}s...");
            }
            $con->reConnect($delay);
        };

        $con->connect();
    }
}
