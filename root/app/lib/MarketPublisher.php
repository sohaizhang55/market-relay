<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketChannelEnum;

/**
 * pair 行情发布器（分发协议 v3）
 *
 * MarketService 在 pair 层每次更新后调用这里，把 pair 帧覆盖写进快照 HASH，
 * 由 MarketDispatcher 按 tick 广播给订阅方。
 * 帧按 pair（{symbol_code}_{type}）标识，不带交易所与 source_key：订阅方只认 pair。
 *
 * 帧里的价格是 pair 层的值，即交易所真实价。
 */
class MarketPublisher
{
    // 频道类型收敛在 MarketChannelEnum（Coding_Standards §8/§9：静态类型必须用 Backed Enum、
    // 比较必须走 EnumClass::CASE->value）。这里原先保留过一组同名的 CHAN_* 桥接常量，
    // 是为了让当时还在用 switch/case 的 MarketSubscriber 不必一起改；
    // 全部调用点迁到枚举之后（2026-08-23）桥接不再有使用者，一并删除 ——
    // 留着的常量迟早会被新代码用上，等于把「该用枚举」这条规范开一个永久的口子。

    // -------------------------------------------------------------------------
    // 连接级心跳（分发进程 ⇄ 订阅方之间长连接的探活契约，两端共用）
    //
    // 解决的问题：链路**静默中断**（中间防火墙/NAT 到期把连接悄悄丢弃，不发 FIN/RST）时
    // 两端都不会触发 onClose —— 分发端继续往死连接 send() 直到缓冲撑满静默丢包，
    // 订阅端不触发 onClose 就永远不 reConnect，行情静默停更，只能等
    // market:price_check_expire(60s) 从价格过期侧兜底告警。
    // 进程级心跳 (CommandHeartBeatService) 管不了这个：两端进程都活得好好的，卡死的是链路。
    //
    // 用应用层 JSON 文本帧而不是 WebSocket 协议层 ping/pong 帧：后者要依赖 Workerman
    // 各版本的 websocketType 常量与内部属性，生产装的是哪个版本不好保证；文本帧两端自己收发，
    // 行为完全可控，代价只是每 15s 一个几十字节的包。
    //
    // 间隔/超时写死成常量而不是读 config/market.php：该文件在 .gitignore 里、不随代码上生产，
    // 读配置会出现"生产端没同步 → 没生效"。
    // -------------------------------------------------------------------------

    /** A 端下发 ping 的间隔（秒），同时也是两端超时巡检 Timer 的周期 */
    const HEARTBEAT_INTERVAL = 15;

    /**
     * 多久没收到对端任何数据即判定链路已死（秒）。取 3 倍间隔，容忍订阅方事件循环偶发繁忙。
     *
     * ⚠️ 判定搭在同一个 HEARTBEAT_INTERVAL 的 Timer 上，所以**最坏回收延迟是
     * TIMEOUT + INTERVAL = 60 秒**，不是 45。本地实测（先回一次 pong 再装死）正是第 61 秒
     * 被回收、日志记 idle=60s。排查时别拿 45 去对时间线。
     *
     * 另：这个值必须远大于订阅方最坏的事件循环停顿，否则会误杀。
     * 回补拆到独立进程（market:price_fallback / market:kline_backfill）之后订阅方最坏停顿约
     * tick_budget_sec(默认3s) + 一个落库批次，有约 7 倍余量；若将来把同步重活塞回订阅方，
     * 这个余量立刻不成立。
     */
    const HEARTBEAT_TIMEOUT = 45;

    /**
     * 系统帧前缀。
     *
     * 为什么系统帧用 `_sys` 裸键，而握手用 `{"cmd":"hello"}` —— 这**不是**命名不一致，
     * 是两个层次：
     *   · `hello` 是 **线上协议里的应用命令**，归 MarketWireProtocol 管，
     *     声明订阅的 streams 与身份（node），需要完整 json_decode。
     *   · `_sys` 是 **传输层系统帧**（ping/pong/throttle/error），归本类管。它必须能在
     *     **不 json_decode** 的前提下识别出来 —— isSysFrame() 就是一次 strncmp，
     *     因为它在 onMessage 的最前面、每一帧都要过。
     * 下划线前缀正是为了让这次前缀比较不会撞上任何行情载荷的字段名。
     *
     * 把 hello 也改成 `_sys` 要么得对每帧解 JSON（前缀比较就白做了），
     * 要么改线格式（与已部署的订阅方不兼容）。两条都不划算。
     */
    const SYS_FRAME_PREFIX = '{"_sys":';

    const SYS_PING = 'ping';
    const SYS_PONG = 'pong';

    /** A -> 订阅方：这条连接的 depth 推送下限变了，附 depth_ms（0 = 恢复到订阅时声明的间隔） */
    const SYS_THROTTLE = 'throttle';

    /** A -> 订阅方：hello 不合法被拒，附 msg 说明原因。被拒的连接收不到任何行情 */
    const SYS_ERROR = 'error';

    /**
     * 构造系统帧。必须保证 _sys 是第一个键，isSysFrame() 的前缀判断依赖这一点
     *
     * @param string $type SYS_PING | SYS_PONG | SYS_THROTTLE | SYS_ERROR
     * @param int $tsMs 毫秒时间戳；pong 原样带回 ping 的值，便于 A 端需要时算 RTT
     * @param array $extra 附加字段，跟在 ts 之后
     */
    public static function sysFrame(string $type, int $tsMs, array $extra = []): string
    {
        return json_encode(['_sys' => $type, 'ts' => $tsMs] + $extra, JSON_UNESCAPED_UNICODE);
    }

    /** 是否心跳帧。行情帧走这里只多一次 strncmp，不解析 JSON */
    public static function isSysFrame(string $frameJson): bool
    {
        return strncmp($frameJson, self::SYS_FRAME_PREFIX, strlen(self::SYS_FRAME_PREFIX)) === 0;
    }

    /**
     * pair 的跨机身份：{symbol_code}_{type}，如 btcusdt_2。
     *
     * ⚠️ 不用 pair_id：它是各机 exchange_pair 的自增主键，本机与订阅方之间会静默错配。
     * symbol_code 在 exchange_pair 里已是归一化的小写无分隔符形式，这里再 strtolower 一次只为兜底。
     * 订阅方按同一规则从本机 exchange_pair 拼出 key 去对号入座。
     */
    public static function pairKey(string $symbolCode, int $type): string
    {
        return strtolower($symbolCode) . '_' . $type;
    }

    /**
     * 最新价（ticker）。$tick 是 MarketService::updatePrice() 算好的 pair 层快照，只取行情字段，
     * update_at / update_ts 这类本机落库字段不上线。
     *
     * @param array $pair exchange_pair 行，至少含 symbol_code、type
     */
    public static function publishTicker(array $pair, array $tick): void
    {
        self::push(MarketChannelEnum::PRICE, $pair, [
            'tick' => self::only($tick, ['last_price', 'open', 'high', 'low', 'close', 'amount', 'vol', 'change']),
            'ts'   => (int)($tick['market_ts'] ?? 0),
        ]);
    }

    /** 标记价格（仅合约） */
    public static function publishMarkPrice(array $pair, string $markPrice, int $ts): void
    {
        self::push(MarketChannelEnum::MARK_PRICE, $pair, [
            'mark_price' => $markPrice,
            'ts'         => $ts,
        ]);
    }

    /** 盘口：前 20 档 bids/asks + ts（毫秒） */
    public static function publishDepth(array $pair, array $depth): void
    {
        self::push(MarketChannelEnum::DEPTH, $pair, [
            'depth' => self::only($depth, ['bids', 'asks', 'ts']),
        ]);
    }

    /**
     * 成交：整窗覆盖发出，不是只发本次新增的。
     *
     * $window 是 MarketService::updateTrade() 维护的 market:trade:pair_{id}：已归一化为
     * {id, price, quantity, direction, ts}、按新到旧排列、最多 market.dispatch.trade_window 笔。
     * 覆盖语义下每帧都带全窗，订阅方按成交 id 去重即可，重复投递是幂等的。
     *
     * @param int $arrived 本次真正新增的笔数，只用于溢出计数，见 countTradeOverflow()
     */
    public static function publishTrade(array $pair, array $window, int $arrived): void
    {
        $key = self::pairKey((string)$pair['symbol_code'], (int)$pair['type']);
        self::countTradeOverflow($key, $arrived, self::tradeWindowSize());
        self::push(MarketChannelEnum::TRADE, $pair, [
            'data' => array_values($window),
        ]);
    }

    /**
     * K 线：当前这一根的合并状态（open 保留首次、high/low 取极值、其余为最新）。
     * ts 是这根 K 线的起始时刻，**毫秒**；pair_id 是本机字段，不上线。
     */
    public static function publishKline(array $pair, string $period, array $kline): void
    {
        self::push(MarketChannelEnum::KLINE, $pair, [
            'period' => $period,
            'kline'  => self::only($kline, ['ts', 'open', 'high', 'low', 'close', 'vol', 'amount']),
        ], $period);
    }

    /**
     * 发布一帧：覆盖写进快照 HASH，由分发机按 tick 取当前值广播。
     *
     * 每帧都带 pair / symbol / type 三个身份字段和 _channel 数据类型，订阅方据此对号入座；
     * 刻意**不带** exchange / source_key —— 订阅方不应该知道、也不需要知道数据来自哪家交易所。
     */
    private static function push(MarketChannelEnum $channel, array $pair, array $body, string $suffix = ''): void
    {
        $symbol = strtolower((string)$pair['symbol_code']);
        $type   = (int)$pair['type'];
        $key    = self::pairKey($symbol, $type);

        $payload = ['pair' => $key, 'symbol' => $symbol, 'type' => $type] + $body;
        $payload['_channel'] = $channel->value;

        // 按类型写进各自那一套快照键，由对应类型的分发进程（现货 / 合约各一个）取走
        MarketSnapshotStore::put($channel, $type, $key, $payload, $suffix);
    }

    /** 按白名单取字段，缺的字段不补（订阅方对缺字段按 0/空处理） */
    private static function only(array $row, array $keys): array
    {
        return array_intersect_key($row, array_flip($keys));
    }

    /** @var array<string, array{since:float,n:int}> pair => 本个 tick 窗口内已到达的成交笔数 */
    private static array $tradeArrivals = [];

    /** 真正被丢弃的成交笔数累计，由采集器 stat 定期读取并清零，见 countTradeOverflow() */
    private static int $windowOverflow = 0;

    /**
     * 统计**真正丢掉**的成交笔数。
     *
     * ⚠️ 这里刻意不等于「被挤出窗口的笔数」—— 那是个几乎恒为正的数字，会制造永久假告警：
     * 窗口是「保留最近 N 笔并整体覆盖发出」，被挤出去的那一笔在此之前的每一帧里
     * 都发过了，挤出去不是丢失，只是不再重复发。窗口一旦坐满 30 笔，之后每来一笔
     * 就挤掉一笔，按挤出计数等于把总成交量当成丢包量报出来。
     *
     * 真正会丢的只有一种情形：**同一 pair 在分发机的一个 tick 之内到达的成交
     * 超过窗口容量**。分发机每 tick 只取一次快照，那一次快照里最多装得下 N 笔，
     * 多出来的部分从来没有机会被任何一次快照带走。所以按 tick 窗口计数。
     */
    private static function countTradeOverflow(string $pair, int $arrived, int $keep): void
    {
        $now  = microtime(true);
        $tick = self::dispatchTickSeconds();
        $st   = self::$tradeArrivals[$pair] ?? ['since' => $now, 'n' => 0];

        if (($now - $st['since']) >= $tick) $st = ['since' => $now, 'n' => 0];

        $st['n'] += $arrived;
        if ($st['n'] > $keep) {
            self::$windowOverflow += $st['n'] - $keep;
            // 已经计过的那部分不再重复计：同一个 tick 窗口内后续再来成交时，
            // 只有相对 keep 的**新增**超出量才是新丢掉的
            $st['n'] = $keep;
        }
        self::$tradeArrivals[$pair] = $st;
    }

    /**
     * 分发机的 tick 周期(秒)。采集器与分发机同机同配置，直接读即可。
     * 兜底默认与 config/market.sample.php 的 MARKET_DISPATCH_TICK_MS 对齐（见 MarketDispatcher::DEFAULT_TICK_MS）
     */
    private static function dispatchTickSeconds(): float
    {
        static $sec = null;
        if ($sec === null) {
            $sec = max(0.01, (int)\think\facade\Config::get('market.dispatch.tick_ms', 100) / 1000);
        }
        return $sec;
    }

    public static function takeTradeWindowOverflow(): int
    {
        $n = self::$windowOverflow;
        self::$windowOverflow = 0;
        return $n;
    }

    private static function tradeWindowSize(): int
    {
        static $size = null;
        if ($size === null) {
            $size = max(1, (int)\think\facade\Config::get('market.dispatch.trade_window', 30));
        }
        return $size;
    }
}
