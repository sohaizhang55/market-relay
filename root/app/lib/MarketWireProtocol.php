<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketDataTypeEnum;

/**
 * 分发机 <-> 订阅方之间的线上协议编解码（两端共用同一份，避免各写一半对不上）
 *
 * 线上只有一种分发与接收方式，没有版本号、没有可选形态、不做协商：
 *   一条 WebSocket 二进制消息 = 一帧 JSON 经 gzdeflate（raw deflate）
 *
 * 帧就是 MarketPublisher 写进快照的那条 pair 帧，分发时在最前面插入它所属的 stream：
 *   {"stream":"btcusdt@depth","pair":"btcusdt_2","symbol":"btcusdt","type":2, ...数据..., "_channel":"market:raw:depth"}
 * 订阅方只认 pair（{symbol_code}_{type}），帧里没有交易所与 source_key。
 *
 * ── 订阅：hello 只有 cmd / node / streams ───────────────────────────────────
 *   {"cmd":"hello","node":"svr-b","streams":["btcusdt@ticker","btcusdt@depth@500ms","ethusdt@kline_1m"]}
 * 端口已经决定了现货(8594)还是合约(8595)，所以 stream 里只写裸 symbol，不带类型。
 * 不传 streams = 订阅本端口全部 stream。stream 的写法见 parseStream()。
 *
 * ── stream 与快照 field 一一对应 ─────────────────────────────────────────────
 *   btcusdt@ticker     <-> market:raw:price|btcusdt_{type}
 *   btcusdt@markPrice  <-> market:raw:mark_price|btcusdt_{type}（只有合约）
 *   btcusdt@depth      <-> market:raw:depth|btcusdt_{type}
 *   btcusdt@trade      <-> market:raw:trade|btcusdt_{type}
 *   btcusdt@kline_1m   <-> market:raw:kline|btcusdt_{type}|60
 * 分发机按 stream 建房间：一帧只压缩一次，原样发给房间里的每条连接（MarketDispatcher::deliver()）。
 *
 * ── 为什么不用协议级 permessage-deflate ────────────────────────────────────
 * Workerman 服务端支持，但它的客户端 Protocols/Ws.php 没有 inflate 实现。两端都在自己手里，
 * 走应用级压缩 + 二进制帧不依赖任何协议协商。无状态 deflate 让每条消息独立可解，重连不需要同步窗口。
 */
final class MarketWireProtocol
{
    public const CMD_HELLO = 'hello';

    /** 节点标识最大长度。够放 hostname，又不至于让日志一行被它撑爆 */
    private const MAX_NODE_LEN = 40;

    /**
     * hello 的上限。分发机监听端口对任何能连上的对端开放（allow_ips 默认为空），
     * 所以这里必须当成不可信输入处理：越界一律拒绝整条 hello 而不是截断 ——
     * 截断会让订阅方"以为订到了、其实缺一部分"且无从察觉；拒绝则这条连接一帧都收不到，
     * 分发机回一条 {"_sys":"error"} 并记 subscriber_hello_invalid 事件
     */
    public const MAX_HELLO_BYTES = 65536;

    /** streams 最多几个。现网约百级 pair × 10 种 stream，留几倍余量 */
    public const MAX_STREAMS = 4096;

    /** hello 允许出现的键。白名单：写了 topics / channels / subs 这类旧字段的 hello 整条拒绝，不悄悄忽略 */
    private const HELLO_KEYS = ['cmd', 'node', 'streams'];

    /** 频道 => stream 名（K 线单独处理，名字里带周期） */
    private const CHANNEL_STREAMS = [
        'market:raw:price'      => 'ticker',
        'market:raw:mark_price' => 'markPrice',
        'market:raw:depth'      => 'depth',
        'market:raw:trade'      => 'trade',
    ];

    private const KLINE_CHANNEL = 'market:raw:kline';

    /** K 线周期(秒) => stream 名里的周期写法。周期全集以 MarketHelper::KLINE_PERIODS 为准 */
    public const KLINE_INTERVALS = [
        60    => '1m',
        300   => '5m',
        900   => '15m',
        3600  => '1h',
        14400 => '4h',
        86400 => '1d',
    ];

    /**
     * depth 可选的推送间隔（毫秒），从快到慢。第一个是默认档。
     * 分发机降级另有更慢的档位，只降级用、不能点名（MarketDispatcher::DEGRADE_FLOORS）
     */
    public const DEPTH_INTERVALS = [100, 500, 1000];

    /** 帧前面插入 stream 名，再压缩。帧本身已是合法 JSON，直接拼字符串：这是分发机每帧的热路径 */
    public static function encodeFrame(string $stream, string $frameJson): string
    {
        $head = '{"stream":"' . $stream . '"';
        $json = strlen($frameJson) > 2 ? $head . ',' . substr($frameJson, 1) : $head . '}';
        return self::deflate($json);
    }

    /** level 1：单帧几百字节到几 KB，约几十微秒 */
    public static function deflate(string $json): string
    {
        return gzdeflate($json, 1);
    }

    /**
     * 解压一条线上消息，null = 不是合法的 deflate 流。
     * 单独暴露是为了让观测工具能看到 json_decode 之前的原文。
     */
    public static function inflate(string $raw): ?string
    {
        if ($raw === '') return null;
        $inflated = @gzinflate($raw);
        return $inflated === false ? null : $inflated;
    }

    /**
     * 解开一条线上消息，返回其中的那一帧。解压失败、不是 JSON 对象都返回 null，由调用方按坏包计。
     *
     * @return array<string,mixed>|null
     */
    public static function decodeMessage(string $raw): ?array
    {
        $json = self::inflate($raw);
        if ($json === null) return null;

        $frame = json_decode($json, true);
        return (is_array($frame) && $frame !== [] && !array_is_list($frame)) ? $frame : null;
    }

    /**
     * 快照 field -> stream 名，认不出返回 null（不是线上频道、或 K 线周期不在 KLINE_INTERVALS 里）。
     * field 格式见 MarketSnapshotStore::makeField()
     */
    public static function streamOfField(string $field): ?string
    {
        $parsed = MarketSnapshotStore::parseField($field);
        if ($parsed === null) return null;

        // pair = {symbol_code}_{type}，从最后一个下划线切：type 恒为纯数字
        $pos = strrpos($parsed['pair'], '_');
        if ($pos === false || $pos === 0) return null;
        $symbol = substr($parsed['pair'], 0, $pos);

        if ($parsed['channel'] === self::KLINE_CHANNEL) {
            $iv = self::KLINE_INTERVALS[(int)$parsed['suffix']] ?? null;
            return $iv === null ? null : "{$symbol}@kline_{$iv}";
        }
        $name = self::CHANNEL_STREAMS[$parsed['channel']] ?? null;
        return $name === null ? null : "{$symbol}@{$name}";
    }

    /** stream 名 -> 某类 pair 快照里的 field。stream 必须是 parseStream() 归一化过的 */
    public static function fieldOfStream(string $stream, int $type): ?string
    {
        [$symbol, $name] = array_pad(explode('@', $stream, 2), 2, '');
        $pair = MarketPublisher::pairKey($symbol, $type);

        if (str_starts_with($name, 'kline_')) {
            $period = array_search(substr($name, 6), self::KLINE_INTERVALS, true);
            return $period === false ? null : self::KLINE_CHANNEL . '|' . $pair . '|' . $period;
        }
        $channel = array_search($name, self::CHANNEL_STREAMS, true);
        return $channel === false ? null : $channel . '|' . $pair;
    }

    /**
     * 某类 pair 可订阅的 stream 名（不含 symbol 与间隔），顺序即对接文档里的顺序。
     * 现货没有 markPrice。后台交易对页与对接说明页用它列出可订阅项，与 parseStream() 认的是同一份全集
     *
     * @return string[] 如 ['ticker', 'markPrice', 'depth', 'trade', 'kline_1m', ...]
     */
    public static function streamNames(int $type): array
    {
        $names = [];
        foreach (self::CHANNEL_STREAMS as $name) {
            if ($name === 'markPrice' && $type === MarketDataTypeEnum::SPOT->value) continue;
            $names[] = $name;
        }
        foreach (self::KLINE_INTERVALS as $iv) $names[] = 'kline_' . $iv;
        return $names;
    }

    /**
     * stream 的类别：ticker / markPrice / depth / trade / kline（K 线各周期并成一类）。
     * 分发机按它统计每条连接的流量构成，后台分发监控页展示
     */
    public static function streamKind(string $stream): string
    {
        $pos = strpos($stream, '@');
        $name = $pos === false ? $stream : substr($stream, $pos + 1);
        return str_starts_with($name, 'kline_') ? 'kline' : $name;
    }

    /** 是否 depth stream。只有 depth 有推送间隔、会被降级 */
    public static function isDepthStream(string $stream): bool
    {
        return str_ends_with($stream, '@depth');
    }

    /**
     * 解析订阅方发来的 hello。
     *
     * 分发机在收到第一条合法 hello 之前不给这条连接推任何数据；订阅方不等 ack，发完 hello 直接收帧即可。
     * 同一条连接再发一次合法 hello = 整个替换订阅（不是增量），分发机会按新订阅再补一次快照。
     *
     * @param int $type 本端口分发的 pair 类型（MarketDataTypeEnum 的值）：现货端口不接受 markPrice
     * @param ?string $error 不合法时写入原因，分发机原样回给订阅方
     * @return array{node:string, streams:?array<string,int>}|null
     *   streams：null = 全部；否则 stream => 请求的推送间隔(毫秒)，只有 depth 有值，其余为 0
     */
    public static function parseHello(string $raw, int $type, ?string &$error = null): ?array
    {
        $error = null;
        if ($raw === '' || $raw[0] !== '{') return self::fail($error, 'hello 必须是 JSON 对象');
        if (strlen($raw) > self::MAX_HELLO_BYTES) return self::fail($error, 'hello 超过 ' . self::MAX_HELLO_BYTES . ' 字节');

        $msg = json_decode($raw, true);
        if (!is_array($msg) || ($msg['cmd'] ?? '') !== self::CMD_HELLO) return self::fail($error, 'cmd 必须是 hello');

        $unknown = array_diff(array_keys($msg), self::HELLO_KEYS);
        if ($unknown) return self::fail($error, '不认识的字段: ' . implode(',', $unknown) . '（只接受 cmd / node / streams）');

        $streams = null;
        if (array_key_exists('streams', $msg)) {
            $list = $msg['streams'];
            if (!is_array($list) || !array_is_list($list) || $list === []) {
                return self::fail($error, 'streams 必须是非空数组；要订阅全部就不传 streams');
            }
            if (count($list) > self::MAX_STREAMS) return self::fail($error, 'streams 超过 ' . self::MAX_STREAMS . ' 个');

            $streams = [];
            foreach ($list as $item) {
                $parsed = is_string($item) ? self::parseStream($item, $type, $error) : self::fail($error, 'stream 必须是字符串');
                if ($parsed === null) return null;
                [$stream, $iv] = $parsed;
                if (isset($streams[$stream])) return self::fail($error, "stream 重复: {$stream}");
                $streams[$stream] = $iv;
            }
        }

        return [
            // 订阅方机器标识。同一台机器的多条连接带同一个值，A 侧才能按机器聚合
            'node'    => self::sanitizeNode($msg['node'] ?? null),
            'streams' => $streams,
        ];
    }

    /**
     * 解析一个 stream：{symbol}@{名字}[@{间隔}]，大小写不敏感，归一化成规范写法。
     *
     *   btcusdt@ticker          最新价
     *   btcusdt@markPrice       标记价格（只有合约端口）
     *   btcusdt@depth           深度 20 档，默认 100ms
     *   btcusdt@depth@500ms     深度，指定间隔（DEPTH_INTERVALS 之一）
     *   btcusdt@trade           最近成交
     *   btcusdt@kline_1m        K 线，周期见 KLINE_INTERVALS
     *
     * symbol 按 MarketPublisher::pairKey() 的规则归一化（小写、去掉 -_/），BTC-USDT 与 btcusdt 等价。
     *
     * @return array{0:string,1:int}|null [规范 stream 名, 请求的间隔毫秒（非 depth 为 0）]
     */
    public static function parseStream(string $raw, int $type, ?string &$error = null): ?array
    {
        $parts = explode('@', strtolower(trim($raw)));
        if (count($parts) < 2 || count($parts) > 3) return self::fail($error, "stream 格式不对: {$raw}（应为 symbol@名字）");

        $symbol = str_replace(['-', '_', '/'], '', $parts[0]);
        // 纯数字的 symbol 必然是写错了：一条永远匹配不上的订阅，表现是安静的空流
        if ($symbol === '' || !ctype_alnum($symbol) || ctype_digit($symbol)) return self::fail($error, "symbol 不合法: {$raw}");

        $name = $parts[1];
        if ($name === 'markprice') {
            if ($type === MarketDataTypeEnum::SPOT->value) return self::fail($error, "现货端口没有 markPrice: {$raw}");
            $name = 'markPrice';
        } elseif (str_starts_with($name, 'kline_')) {
            if (!in_array(substr($name, 6), self::KLINE_INTERVALS, true)) {
                return self::fail($error, "K 线周期不支持: {$raw}（可选 " . implode('/', self::KLINE_INTERVALS) . '）');
            }
        } elseif (!in_array($name, ['ticker', 'depth', 'trade'], true)) {
            return self::fail($error, "stream 名不认识: {$raw}");
        }

        $iv = 0;
        if ($name === 'depth') {
            $iv = self::DEPTH_INTERVALS[0];
            if (isset($parts[2])) {
                $iv = str_ends_with($parts[2], 'ms') ? (int)substr($parts[2], 0, -2) : 0;
                if (!in_array($iv, self::DEPTH_INTERVALS, true)) {
                    return self::fail($error, "depth 间隔不支持: {$raw}（可选 " . implode('/', array_map(fn($v) => $v . 'ms', self::DEPTH_INTERVALS)) . '）');
                }
            }
        } elseif (isset($parts[2])) {
            return self::fail($error, "只有 depth 可以指定间隔: {$raw}");
        }

        return ["{$symbol}@{$name}", $iv];
    }

    /** 写入错误原因并返回 null，让解析函数一行 return */
    private static function fail(?string &$error, string $msg): null
    {
        $error = $msg;
        return null;
    }

    /**
     * 节点标识只做长度与字符集收紧。它会进日志、指标 key、监控页 ——
     * 放任意字符串进来等于把这三处的格式交给对端决定
     */
    private static function sanitizeNode($v): string
    {
        if (!is_string($v)) return '';
        $v = (string)preg_replace('/[^A-Za-z0-9._-]/', '', $v);
        return substr($v, 0, self::MAX_NODE_LEN);
    }

    /**
     * 订阅方侧：构造 hello
     *
     * @param string $node    本机标识，'' = 不上报（A 侧归到 '?'）
     * @param ?array $streams stream 列表，写法见 parseStream()；null = 本端口全部
     */
    public static function buildHello(string $node = '', ?array $streams = null): string
    {
        $msg = ['cmd' => self::CMD_HELLO];
        if ($node !== '') $msg['node'] = $node;
        if ($streams !== null) $msg['streams'] = array_values($streams);
        return json_encode($msg);
    }
}
