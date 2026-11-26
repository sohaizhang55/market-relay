<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketChannelEnum;

/**
 * 行情源的 WS 新鲜度 —— 主备切换判断「源断流」的唯一依据
 *
 * 采集器每收到一帧价格 / 深度就在进程内记下该源的时刻，每秒一次批量写进 Redis；
 * 选源进程读它，按 market_data.ticker_stale_sec / depth_stale_sec 分别判断两路有没有断流。
 *
 * 为什么不复用现成的时间戳：
 *   market_data.update_ts   market:price_fallback 的 REST 兜底也会刷它。WS 断了靠 REST 续命时它照样新鲜，
 *                           选源进程会以为主源健康，永远不切
 *   MarketMetrics collect   每 10 秒才刷一次 Redis，正常的源读出来也可能已经 9 秒多没更新，
 *                           离断流阈值太近，定时器一抖就误切
 *
 * 价格与深度分开记：深度停了 = 连接或订阅坏了；价格停了而深度还在推 = 价格那一路单独坏了，
 * 合成一个时间就分不出后者。只记这两路：币安 K 线是独立的一条连接，价格那条断了 K 线可能还在推；
 * 成交在冷门对上本来就稀疏。
 *
 * 顺带在采集器里算帧间隔统计（最长间隔、超过断流秒数的次数），每 10 分钟落一次 Redis，
 * 后台「原始行情」页据此调断流秒数。放在采集器而不是选源进程：选源进程默认不启用，
 * 而阈值恰恰要在启用之前调好。统计在新帧到达时算，一个源彻底断了、一直没有新帧时，
 * 这段间隔要等它恢复（或下一次落盘，按「至今仍未来帧」计）才记上 —— 断流当下看新鲜度那两列。
 *
 * 写  MarketCollectorAbstract::handlePrice / handleDepth
 * 读  选源进程（新鲜度）· 后台「原始行情」页（新鲜度与统计）
 * 落地  Redis HASH market:source:ws_ts:price、market:source:ws_ts:depth，
 *       field = source_key，value = 毫秒时间戳，常驻不设 TTL
 *       Redis HASH market:source:gap_stats，field = source_key，value = json，每个采集器只覆盖自己的源
 */
class MarketSourceHealth
{
    public const WS_TS_PREFIX = 'market:source:ws_ts:';
    public const STATS_KEY    = 'market:source:gap_stats';

    /** 断流秒数在采集器进程里缓存多久（秒）：后台改了阈值，统计最多这么久后按新值算 */
    private const LIMITS_TTL = 60;

    /** @var array<string,array<string,int>> 频道后缀 => [source_key => 本进程最近一帧的毫秒时间戳]，等下一次 flush() */
    private static array $pending = [];

    /** @var array<string,array<string,int>> 频道后缀 => [source_key => 上一帧的毫秒时间戳]，算间隔用 */
    private static array $lastFrame = [];
    /** @var array<string,array<string,array{max:int,stale:int}>> 频道后缀 => [source_key => 本窗口最长间隔(ms) / 超阈值次数] */
    private static array $gapStats = [];
    private static int $statsSince = 0;

    /** @var array<string,array{price:int,depth:int}> source_key => 断流秒数 */
    private static array $limits = [];
    private static int $limitsAt = 0;

    /** 记一帧，顺带累计帧间隔统计。热路径，只动进程内数组（阈值每分钟才查一次库）；价格、深度以外的频道忽略 */
    public static function touch(MarketChannelEnum $channel, string $sourceKey): void
    {
        $suffix = self::suffix($channel);
        if ($suffix === null) return;
        $now = (int)(microtime(true) * 1000);
        self::$pending[$suffix][$sourceKey] = $now;
        self::$statsSince = self::$statsSince ?: intdiv($now, 1000);

        $last = self::$lastFrame[$suffix][$sourceKey] ?? 0;
        self::$lastFrame[$suffix][$sourceKey] = $now;
        if ($last > 0) self::recordGap($suffix, $sourceKey, $now - $last);
    }

    /**
     * 把本窗口的帧间隔统计写进 Redis 并清零，由采集器每 10 分钟调用。
     *
     * 本窗口一帧都没来的源也写：间隔按「到此刻为止仍未来帧」算，否则彻底断掉的源统计里看着一切正常。
     * 只 hMSet 本进程的源、不 del 整个 HASH —— 几个采集器各写各的交易所，删了会把别人的抹掉。
     */
    public static function flushStats(): void
    {
        $now = (int)(microtime(true) * 1000);
        foreach (self::$lastFrame as $suffix => $map) {
            foreach ($map as $sk => $last) {
                if (!isset(self::$gapStats[$suffix][$sk])) self::recordGap($suffix, $sk, $now - $last);
            }
        }

        $since = self::$statsSince ?: (int)(($now - 600000) / 1000);
        $hash  = [];
        foreach (array_unique(array_merge(...array_map('array_keys', array_values(self::$gapStats ?: [[]])))) as $sk) {
            $p = self::$gapStats['price'][$sk] ?? ['max' => 0, 'stale' => 0];
            $d = self::$gapStats['depth'][$sk] ?? ['max' => 0, 'stale' => 0];
            $hash[$sk] = json_encode([
                'price_max_gap_sec' => round($p['max'] / 1000, 1),
                'depth_max_gap_sec' => round($d['max'] / 1000, 1),
                'price_stale_times' => $p['stale'],
                'depth_stale_times' => $d['stale'],
                'ticker_stale_sec'  => self::limit($sk, 'price'),
                'depth_stale_sec'   => self::limit($sk, 'depth'),
                'since'             => $since,
                'until'             => intdiv($now, 1000),
            ]);
        }

        self::$gapStats   = [];
        self::$statsSince = intdiv($now, 1000);
        if (!$hash) return;

        try {
            redis_client()?->hMSet(self::STATS_KEY, $hash);
        } catch (\Throwable) {
        }
    }

    /** 记一段帧间隔：更新最长值，超过该源断流秒数（0 = 不判）就记一次 */
    private static function recordGap(string $suffix, string $sk, int $gapMs): void
    {
        $s = &self::$gapStats[$suffix][$sk];
        $s ??= ['max' => 0, 'stale' => 0];
        if ($gapMs > $s['max']) $s['max'] = $gapMs;
        $limit = self::limit($sk, $suffix);
        if ($limit > 0 && $gapMs > $limit * 1000) $s['stale']++;
    }

    /** 某个源某一路的断流秒数，整表缓存 LIMITS_TTL 秒；查不到按 0（不判） */
    private static function limit(string $sk, string $suffix): int
    {
        if (time() - self::$limitsAt >= self::LIMITS_TTL) {
            self::$limitsAt = time();
            try {
                $rows = \think\facade\Db::name('market_data')->field('source_key, ticker_stale_sec, depth_stale_sec')->limit(5000)->select();
                self::$limits = [];
                foreach ($rows as $r) {
                    self::$limits[(string)$r['source_key']] = ['price' => (int)$r['ticker_stale_sec'], 'depth' => (int)$r['depth_stale_sec']];
                }
            } catch (\Throwable) {
                // 库不可用时沿用上一份，统计不能拖垮采集
            }
        }
        return self::$limits[$sk][$suffix] ?? 0;
    }

    /**
     * 把上次 flush 之后收到过帧的源写进 Redis（每路一次 hMSet），由采集器每秒调用。
     *
     * 失败时保留 $pending 等下一秒重试（值只会被更新的帧覆盖，不会积压）；不抛 ——
     * 写不进去的后果是选源进程把这些源看成断流，那是选源侧要兜的，不能拖垮采集。
     */
    public static function flush(): void
    {
        if (!self::$pending) return;

        $redis = redis_client();
        if (!$redis) return;

        try {
            $pipe = $redis->multi(\Redis::PIPELINE);
            foreach (self::$pending as $suffix => $map) {
                $pipe->hMSet(self::WS_TS_PREFIX . $suffix, $map);
            }
            $pipe->exec();
            self::$pending = [];
        } catch (\Throwable) {
        }
    }

    /**
     * 某一路下全部源最近一次收到 WS 帧的毫秒时间戳。
     *
     * @return array<string,int> 从没收到过帧的源不在里面；Redis 不可用或频道不是价格 / 深度时返回空数组
     */
    public static function wsTsMap(MarketChannelEnum $channel): array
    {
        $suffix = self::suffix($channel);
        $redis  = redis_client();
        if ($suffix === null || !$redis) return [];

        try {
            return array_map('intval', $redis->hGetAll(self::WS_TS_PREFIX . $suffix) ?: []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 后台展示用：一路新鲜度的 [文字, 颜色类, 悬浮说明]，「原始行情」与「交易对数据源」两页共用。
     *
     * 「-」不标红：本机从没收到过这一路的帧，多半是采集器没跑，
     * 不是这个源的故障；采集器在跑却一直是「-」，看「行情链路监控」页定位。
     *
     * @param int $lastMs 最后一帧的毫秒时间戳，0 = 本机从没收到过
     * @param int $limit  断流秒数，0 = 这一路不判
     * @param int $nowMs  当前毫秒时间戳，整页共用一个，避免逐行取时间造成同一页口径不一
     */
    public static function label(int $lastMs, int $limit, int $nowMs): array
    {
        if ($lastMs <= 0) {
            return ['-', 'color-desc', '本机没收到过这一路的 WS 帧（采集器没跑？）'];
        }
        $age   = max(0, intdiv($nowMs - $lastMs, 1000));
        $text  = $age < 60 ? "{$age}s" : ($age < 3600 ? intdiv($age, 60) . 'm' : '>1h');
        $title = '最后一帧 ' . date('Y-m-d H:i:s', intdiv($lastMs, 1000));
        if ($limit <= 0) {
            return [$text, 'color-desc', $title . '；断流秒数为 0，这一路不参与判断'];
        }
        return $age > $limit
            ? [$text, 'color-red', $title . "；超过断流阈值 {$limit} 秒，主备切换会判该源不健康"]
            : [$text, 'color-green', $title . "；断流阈值 {$limit} 秒"];
    }

    /** 频道 => Redis key 后缀；只认价格与深度 */
    private static function suffix(MarketChannelEnum $channel): ?string
    {
        return match ($channel) {
            MarketChannelEnum::PRICE => 'price',
            MarketChannelEnum::DEPTH => 'depth',
            default                  => null,
        };
    }
}
