<?php

declare(strict_types=1);

namespace app\business\controller;

use app\command\MarketDispatcher;
use app\enum\CommonStatusEnum;
use app\enum\MarketDataTypeEnum;
use app\lib\MarketPublisher;
use app\lib\MarketWireProtocol;
use think\facade\Config;
use think\facade\Db;

/**
 * 分发对接说明
 *
 * 给订阅方（下游业务服务器）的对接页：连哪个地址、hello 怎么写、帧长什么样、心跳与降级规则，
 * 外加一个按本机真实交易对生成 hello 的工具。
 *
 * 端口、心跳、降级、上限这些数字全部从代码常量与配置读，不在模板里写死 ——
 * 页面与分发机实际行为一旦对不上，订阅方会照着错的说明去排查。
 * HTTP 接口字段见 app/inner_api/controller/Pair.php 的注释。
 */
class DispatchGuide extends Base
{
    /** stream 名 => [快照频道, 说明]。K 线另行生成；名字全集以 MarketWireProtocol::streamNames() 为准 */
    private const STREAM_DESC = [
        'ticker'    => ['market:raw:price', '最新价与 24h 统计：tick{last_price, open, high, low, close, amount, vol, change}'],
        'markPrice' => ['market:raw:mark_price', '标记价格：mark_price。只有合约端口'],
        'depth'     => ['market:raw:depth', '盘口：depth{bids, asks, ts}，各 20 档。可带推送间隔，会被降级'],
        'trade'     => ['market:raw:trade', '成交：data[{id, price, quantity, direction, ts}]，最近 N 笔、新到旧，整窗覆盖，按 id 去重'],
    ];

    /** 系统帧（文本帧，_sys 开头）=> 说明 */
    private const SYS_FRAMES = [
        'ping'     => ['分发机 → 订阅方', '心跳。原样带回 ts 回一条 {"_sys":"pong","ts":<同一个 ts>}'],
        'pong'     => ['订阅方 → 分发机', '回心跳。必须紧凑书写、以 {"_sys": 开头，否则会被当成 hello'],
        'error'    => ['分发机 → 订阅方', 'hello 不合法，msg 是原因。连接不断，已生效的订阅保持不变'],
        'throttle' => ['分发机 → 订阅方', 'depth 被降级 / 恢复。depth_ms = 当前下限，0 = 已恢复到订阅的间隔'],
    ];

    /** HTTP 接口 => 用途。字段见 app/inner_api/controller/Pair.php 的注释 */
    private const HTTP_APIS = [
        '/inner_api/pair/pairs'        => 'pair 元数据全集（含禁用的），启动时核对本机交易对、判断开休市',
        '/inner_api/pair/prices'       => '全部 pair 当前价格与标记价格，WS 静默时自救（落后一个落库周期）',
        '/inner_api/pair/fundingRates' => '合约资金费率，费率的唯一通道（不走 WS）',
        '/inner_api/pair/depth'        => '单个 pair 当前盘口，?pair=btcusdt_2',
        '/inner_api/pair/klines'       => '单个 pair 的 K 线历史，断档回补，?pair=btcusdt_2&period=60&since_ts=..&limit=500',
    ];

    /**
     * 分发对接说明
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '分发对接说明';

        // 后台域名不一定就是分发机 IP（经 CDN / 反代时），页面上提示订阅方以实际 IP 为准
        $host = (string)$this->request->host(true) ?: '<分发机IP>';
        $types = MarketDataTypeEnum::labels();

        $this->endpoints = [];
        $this->streamRows = [];
        $this->pairs = [];
        $enabledPairs = Db::name('exchange_pair')->where('status', CommonStatusEnum::ON->value)
            ->order('type asc, sort desc, id asc')->field('symbol, symbol_code, type')->select()->toArray();

        foreach ($types as $type => $label) {
            $type = (int)$type;
            $port = MarketDispatcher::portOf($type);
            $names = MarketWireProtocol::streamNames($type);
            $symbols = [];
            foreach ($enabledPairs as $p) {
                if ((int)$p['type'] === $type) $symbols[] = ['code' => strtolower((string)$p['symbol_code']), 'name' => (string)$p['symbol']];
            }
            $this->endpoints[] = [
                'type' => $type, 'label' => $label, 'port' => $port,
                'url' => "ws://{$host}:{$port}", 'pair_count' => count($symbols),
            ];
            $this->pairs[$type] = ['label' => $label, 'port' => $port, 'url' => "ws://{$host}:{$port}",
                                   'symbols' => $symbols, 'streams' => $names];
        }

        // stream 字典按合约的全集列（现货少一个 markPrice，说明里已注明）
        foreach (MarketWireProtocol::streamNames(MarketDataTypeEnum::SWAP->value) as $name) {
            if (str_starts_with($name, 'kline_')) continue;
            [$channel, $desc] = self::STREAM_DESC[$name] ?? ['-', ''];
            $this->streamRows[] = ['stream' => '{symbol}@' . $name, 'channel' => $channel, 'desc' => $desc];
        }
        $this->streamRows[] = [
            'stream'  => '{symbol}@kline_{周期}',
            'channel' => 'market:raw:kline',
            'desc'    => 'K 线：period、kline{ts, open, high, low, close, vol, amount}，当前这一根的最新状态',
        ];

        // 收数示例连合约端口：端口取配置里的实际值，主机仍写 <分发机IP>（理由见上面 $host）
        $this->exampleSwapPort = MarketDispatcher::portOf(MarketDataTypeEnum::SWAP->value);

        $this->klineIntervals = MarketWireProtocol::KLINE_INTERVALS;
        $this->depthIntervals = MarketWireProtocol::DEPTH_INTERVALS;
        $this->sysFrames = self::SYS_FRAMES;
        $this->httpApis = self::HTTP_APIS;

        $allowIps = array_values(array_filter(array_map('trim', explode(',', (string)Config::get('market.inner_api.allow_ips', '')))));
        $this->allowIpsText = $allowIps ? implode('、', $allowIps) : '未限制（任何能连上端口的 IP 都可以订阅）';

        $tickMs = (int)Config::get('market.dispatch.tick_ms', 100);
        $floors = array_values(array_filter(MarketDispatcher::DEGRADE_FLOORS));
        $maxSendBuffer = (int)Config::get('market.dispatch.max_send_buffer', 4 * 1024 * 1024);
        $this->rules = [
            ['心跳', sprintf('分发机每 %d 秒发一次 ping；超过 %d 秒没收到 pong 就断开（巡检有间隔，实际 %d~%d 秒）',
                MarketPublisher::HEARTBEAT_INTERVAL, MarketPublisher::HEARTBEAT_TIMEOUT,
                MarketPublisher::HEARTBEAT_TIMEOUT, MarketPublisher::HEARTBEAT_TIMEOUT + MarketPublisher::HEARTBEAT_INTERVAL)],
            ['分发节拍', sprintf('每 %dms 取一次变化，ticker / markPrice / trade / kline 有变化就推，没有固定频率', $tickMs)],
            ['depth 间隔', sprintf('可选 %s ms，默认 %dms；比节拍快的档位兑现不了',
                implode(' / ', MarketWireProtocol::DEPTH_INTERVALS), MarketWireProtocol::DEPTH_INTERVALS[0])],
            ['降级', sprintf('发送缓冲超过 %s 且还在涨、或涨满 %s 时，这条连接的 depth 降一档：%s ms；连续 %d 秒没再降、缓冲回落到 %s 以下升回一档。只降 depth，其他 stream 不受影响',
                $this->bytesText((int)($maxSendBuffer * MarketDispatcher::SOFT_DEGRADE_RATIO)), $this->bytesText($maxSendBuffer),
                implode(' → ', $floors), MarketDispatcher::DEGRADE_RECOVER_SEC,
                $this->bytesText((int)($maxSendBuffer * MarketDispatcher::SOFT_DEGRADE_RATIO)))],
            ['积压断开', sprintf('发送缓冲持续满 %d 秒就断开。重连后会补一次快照，覆盖语义下不丢最终状态',
                max(1, (int)Config::get('market.dispatch.lag_close_sec', 5)))],
            ['hello 上限', sprintf('每条连接最多 %d 次 hello（含不合法的），超过后再发不处理；streams 最多 %d 个，hello 最大 %d KB',
                MarketDispatcher::MAX_HELLO_PER_CONN, MarketWireProtocol::MAX_STREAMS, intdiv(MarketWireProtocol::MAX_HELLO_BYTES, 1024))],
        ];

        return $this->fetch();
    }

    private function bytesText(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
