<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketExchangeEnum;

class MarketHelper
{
    /**
     * 平台标准 K 线周期(秒)，全站唯一来源——各交易所采集类的 PERIOD_MAP、K线断档回补等所有需要遍历
     * "支持哪些周期"的地方都引用这份常量，不再各自重复声明一份，避免改一处忘另一处。
     */
    public const KLINE_PERIODS = [60, 300, 900, 3600, 14400, 86400];

    /** klineKeep() 在配置缺失或周期不在表里时的兜底，与 config/market.sample.php 的 kline_retention 一致 */
    private const KLINE_KEEP_FALLBACK = 2000;

    /**
     * 取某一周期该保留几根 K 线，也是 market:kline_backfill 补数的窗口大小。
     *
     * 配置值可以是一个整数（所有周期统一，默认 2000），也可以是分周期的数组 —— 生产的
     * config/market.php 在 .gitignore 里、不随代码更新，两种写法都必须一直认。
     *
     * 汇总周期不额外多留基础周期：火币日线由 4 小时线汇总（见 klinePlan()），4 小时线也只留 2000 根，
     * 所以火币日线最多约 333 根（2000 × 4 小时 ≈ 333 天），补不满 2000，这是有意的取舍。
     *
     * @param string $layer 'source' = market_source_kline，'pair' = exchange_pair_kline
     */
    public static function klineKeep(string $layer, int|string $period): int
    {
        $cfg = \think\facade\Config::get("market.kline_retention.{$layer}_kline_keep", self::KLINE_KEEP_FALLBACK);

        $keep = is_array($cfg)
            ? (int)($cfg[(int)$period] ?? self::KLINE_KEEP_FALLBACK)
            : (int)$cfg;

        return max(1, $keep);
    }

    /** @var array<string, array{native: int[], derived: array<int,int>}> klinePlan() 的缓存，按交易所 */
    private static array $klinePlans = [];

    /**
     * 某交易所的 K 线周期安排：哪些周期直接用交易所的，哪些由别的周期汇总
     *
     * - native  可直接用：交易所提供（在它的 PERIOD_MAP 里），并且切分点与 UTC 对齐。
     *           不对齐的周期不写进 PERIOD_MAP，比如火币的日线（北京时间 00:00 切）
     * - derived 平台要、但不能直接用的周期 => 拿来汇总它的基础周期：能整除它的最大 native 周期。
     *           汇总在源层做（MarketService::deriveKlineLive / rebuildDerivedKlines），
     *           之后 pair 层、主备切换、补数都不用区分哪个周期是汇总的
     *
     * 没有 REST 实现的交易所（没有 PERIOD_MAP）视为全部可直接用 —— 它本来就采不到 K 线。
     *
     * @return array{native: int[], derived: array<int,int>}
     */
    public static function klinePlan(MarketExchangeEnum|string $exchange): array
    {
        $ex = $exchange instanceof MarketExchangeEnum ? $exchange : MarketExchangeEnum::tryFrom(strtolower($exchange));
        $cacheKey = $ex?->value ?? (string)$exchange;
        if (isset(self::$klinePlans[$cacheKey])) return self::$klinePlans[$cacheKey];

        $client = $ex !== null ? MarketRestAbstract::make($ex) : null;
        $map    = $client !== null && defined(get_class($client) . '::PERIOD_MAP') ? $client::PERIOD_MAP : null;
        $native = $map === null
            ? self::KLINE_PERIODS
            : array_values(array_intersect(self::KLINE_PERIODS, array_map('intval', array_keys($map))));

        $derived = [];
        foreach (self::KLINE_PERIODS as $period) {
            if (in_array($period, $native, true)) continue;
            $bases = array_filter($native, fn(int $n) => $n < $period && $period % $n === 0);
            if ($bases) $derived[$period] = max($bases);
        }
        return self::$klinePlans[$cacheKey] = ['native' => $native, 'derived' => $derived];
    }

    /**
     * 生成统一的行情来源标识
     * 格式：EXCHANGE_symbol_type，例如 HUOBI_btcusdt_1
     */
    public static function makeSourceKey(MarketExchangeEnum $exchange, string $symbol, int $type): string
    {
        $normalizedSymbol = str_replace(['-', '_', '/'], '', strtolower($symbol));
        return strtoupper($exchange->value) . '_' . $normalizedSymbol . '_' . $type;
    }

    /**
     * 将行情价格安全转换为 bcmath 可接受的定点数字符串
     * 极低价格币种 (如 SHIB) 经 float 转换后会变成科学计数法 (如 "4.34E-6")，
     * bcmath 函数无法解析该格式会抛出 ValueError，这里统一转换避免该问题
     */
    public static function safeNumStr(mixed $value): string
    {
        return sprintf('%.10F', (float)$value);
    }

    /**
     * 简单 HTTP GET + JSON 解析，短超时：可能运行在 Workerman 事件循环内，避免阻塞太久
     * 供各交易所 Market 采集类的 REST 兜底 (fetchRestLastPrice/fetchRestMarkPrice) 使用
     * @param string $url 请求地址
     * @param int $timeoutSec 总超时时间；全量批量接口 (数百 symbol) 响应体更大，按需调大
     * @param string[] $headers 附加请求头，如调用 inner_api 时携带的共享密钥 X-Inner-Token
     */
    public static function httpGetJson(string $url, int $timeoutSec = 3, array $headers = []): ?array
    {
        // 该 host 正在限流冷却中：直接不发，把配额留给 WS 重连。
        // 调用方拿到 null 的处理路径与"请求失败"完全一致（保留上次值/下轮重试），无需改动
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        if (self::inRateLimitCooldown($host)) return null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'market-relay/1.0',
        ]);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // PHP8 起 curl handle 由 GC 自动回收，curl_close() 已无实际作用，PHP8.5 起会抛 Deprecated (被 ThinkPHP 错误处理器转成异常)，故不再显式调用

        if ($code === 429 || $code === 418) {
            // 被限流：立刻让整个 host 进入冷却，而不是继续按原节奏打
            self::enterRateLimitCooldown($host, $code);
            return null;
        }

        if ($body === false || $code !== 200) {
            self::noteHttpFailure($host, $code);
            return null;
        }

        self::$failStreak[$host] = 0;

        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * 连续失败(超时/连不上/非 200)达到阈值就让该 host 进入冷却。
     *
     * ⚠️ 这条是补一个真实的缺口：原先只有 429/418 会熔断，而**超时根本不触发任何熔断** ——
     * 偏偏超时才是致命的那一种。以 klineBackfillTick 为例：它挂在 30 秒定时器上，
     * 单轮最坏要跑满 max_fixes_per_tick × CURLOPT_TIMEOUT 秒。一旦交易所 REST 不可达，
     * 单轮耗时就会超过定时器周期，Workerman 单线程定时器不重入、只会背靠背连续执行 ——
     * 同进程的其它任务（价格兜底、心跳）就**近乎永久**轮不到。
     *
     * 有了熔断，对端挂掉时那条路径的阻塞占空比从 >100% 降到 单轮deadline/冷却时长。
     */
    private static function noteHttpFailure(string $host, int $code): void
    {
        if ($host === '') return;

        $n = (self::$failStreak[$host] ?? 0) + 1;
        self::$failStreak[$host] = $n;
        if ($n < self::FAIL_STREAK_THRESHOLD) return;

        self::$failStreak[$host] = 0;
        self::enterCooldown($host, self::failCooldownSeconds(), sprintf(
            '连续 %d 次请求失败(最后一次 HTTP %d)', self::FAIL_STREAK_THRESHOLD, $code
        ));
    }

    /** 失败熔断时长(秒)。比限流冷却短：限流是对方明确要求退避，失败可能只是一次抖动 */
    private static function failCooldownSeconds(): int
    {
        return max(5, (int)\think\facade\Config::get('market.rest_fallback.fail_cooldown', 60));
    }

    // -------------------------------------------------------------------------
    // 交易所限流冷却（429/418）
    //
    // 背景（2026-08-19 生产事故）：火币的 WS 与 REST **共用 api.hbdm.com 这一个域名**，
    // 因此共享同一份 CloudFront 限流配额。WS 握手一旦拿到 429，原先的固定 5 秒重连会一直硬撞，
    // 同时 WS 断开导致价格过期、REST 兜底(每10s)+K线回补(每30s最多5个)全速运转，
    // 两股流量叠加持续打向同一个 host —— 限流窗口永远刷不掉，形成自维持的死锁。
    //
    // 这里的冷却负责切断 REST 那一路：一旦收到 429，该 host 上所有 REST 请求立即停发，
    // 把配额让给 WS 重连（WS 侧的指数退避见 MarketCollectorAbstract::nextReconnectDelay）。
    //
    // 冷却状态存 Redis 而不是进程内静态：market:huobi 的 spot / swap 是**两个独立进程**，
    // 但共用一个出口 IP、撞的是同一份配额，进程内变量会让另一个进程毫不知情地继续打。
    // Redis 不可用时降级为进程内静态，至少还能拦住本进程。
    // -------------------------------------------------------------------------

    /** 收到 429/418 后该 host 停发 REST 的秒数 */
    private const RATE_LIMIT_COOLDOWN = 120;

    /** 连续多少次请求失败就熔断该 host，见 noteHttpFailure() */
    private const FAIL_STREAK_THRESHOLD = 3;

    private const COOLDOWN_KEY_PREFIX = 'market:ratelimit:cooldown:';

    /** Redis 不可用时的降级存储：host => 冷却截止时间戳 */
    private static array $localCooldown = [];

    /** @var array<string,int> host => 连续失败次数，成功即清零 */
    private static array $failStreak = [];

    /**
     * 该 host 是否处于限流冷却期内
     */
    public static function inRateLimitCooldown(string $host): bool
    {
        if ($host === '') return false;

        if ((self::$localCooldown[$host] ?? 0) > time()) return true;

        $redis = redis_client();
        if (!$redis) return false;

        try {
            return (bool)$redis->exists(self::COOLDOWN_KEY_PREFIX . $host);
        } catch (\Throwable $e) {
            return false; // Redis 抖动不该反过来阻断行情采集
        }
    }

    /**
     * 让某 host 进入限流冷却
     */
    private static function enterRateLimitCooldown(string $host, int $code): void
    {
        self::enterCooldown($host, self::RATE_LIMIT_COOLDOWN, "HTTP {$code} 限流");
    }

    /**
     * 让某个 host 进入冷却：冷却期内 httpGetJson() 直接返回 null，一个字节都不发。
     * 进程内 + Redis 双写，这样同机多个采集进程能共享同一份退避决定。
     */
    private static function enterCooldown(string $host, int $seconds, string $reason): void
    {
        if ($host === '') return;

        $until = time() + $seconds;
        $already = self::$localCooldown[$host] ?? 0;
        self::$localCooldown[$host] = $until;

        $redis = redis_client();
        if ($redis) {
            try {
                $redis->setex(self::COOLDOWN_KEY_PREFIX . $host, $seconds, (string)$until);
            } catch (\Throwable $e) {
            }
        }

        // 冷却期内重复命中不再刷日志，避免限流风暴时把日志也打爆
        if ($already <= time()) {
            business_log(
                'market_rate_limit',
                "[{$host}] {$reason}，REST 暂停 {$seconds}s",
                'error'
            );
        }
    }
}
