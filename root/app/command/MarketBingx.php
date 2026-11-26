<?php

declare(strict_types=1);

namespace app\command;

use think\console\Output;
use app\enum\MarketExchangeEnum;
use app\enum\MarketDataTypeEnum;
use app\lib\MarketRestBingx;
use Workerman\Connection\AsyncTcpConnection;

/**
 * BingX 行情直连采集服务：只采 USDT 永续，用于外汇与燃料油（NC 前缀 TradFi 合约，如 NCFXEUR2USD-USDT）
 *
 * 命令  php think market:bingx
 * 触发  deploy/restart_market.sh · win_deploy/restart_market.bat
 * 单例  pid 文件 runtime/market-relay_market_bingx_2.pid（见 SingletonGuardTrait）
 * 依赖  配置 market.endpoints.bingx · 表 market_data（exchange=bingx, type=2）
 *
 * 用法:
 *   php think market:bingx start -t 2 -d     # 后台常驻（BingX 只有合约，没有 -t 1）
 *   php think market:bingx status -t 2
 *   php think market:bingx stop -t 2
 *
 * 协议为 2026-10-20 对 open-api-swap.bingx.com 实测所得（官方文档是前端渲染的单页，抓不到正文）：
 *   · 地址 wss://open-api-swap.bingx.com/swap-market，所有下行帧 gzip 压缩
 *   · 订阅 {"id":"..","reqType":"sub","dataType":"<symbol>@<stream>"}，回 {"id":..,"code":0}
 *   · 心跳：服务端约每 5 秒发一帧 Ping（gzip 的纯文本），客户端回纯文本 Pong
 *   · @ticker      data{e:24hTicker, E, s, c, o, h, l, v(量), q(额)}
 *   · @markPrice   data{e:markPriceUpdate, E, s, p}
 *   · @trade       data[{t(成交id), p, q, T, m(买方是挂单方), s}]，一帧可能多笔
 *   · @depth20@500ms  data{bids, asks}，symbol 只在 dataType 里，ts 在顶层
 *   · @kline_<interval>  data[{o, h, l, c, v, T(开盘毫秒)}]，s 在顶层；**没有成交额字段**
 * 实测外汇合约在周末不出 K 线（跟随真实外汇作息），REST 补数在周末回空属正常。
 */
class MarketBingx extends MarketCollectorAbstract
{
    /** 默认 WS 地址：ws:// + 443 端口，transport 置 ssl（与 Binance 采集器同一写法） */
    private const DEFAULT_WS = 'ws://open-api-swap.bingx.com:443/swap-market';

    /** 订阅逐条发送的间隔（秒）。BingX 每条订阅只带一个 dataType，限流未见文档，按 10 条/秒保守发 */
    private const SUB_INTERVAL = 0.1;


    /** @var array<string,int> connName => 订阅定时器 id */
    private array $subTimers = [];

    /** @var array<string,int> connName => 连接代数，见 MarketBinance::$subGeneration */
    private array $subGeneration = [];

    protected function exchange(): MarketExchangeEnum
    {
        return MarketExchangeEnum::BINGX;
    }

    protected function startCollectors(Output $output, int $type, array $symbols): void
    {
        // BingX 只采永续；-t 1 时 market_data 里不会有它的现货行，这里再兜一层，免得空跑一条连接
        if ($type !== MarketDataTypeEnum::SWAP->value || empty($symbols)) {
            $output->writeln('<comment>[BingX]</comment> 只采集永续合约（-t 2），本次没有要采的品种');
            return;
        }

        $url = (string)\think\facade\Config::get('market.endpoints.bingx.ws', self::DEFAULT_WS);

        $marketParams = [];
        $klineParams  = [];
        foreach ($symbols as $symbol) {
            $sym = strtoupper($symbol);
            $marketParams[] = "{$sym}@ticker";
            $marketParams[] = "{$sym}@markPrice";
            $marketParams[] = "{$sym}@depth20@500ms";
            $marketParams[] = "{$sym}@trade";
            foreach (MarketRestBingx::PERIOD_MAP as $interval) {
                $klineParams[] = "{$sym}@kline_{$interval}";
            }
        }

        // 驱动价格的频道与 K 线拆成两条物理连接，理由同 MarketBinance：K 线量大、更新频繁，
        // 不让它的抖动或被断连牵连最新价 / 标记价
        $this->openConn($output, $type, 'Swap-Market', $url, $marketParams, ['24hTicker', 'markPriceUpdate']);
        $this->openConn($output, $type, 'Swap-Kline', $url, $klineParams);
    }

    /**
     * @param array $priceEvents 驱动定价的事件名。非空时看门狗只认这些事件刷新的时间，
     *   理由见 MarketBinance::openBinanceWsConn() 的同名参数
     */
    private function openConn(Output $output, int $type, string $connName, string $url, array $params, array $priceEvents = []): void
    {
        if (!\Workerman\Worker::$daemonize) {
            $output->writeln("<info>[Market]</info> BingX {$connName} connecting to {$url}");
        }

        $con = new AsyncTcpConnection($url);
        $con->transport = 'ssl';

        $logName = "BingX-{$connName}";
        // 三个时间：$lastMsgTime 任何下行帧都刷（含服务端约每 5 秒一帧的 Ping），只用来在日志里区分「连接还活着」；
        // $lastDataTime 收到任意行情数据帧时刷；$lastPriceMsgTime 只在价格类事件时刷。
        // 看门狗：传了 $priceEvents 的连接看价格帧，否则（K 线连接）看数据帧 —— 不能看 $lastMsgTime，
        // 订阅丢了 Ping 照发，看门狗会一直以为正常，见 MarketCollectorAbstract::watchdogCheck()
        $lastMsgTime = time();
        $lastDataTime = time();
        $lastPriceMsgTime = time();
        $subErrors = 0;

        \Workerman\Timer::add(30, \app\lib\TaskLiveness::wrap('ws_watchdog:bingx:' . $connName, function () use ($con, &$lastMsgTime, &$lastDataTime, &$lastPriceMsgTime, $priceEvents, $output, $logName) {
            $this->pingDb();
            $this->watchdogCheck($con, $logName, $priceEvents ? $lastPriceMsgTime : $lastDataTime, $lastMsgTime, $output);
        }));

        $con->onConnect = function ($con) use ($output, $params, $connName, $logName, &$lastDataTime, &$lastPriceMsgTime, &$subErrors) {
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<info>[Market]</info> BingX {$connName} WebSocket Connected.");
            }
            // 刚连上还没有数据帧，从现在起给足静默时长；订阅全被拒的话到时看门狗会再重连一次
            $lastDataTime = time();
            $lastPriceMsgTime = time();
            $subErrors = 0;
            business_log('market_collect_stat', sprintf('[%s] 已连接，发出订阅 %d 条', $logName, count($params)));
            $this->sendSubscribeThrottled($con, $params, $connName, $output);
        };

        $con->onMessage = $this->timedHandler('msg:bingx:' . $connName, function ($con, $raw) use ($output, $type, $connName, $logName, $priceEvents, &$lastMsgTime, &$lastDataTime, &$lastPriceMsgTime, &$subErrors) {
            $lastMsgTime = time();

            // 下行帧一律 gzip；解不开就按明文兜底（不认识的帧下面会被忽略）
            $text = @gzdecode($raw);
            if ($text === false) $text = $raw;

            // 心跳：不回 Pong 会被服务端断开
            if ($text === 'Ping') {
                $con->send('Pong');
                return;
            }

            $msg = json_decode($text, true, 512, JSON_BIGINT_AS_STRING);
            if (!is_array($msg)) return;

            $dataType = (string)($msg['dataType'] ?? '');
            // 订阅应答 {"id":..,"code":0,"dataType":""}：只在失败时记录
            if ($dataType === '') {
                if ((int)($msg['code'] ?? 0) !== 0) {
                    $this->logSubError($logName, $text, $subErrors);
                    if (!\Workerman\Worker::$daemonize) {
                        $output->writeln("<error>[BingX-{$connName}]</error> subscribe failed: {$text}");
                    }
                }
                return;
            }

            $data = $msg['data'] ?? null;
            if ($data === null) return;
            $lastDataTime = time();
            // 真正收到行情数据才算连接可用、清零退避；订阅应答不算，否则订阅一直失败时退避永远长不起来
            $this->resetReconnectBackoff("bingx:{$connName}");
            [$symbol, $stream] = array_pad(explode('@', $dataType, 2), 2, '');

            $event = is_array($data) ? ($data['e'] ?? null) : null;
            if ($priceEvents && in_array($event, $priceEvents, true)) {
                $lastPriceMsgTime = time();
            }

            if ($stream === 'ticker') {
                $tick = [
                    'open'   => $data['o'] ?? 0,
                    'close'  => $data['c'] ?? 0,
                    'low'    => $data['l'] ?? 0,
                    'high'   => $data['h'] ?? 0,
                    'amount' => $data['q'] ?? 0,
                    'vol'    => $data['v'] ?? 0,
                    'count'  => 0, // BingX ticker 不给成交笔数
                ];
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<info>[BingX-{$connName}]</info> {$symbol} Price: <fg=yellow>{$tick['close']}</>");
                }
                $this->handlePrice(MarketExchangeEnum::BINGX, $symbol, $type, $tick, (int)($data['E'] ?? (microtime(true) * 1000)));
            } elseif ($stream === 'markPrice') {
                if (!isset($data['p'])) return;
                $this->handleMarkPrice(MarketExchangeEnum::BINGX, $symbol, $type, (string)$data['p'], (int)($data['E'] ?? (microtime(true) * 1000)));
            } elseif (str_starts_with($stream, 'depth')) {
                $this->handleDepth(MarketExchangeEnum::BINGX, $type, $symbol, [
                    'bids' => $data['bids'] ?? [],
                    'asks' => $data['asks'] ?? [],
                    'ts'   => (int)($msg['ts'] ?? (microtime(true) * 1000)),
                ]);
            } elseif ($stream === 'trade') {
                // 在采集端就归一成 {id, price, quantity, direction, ts}，MarketService::updateTrade 原样接收
                // m = 买方是挂单方，即主动卖出
                $trades = [];
                foreach ((array)$data as $t) {
                    if (!is_array($t) || !isset($t['p'])) continue;
                    $trades[] = [
                        'id'        => (string)($t['t'] ?? ''),
                        'price'     => (string)$t['p'],
                        'quantity'  => (string)($t['q'] ?? '0'),
                        'direction' => !empty($t['m']) ? 'sell' : 'buy',
                        'ts'        => (int)($t['T'] ?? (microtime(true) * 1000)),
                    ];
                }
                if ($trades) $this->handleTrade(MarketExchangeEnum::BINGX, $type, $symbol, $trades);
            } elseif (str_starts_with($stream, 'kline_')) {
                $period = array_search(substr($stream, 6), MarketRestBingx::PERIOD_MAP, true);
                if ($period === false) return;
                foreach ((array)$data as $k) {
                    if (!is_array($k) || !isset($k['T'])) continue;
                    $close = \app\lib\MarketHelper::safeNumStr($k['c'] ?? 0);
                    $vol   = \app\lib\MarketHelper::safeNumStr($k['v'] ?? 0);
                    $this->handleKline(MarketExchangeEnum::BINGX, $type, $symbol, (string)$period, [
                        'id'     => intdiv((int)$k['T'], 1000),
                        'open'   => $k['o'] ?? 0,
                        'close'  => $k['c'] ?? 0,
                        'low'    => $k['l'] ?? 0,
                        'high'   => $k['h'] ?? 0,
                        'vol'    => $k['v'] ?? 0,
                        // K 线帧没有成交额，按 成交量 × 收盘价 近似，与 MarketRestBingx::fetchRestKlines 一致
                        'amount' => bcmul($vol, $close, 10),
                    ]);
                }
            }
        });

        $con->onError = function ($con, $code, $msg) use ($output, $connName) {
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<error>[BingX-{$connName}] WebSocket Error: {$msg} (code:{$code})</error>");
            }
        };

        $con->onClose = function ($con) use ($output, $connName) {
            $this->cancelSubscribeTimer($connName);
            $delay = $this->nextReconnectDelay("bingx:{$connName}");
            if (!\Workerman\Worker::$daemonize) {
                $output->writeln("<comment>[BingX-{$connName}]</comment> WebSocket Closed. Reconnecting in {$delay}s...");
            }
            $con->reConnect($delay);
        };

        $con->connect();
    }

    /**
     * 逐条节流发送订阅。BingX 一条订阅只带一个 dataType（不像 Binance 能一次带一批），
     * 10 个品种的 K 线连接就是 60 条，连发容易撞未公开的限流；断线重连时用连接代数作废在途的旧定时器，
     * 做法同 MarketBinance::sendSubscribeThrottled()
     */
    private function sendSubscribeThrottled($con, array $params, string $connName, Output $output): void
    {
        $this->cancelSubscribeTimer($connName);
        $gen = ($this->subGeneration[$connName] = ($this->subGeneration[$connName] ?? 0) + 1);

        $total = count($params);
        if ($total === 0) return;

        $i = 0;
        $send = function () use ($con, $params, $connName, $output, $total, $gen, &$i) {
            if (($this->subGeneration[$connName] ?? 0) !== $gen) return;

            $con->send(json_encode(['id' => "{$connName}-{$i}", 'reqType' => 'sub', 'dataType' => $params[$i]]));
            $i++;

            if ($i >= $total) {
                $this->cancelSubscribeTimer($connName);
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<comment>[Sub]</comment> BingX {$connName} Subscribed {$total} channels");
                }
            }
        };

        $send();
        if ($i < $total) {
            // @no-task-report: 一次性定时器，发完最后一条就自删；真停了表现为订阅不全、90 秒无数据，由 ws_watchdog 兜底重连
            $this->subTimers[$connName] = \Workerman\Timer::add(self::SUB_INTERVAL, $send);
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
