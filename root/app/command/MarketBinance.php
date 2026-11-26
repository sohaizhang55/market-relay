<?php

declare(strict_types=1);

namespace app\command;

use think\console\Output;
use app\enum\MarketExchangeEnum;
use app\enum\MarketDataTypeEnum;
use app\lib\MarketRestBinance;
use Workerman\Connection\AsyncTcpConnection;

/**
 * 币安 (Binance) 行情直连采集服务
 *   php think market:binance start -t 1
 *
 * 期货新路由规则（旧版 /ws、/stream 端点已于 2026-04-23 下线）：
 *   文档: https://developers.binance.com/docs/derivatives/usds-margined-futures/websocket-market-streams/Important-WebSocket-Change-Notice
 *   Mark Price Stream: https://developers.binance.com/docs/derivatives/usds-margined-futures/websocket-market-streams/Mark-Price-Stream
 *
 * [!] 端点一律用**国际站**(stream/api/fstream/fapi .binance.com)，不要改成 .us：
 *   Binance.US 的上架币种是国际站的子集，没上架的交易对在那条流上**永远收不到数据**，
 *   表现是该 source_key 长期陈旧、看起来像故障。现网 TRX 就这么卡了 3.9 天。
 *   (US 文档仅供对照: https://docs.binance.us/#websocket-streams)
 * 按官方映射拆为 /public（depth）和 /market（ticker、markPrice、kline、aggTrade）两大类。
 * 成交固定订阅 aggTrade（期货走 /market 上的独立连接），刻意不做成配置。
 * 不用 @trade：实测挂 /market 订阅成功却永远无推送，只有挂 /public 才有数据（文档未覆盖）。
 * kline 数据量大、更新频繁，与驱动定价的 ticker/markPrice 拆成独立连接（同为 /market 类目，仅物理连接分开），
 * 避免 K 线流量抖动/限速影响价格连接，也避免二者故障域相互牵连；现货侧同理拆分。
 */
class MarketBinance extends MarketCollectorAbstract
{

    protected function exchange(): MarketExchangeEnum
    {
        return MarketExchangeEnum::BINANCE;
    }

    protected function startCollectors(Output $output, int $type, array $symbols): void
    {
        $this->connectBinance($output, $type, $symbols);
    }

    /**
     * 把 aggTrade 帧 {a,p,q,f,l,T,m} 归一成 trade 帧的形状 {t,p,q,T,m}：下游 updateTrade() 只认这一种。
     * t 取聚合成交 id a；缺字段留 null，由 updateTrade() 按原有规则兜底
     */
    private static function aggTradeAsTrade(array $data): array
    {
        return [
            't' => $data['a'] ?? null,
            'p' => $data['p'] ?? null,
            'q' => $data['q'] ?? null,
            'T' => $data['T'] ?? null,
            'm' => $data['m'] ?? false,
        ];
    }

    /**
     * 建立币安 WebSocket 连接并处理消息
     */
    private function connectBinance(Output $output, int $type, array $symbols)
    {
        $periodMap = MarketRestBinance::PERIOD_MAP;
        $revPeriodMap = array_flip($periodMap);
        $wsUrls = (array)\think\facade\Config::get('market.endpoints.binance.ws', []);

        if ($type == MarketDataTypeEnum::SPOT->value) {
            $params = [];
            $klineParams = [];
            foreach ($symbols as $symbol) {
                $lowerSymbol = strtolower($symbol);
                $params[] = "{$lowerSymbol}@ticker";
                $params[] = "{$lowerSymbol}@depth20@100ms";
                $params[] = "{$lowerSymbol}@aggTrade";
                foreach ($periodMap as $binancePeriod) {
                    $klineParams[] = "{$lowerSymbol}@kline_{$binancePeriod}";
                }
            }
            // 用 Combined Stream 端点 (/stream 而非 /ws)：现货 Partial Book Depth Streams (<symbol>@depth<levels>)
            // 官方 payload 本身不带任何 symbol 字段 ({"lastUpdateId":..,"bids":..,"asks":..})，
            // 多 symbol 共用一条连接时无法从消息体反推归属，Combined Stream 会包一层 {"stream":"<name>","data":{...}}，
            // 靠 stream 名解出 symbol，其余频道(ticker/kline/trade)payload 本身就带 symbol，不受影响
            // priceEvents=['24hrTicker']：只有驱动 last_price 的这个子频道单独判断新鲜度，见 openBinanceWsConn 注释
            $this->openBinanceWsConn($output, $type, 'Spot', $wsUrls['spot'], $params, $revPeriodMap, ['24hrTicker']);
            // K线单独一条连接：数据量大、更新频繁，与驱动 last_price 的 ticker 拆开，避免互相牵连；
            // K线过期有独立的周期扫描 + REST 回补兜底（见 K线断档回补机制），这里不设 priceEvents，
            // 退化为原来的"任意消息即视为存活"判断
            $this->openBinanceWsConn($output, $type, 'Spot-Kline', $wsUrls['spot'], $klineParams, $revPeriodMap);
        } else {
            // 按币安新路由规则分配（旧版 /ws /stream 端点已于 2026-04-23 下线）：
            // depth => /public，ticker/markPrice/kline/aggTrade => /market。
            // 期货 /public：depth
            $publicParams = [];
            // 期货 /market：ticker、markPrice（驱动定价，独立一条连接）
            $marketParams = [];
            // 期货 /market：kline（数据量大更新频繁，拆成独立连接，避免跟 ticker/markPrice 互相牵连）
            $klineParams = [];
            // 期货 /market：aggTrade，同样不与驱动定价的连接合用
            $tradeParams = [];
            foreach ($symbols as $symbol) {
                $lowerSymbol = strtolower($symbol);
                $publicParams[] = "{$lowerSymbol}@depth20@500ms";
                $tradeParams[] = "{$lowerSymbol}@aggTrade";

                $marketParams[] = "{$lowerSymbol}@ticker";
                $marketParams[] = "{$lowerSymbol}@markPrice@1s"; // 官方标记价格，供强平判断使用

                foreach ($periodMap as $binancePeriod) {
                    $klineParams[] = "{$lowerSymbol}@kline_{$binancePeriod}";
                }
            }

            // Swap-Public 只有 depth，不驱动价格，priceEvents 留空，
            // 退化为原来的"任意消息即视为存活"判断；Swap-Market 的 ticker/markPrice 才是判断依据
            $this->openBinanceWsConn($output, $type, 'Swap-Public', $wsUrls['swap_public'], $publicParams, $revPeriodMap);
            $this->openBinanceWsConn($output, $type, 'Swap-Market', $wsUrls['swap_market'], $marketParams, $revPeriodMap, ['24hrTicker', 'markPriceUpdate']);
            // Swap-Kline 同属 /market 类目，只是拆成独立物理连接；K线过期有独立的周期扫描 + REST 回补兜底
            // （见 K线断档回补机制），这里不设 priceEvents，退化为"任意消息即视为存活"判断
            $this->openBinanceWsConn($output, $type, 'Swap-Kline', $wsUrls['swap_market'], $klineParams, $revPeriodMap);
            $this->openBinanceWsConn($output, $type, 'Swap-Trade', $wsUrls['swap_market'], $tradeParams, $revPeriodMap);
        }
    }

    /**
     * @param array $priceEvents 驱动定价的事件名白名单 (如 ['24hrTicker','markPriceUpdate'])。
     *   非空时，90s 无数据看门狗改盯这些事件专属的 $lastPriceMsgTime，而不是任意消息都会刷新的
     *   $lastMsgTime——同一条连接上 depth/trade/kline 等其它频道持续有数据会让 $lastMsgTime
     *   看起来"很新鲜"，掩盖掉真正驱动 last_price/mark_price 的那个子频道已经被交易所静默丢弃的情况
     *   (2026-08-07 实测复现：Spot 连接其它频道仍在推送，但 @ticker 已经停推，market_ts 冻结不动，
     *   看门狗却因为 $lastMsgTime 一直被刷新而从未触发重连)。留空 (如 Swap-Public，本身不带价格频道)
     *   则退化为原来的"任意消息即视为存活"判断。
     */
    private function openBinanceWsConn(Output $output, int $type, string $connName, string $wsUrl, array $params, array $revPeriodMap, array $priceEvents = [])
    {
        if (!\Workerman\Worker::$daemonize) {
            $output->writeln("<info>[Market]</info> Binance {$connName} connecting to {$wsUrl}");
        }

        $con = new AsyncTcpConnection($wsUrl);
        $con->transport = 'ssl';

        $lastMsgTime = time();
        $lastPriceMsgTime = time();

        \Workerman\Timer::add(30, \app\lib\TaskLiveness::wrap('ws_watchdog:' . $connName, function () use ($con, &$lastMsgTime, &$lastPriceMsgTime, $priceEvents, $output, $connName) {
            $this->pingDb();
            $checkTime = $priceEvents ? $lastPriceMsgTime : $lastMsgTime;
            if (time() - $checkTime > 90) {
                if (!\Workerman\Worker::$daemonize) {
                    $reason = $priceEvents ? 'No price data (' . implode('/', $priceEvents) . ')' : 'No data';
                    $output->writeln("<error>[Binance-{$connName}]</error> {$reason} for 90s, forcing reconnect...");
                }
                $con->close();
            }
        }));

        $con->onConnect = function ($con) use ($output, $params, $connName) {
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<info>[Market]</info> Binance {$connName} WebSocket Connected.");
            }
            $this->sendSubscribeThrottled($con, $params, $connName, $output);
        };

        // onMessage 整段计入事件循环占用（gzdecode + json_decode 是这条路径上最大的一块 CPU，
        // 此前完全没有被任何指标覆盖）。包在**赋值处**而不是回调体内部，
        // 省掉一层闭包和一份逐字重复的 use 列表，见 EventLoopBusyTrait::timedHandler()
        $con->onMessage = $this->timedHandler('msg:' . $connName, function ($con, $data_json) use ($output, $type, $connName, $revPeriodMap, $priceEvents, &$lastMsgTime, &$lastPriceMsgTime) {
            $lastMsgTime = time();
            $this->resetReconnectBackoff("binance:{$connName}"); // 真正收到数据才算连接可用，清零退避
            $data = json_decode($data_json, true, 512, JSON_BIGINT_AS_STRING);
            if (!$data) return;

            // Combined Stream 端点 (/stream) 会把每条推送包一层 {"stream":"<streamName>","data":{...}}，
            // 现货 Partial Book Depth Streams 的 data 里没有任何 symbol 字段，靠 stream 名兜底解出 symbol
            $streamSymbol = '';
            if (isset($data['stream'], $data['data'])) {
                $streamSymbol = strtolower(strstr($data['stream'], '@', true) ?: '');
                $data = $data['data'];
            }

            $event = $data['e'] ?? null;

            if ($priceEvents && in_array($event, $priceEvents, true)) {
                $lastPriceMsgTime = time();
            }

            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<info>[Binance-{$connName}]</info> onMessage {$data_json}");
            }

            if ($event === '24hrTicker') {
                $symbol = strtolower($data['s']);
                $tick = [
                    'open'   => $data['o'],
                    'close'  => $data['c'],
                    'low'    => $data['l'],
                    'high'   => $data['h'],
                    'amount' => $data['q'],
                    'vol'    => $data['v'],
                    'count'  => $data['n'],
                ];
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<info>[Binance-{$connName}]</info> {$symbol} Price: <fg=yellow>{$tick['close']}</>");
                }
                $this->handlePrice(MarketExchangeEnum::BINANCE, $symbol, $type, $tick, $data['E']);
            } elseif ($event === 'markPriceUpdate') {
                $symbol = strtolower($data['s']);
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<info>[Binance-{$connName}]</info> {$symbol} MarkPrice: <fg=magenta>{$data['p']}</>");
                }
                $this->handleMarkPrice(MarketExchangeEnum::BINANCE, $symbol, $type, (string)$data['p'], (int)($data['E'] ?? (microtime(true) * 1000)));

                // 帧里的资金费率 r / T 刻意不接：费率只走 REST，见 MarketFunding::tickFromRest()
            } elseif ($event === 'kline') {
                $symbol = strtolower($data['s']);
                $k = $data['k'];
                $stdPeriod = $revPeriodMap[$k['i']] ?? $k['i'];
                $tick = [
                    'id'     => floor($k['t'] / 1000),
                    'open'   => $k['o'],
                    'close'  => $k['c'],
                    'low'    => $k['l'],
                    'high'   => $k['h'],
                    'amount' => $k['q'],
                    'vol'    => $k['v'],
                ];
                if (!\Workerman\Worker::$daemonize) {
                    $kTime = date('Y-m-d H:i:s', (int)($k['t'] / 1000));
                    $output->writeln("<info>[Binance-{$connName}]</info> {$symbol} KLine({$stdPeriod}s) Update - <fg=cyan>{$kTime}</>");
                }
                $this->handleKline(MarketExchangeEnum::BINANCE, $type, $symbol, (string)$stdPeriod, $tick);
            } elseif ($event === 'aggTrade') {
                $symbol = strtolower($data['s']);
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<info>[Binance-{$connName}]</info> {$symbol} Trade Update");
                }
                $this->handleTrade(MarketExchangeEnum::BINANCE, $type, $symbol, self::aggTradeAsTrade($data));
            } elseif ($event === null && isset($data['bids'], $data['asks'])) {
                // 现货 Partial Book Depth Streams：不带 'e'/'s' 字段，字段名 bids/asks，symbol 靠 Combined Stream 的 stream 名解出
                $symbol = strtolower($data['s'] ?? '') ?: $streamSymbol;
                if ($symbol) {
                    if (!\Workerman\Worker::$daemonize) {
                        $output->writeln("<info>[Binance-{$connName}]</info> {$symbol} Depth Update");
                    }
                    $this->handleDepth(MarketExchangeEnum::BINANCE, $type, $symbol, $data);
                }
            } elseif ($event === 'depthUpdate' && isset($data['b'], $data['a'])) {
                // 合约 Partial Book Depth Streams：带 'e' 字段，字段名 b/a（与现货不同，此前一直未被任何分支匹配，深度数据从未采集）
                $symbol = strtolower($data['s'] ?? '');
                if ($symbol) {
                    if (!\Workerman\Worker::$daemonize) {
                        $output->writeln("<info>[Binance-{$connName}]</info> {$symbol} Depth Update");
                    }
                    $this->handleDepth(MarketExchangeEnum::BINANCE, $type, $symbol, [
                        'bids' => $data['b'],
                        'asks' => $data['a'],
                        'ts'   => $data['T'] ?? $data['E'] ?? (int)(microtime(true) * 1000),
                    ]);
                }
            }
        });

        $con->onError = function ($con, $code, $msg) use ($output, $connName) {
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<error>[Binance-{$connName}] WebSocket Error: {$msg} (code:{$code})</error>");
            }
        };

        $con->onClose = function ($con) use ($output, $connName) {
            // 连接没了，没发完的订阅分片必须停掉：否则重连后旧定时器还在往新连接外的旧句柄写，
            // 而新的 onConnect 又会起一份，重连几次就叠出好几倍的订阅消息，正好撞限流
            $this->cancelSubscribeTimer($connName);

            // 指数退避，理由同 MarketHuobi：固定间隔重连在被限流时会一直硬撞、永远恢复不了。
            // Binance 各物理连接 (Spot/Swap-Market/Swap-Kline/...) 按 connName 独立计数
            $delay = $this->nextReconnectDelay("binance:{$connName}");
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<comment>[Binance-{$connName}]</comment> WebSocket Closed. Reconnecting in {$delay}s...");
            }
            $con->reConnect($delay);
        };

        $con->connect();
    }

    /** 每条 SUBSCRIBE 最多带几个 stream。Binance 单条连接的 stream 上限是 1024，50 只是保守分片 */
    private const SUB_CHUNK_SIZE = 50;

    /**
     * 分片之间的间隔（秒）。
     *
     * 2026-08-31 实测（直接对交易所压测，不是照文档推的）：
     *   现货 stream.binance.com/ws  —— 限 **5 条/秒**，第 6 条立刻收到
     *                                 close 帧 "Too many requests" 断开
     *   合约 fstream.binance.com    —— 宽松得多，约 15 条突发才断
     * 且是**速率**限制不是总量：12 条 @2.8/s 全部成功，12 条 @6.5/s 在第 6 条被断。
     *
     * 取 0.35s ≈ 2.8 条/秒，实测通过，对 5/s 的线留了近一倍余量（给 PING/PONG 也计数的可能性兜底）。
     */
    private const SUB_CHUNK_INTERVAL = 0.35;

    /** @var array<string,int> connName => Timer id，见 sendSubscribeThrottled() */
    private array $subTimers = [];

    /**
     * @var array<string,int> connName => 连接代数。每次 onConnect 自增，
     * 在途的分片定时器靠它判断"我发的是不是还是当前那条连接"——不用 Workerman 的连接状态常量，
     * 免得框架版本一变就在定时器回调里抛 Error（daemon 下 stdout 丢弃，表现为订阅莫名其妙不全）
     */
    private array $subGeneration = [];

    /**
     * 节流下发订阅。
     *
     * ⚠️ 不能像原来那样在 onConnect 里 foreach 连发。真正会踩线的是**现货的 K 线连接**：
     * 43 个 symbol × 6 个周期 = 258 条流，按 50 个分片就是 6 条 SUBSCRIBE，
     * 而现货端只允许 5 条/秒 —— 第 6 条到达即被断开，然后重连、再发 6 条、再断，稳定振荡。
     * 扩容前只有 18 个 symbol（108 流 → 3 分片），一直在线内，所以这条限制此前从未被踩到；
     * 不是限制变了，是我们变大了。
     *
     * 合约侧 Swap-Kline 是 8 分片，但 fapi 的线在 15 左右，本来就安全 —— 一并走节流只是统一路径。
     *
     * 分片本身不能省（stream 太多时单条消息会过长），能省的只有"同时发"。
     * 最慢的一条连接 8 分片 × 0.35s = 2.8 秒发完，对启动无感。
     */
    private function sendSubscribeThrottled($con, array $params, string $connName, Output $output): void
    {
        $this->cancelSubscribeTimer($connName);
        $gen = ($this->subGeneration[$connName] = ($this->subGeneration[$connName] ?? 0) + 1);

        $chunks = array_chunk($params, self::SUB_CHUNK_SIZE);
        $total = count($chunks);
        if ($total === 0) return;

        $i = 0;
        $send = function () use ($con, $chunks, $connName, $output, $total, $gen, &$i) {
            // 连接在发送过程中断过（onClose → 重连 → 新的 onConnect）就收手，
            // 剩下的分片由新那一轮从头发，避免往旧句柄写、也避免两轮叠加撞限流
            if (($this->subGeneration[$connName] ?? 0) !== $gen) {
                return;
            }

            $con->send(json_encode([
                'method' => 'SUBSCRIBE',
                'params' => $chunks[$i],
                'id'     => $i + 1,
            ]));
            $i++;

            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<comment>[Sub]</comment> Binance {$connName} chunk {$i}/{$total} ("
                    . count($chunks[$i - 1]) . ' streams) sent');
            }

            if ($i >= $total) {
                $this->cancelSubscribeTimer($connName);
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<comment>[Sub]</comment> Binance {$connName} Subscribed "
                        . array_sum(array_map('count', $chunks)) . ' channels');
                }
            }
        };

        // 第一片立刻发，其余交给定时器：只有一片时（大多数小连接）行为与改造前完全一致
        $send();
        if ($i < $total) {
            // @no-task-report: 一次性定时器，发完最后一片就自删（见 $send 里的 cancelSubscribeTimer）。
            // 它"停了"不需要被发现——真停了表现为订阅不全、90 秒无数据，已有 ws_watchdog 兜底重连
            $this->subTimers[$connName] = \Workerman\Timer::add(self::SUB_CHUNK_INTERVAL, $send);
        }
    }

    private function cancelSubscribeTimer(string $connName): void
    {
        if (isset($this->subTimers[$connName])) {
            \Workerman\Timer::del($this->subTimers[$connName]);
            unset($this->subTimers[$connName]);
        }
    }
}
