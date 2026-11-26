<?php

declare(strict_types=1);

namespace app\lib;

/**
 * K 线补数的记忆：哪些段交易所已确认没有 —— 记下来就不再为它们发请求。
 *
 * 没有这层记忆时，交易所本来就给不出的那段（维护停机、休市、上市前、火币现货够不着的老段）
 * 每轮都会被判成缺口重新去要，REST 配额全耗在同一批请求上。
 *
 * ⚠️ 只在**取数成功、而交易所确实没给**时记。取数失败（超时 / 限流 / 错误码）一律不记 ——
 * 拿失败记账会把一次 429 变成跨进程、跨重启的既成事实。
 *
 *   market:kline_gap_empty  field "{source_key}|{period}|{段起点}"  value "{段终点}:{记录时刻}"
 *
 * 后台「K 线补数记录」可以逐条查看、删除（删了就会重新去问）。
 */
final class KlineFetchMemory
{
    private const GAP_KEY = 'market:kline_gap_empty';

    /**
     * 单条记录的有效期，过期后重新确认一次。判断放在 PHP 里而不是用 Redis TTL：
     * TTL 只能挂在整个 hash 上，任何一次新写入都会把全量记录的过期时间一起推后，等于永不过期
     */
    public const TTL = 604800;

    /** 进程内缓存多久重读一次 Redis：让后台删掉的记录传到常驻进程 */
    private const RELOAD_SEC = 300;

    /** @var array<string,array{0:int,1:int}> field => [段终点, 记录时刻] */
    private static array $rows = [];

    /** @var array<string,array<int,array{0:int,1:int}>> "{source_key}|{period}" => [段起点 => [段终点, 记录时刻]] */
    private static array $index = [];

    private static int $loadedAt = 0;

    /**
     * 从缺失集合里扣掉已确认交易所没有的那些根，剩下的才值得去要
     *
     * @param array<int,true> $missing ts(毫秒) => true
     * @return array<int,true>
     */
    public static function withoutKnownEmpty(string $sourceKey, string $period, int $periodMs, array $missing): array
    {
        foreach (self::segments($sourceKey, $period) as [$start, $end]) {
            for ($ts = $start; $ts <= $end; $ts += $periodMs) unset($missing[$ts]);
        }
        return $missing;
    }

    /**
     * [fromMs, toMs] 里已确认交易所没有的根数（段之间有重叠时按并集算，不重复计）。
     * MarketKlineBackfill 拿它和「窗口里缺几根」比：缺的不比它多，就说明全都确认过了，不用扫库
     */
    public static function knownEmptyCount(string $sourceKey, string $period, int $periodMs, int $fromMs, int $toMs): int
    {
        $segments = self::segments($sourceKey, $period);
        if ($segments === []) return 0;
        usort($segments, fn($a, $b) => $a[0] <=> $b[0]);

        $count  = 0;
        $cursor = $fromMs;   // 已计到哪一根之前
        foreach ($segments as [$start, $end]) {
            $start = max($start, $cursor);
            $end   = min($end, $toMs);
            if ($start > $end) continue;
            $count += intdiv($end - $start, $periodMs) + 1;
            $cursor = $end + $periodMs;
        }
        return $count;
    }

    /** 记下 [起点, 终点] 这一段交易所没有；同一起点再记会覆盖（终点只会更晚，如火币现货够不着的范围随时间后移） */
    public static function markGapEmpty(string $sourceKey, string $period, int $startTs, int $endTs): void
    {
        self::load();
        $now   = time();
        $field = "{$sourceKey}|{$period}|{$startTs}";
        self::$rows[$field] = [$endTs, $now];
        self::$index["{$sourceKey}|{$period}"][$startTs] = [$endTs, $now];

        try {
            $redis = redis_client();
            if ($redis) $redis->hSet(self::GAP_KEY, $field, $endTs . ':' . $now);
        } catch (\Throwable $e) {
            // 只是少一次跨进程的记忆，不值得打断补数
        }
    }

    /** 记着的空段条数，给指标用 */
    public static function gapCount(): int
    {
        $expire = time() - self::TTL;
        return count(array_filter(self::load(), fn($r) => $r[1] > $expire));
    }

    /**
     * 后台列表用：未过期的空段记录
     *
     * @return array<int,array{source_key:string,period:string,start_ts:int,end_ts:int,marked_ts:int}>
     */
    public static function gapList(): array
    {
        $out    = [];
        $expire = time() - self::TTL;
        foreach (self::load() as $field => [$end, $at]) {
            if ($at <= $expire) continue;
            [$sourceKey, $period, $start] = array_pad(explode('|', $field, 3), 3, '');
            $out[] = ['source_key' => $sourceKey, 'period' => $period, 'start_ts' => (int)$start, 'end_ts' => $end, 'marked_ts' => $at];
        }
        return $out;
    }

    /** 删掉一条空段记录，market:kline_backfill 下次扫到这段会重新问交易所 */
    public static function forgetGap(string $sourceKey, string $period, int $startTs): bool
    {
        self::load();
        unset(self::$rows["{$sourceKey}|{$period}|{$startTs}"], self::$index["{$sourceKey}|{$period}"][$startTs]);
        try {
            $redis = redis_client();
            return $redis && (int)$redis->hDel(self::GAP_KEY, "{$sourceKey}|{$period}|{$startTs}") > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 缺失 ts 集合按连续段切开
     *
     * @param array<int,true> $missing ts(毫秒) => true，升序
     * @return array<int,array{0:int,1:int}> [[起点, 终点], ...]，升序
     */
    public static function holesOf(array $missing, int $periodMs): array
    {
        $holes = [];
        $start = $prev = null;
        foreach (array_keys($missing) as $ts) {
            if ($prev !== null && $ts - $prev === $periodMs) { $prev = $ts; continue; }
            if ($start !== null) $holes[] = [$start, $prev];
            $start = $prev = $ts;
        }
        if ($start !== null) $holes[] = [$start, $prev];
        return $holes;
    }

    /**
     * 一次成功取数之后，把覆盖范围内交易所没给的那些根按连续段记成空
     *
     * @param array<int,array{0:int,1:int}> $holes 取数前的缺口段
     * @param int $fromMs 这次请求的起点
     * @param int $coveredTo MarketRestAbstract::klineCoveredUntil() 的结果
     * @param array $candles 这次取回的蜡烛
     * @return int 记了几段
     */
    public static function markUncovered(string $sourceKey, string $period, int $periodMs, array $holes, int $fromMs, int $coveredTo, array $candles): int
    {
        $got = [];
        foreach ($candles as $c) $got[(int)($c['id'] ?? 0) * 1000] = true;

        $marked = 0;
        foreach ($holes as [$start, $end]) {
            // 只对这次请求覆盖到的那一截下结论；段比单次上限长时剩下的部分留给下一次
            $start = max($start, $fromMs);
            $end   = min($end, $coveredTo);
            if ($start > $end) continue;

            $runStart = null;
            for ($ts = $start; $ts <= $end + $periodMs; $ts += $periodMs) {
                $empty = $ts <= $end && !isset($got[$ts]);
                if ($empty) { $runStart ??= $ts; continue; }
                if ($runStart !== null) {
                    self::markGapEmpty($sourceKey, $period, $runStart, $ts - $periodMs);
                    $marked++;
                    $runStart = null;
                }
            }
        }
        return $marked;
    }

    /** @return array<int,array{0:int,1:int}> 某 (源,周期) 未过期的空段 [[起点, 终点], ...] */
    private static function segments(string $sourceKey, string $period): array
    {
        self::load();
        $out    = [];
        $expire = time() - self::TTL;
        foreach (self::$index["{$sourceKey}|{$period}"] ?? [] as $start => [$end, $at]) {
            if ($at > $expire) $out[] = [$start, $end];
        }
        return $out;
    }

    /** @return array<string,array{0:int,1:int}> 全部记录，每 RELOAD_SEC 秒重读一次 Redis；过期的顺手从 Redis 删掉 */
    private static function load(): array
    {
        $now = time();
        if (self::$loadedAt > 0 && $now - self::$loadedAt < self::RELOAD_SEC) return self::$rows;

        try {
            $redis = redis_client();
            // 读不到不更新缓存：Redis 抖一下不该让常驻进程长期失忆，下次调用重试
            if (!$redis) return self::$rows;
            $raw = $redis->hGetAll(self::GAP_KEY);
        } catch (\Throwable $e) {
            return self::$rows;
        }

        $rows    = [];
        $index   = [];
        $expired = [];
        foreach (is_array($raw) ? $raw : [] as $field => $val) {
            [$end, $at] = array_map('intval', array_pad(explode(':', (string)$val, 2), 2, '0'));
            if ($at <= $now - self::TTL) { $expired[] = (string)$field; continue; }
            $rows[(string)$field] = [$end, $at];
            $pos = strrpos((string)$field, '|');
            if ($pos !== false) $index[substr((string)$field, 0, $pos)][(int)substr((string)$field, $pos + 1)] = [$end, $at];
        }
        // hash 只增不减的话，会把每次确认过的段永远攒着
        try {
            foreach (array_chunk($expired, 200) as $chunk) $redis->hDel(self::GAP_KEY, ...$chunk);
        } catch (\Throwable $e) {
        }

        self::$rows     = $rows;
        self::$index    = $index;
        self::$loadedAt = $now;
        return $rows;
    }
}
