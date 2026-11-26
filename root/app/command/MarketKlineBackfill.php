<?php

declare(strict_types=1);

namespace app\command;

use think\console\Output;
use Workerman\Worker;
use think\facade\Db;
use app\lib\KlineFetchMemory;
use app\lib\MarketHelper;
use app\lib\MarketRestAbstract;
use app\business\service\MarketService;
use app\service\MarketSessionService;

/**
 * K 线补数进程，每个交易所一个：保证每个周期最近 klineKeep() 根（默认 2000，配置 market.kline_retention）不缺
 *   —— 尾部滞后、pair 层重放、窗口补满、汇总周期。更早的不补，也会被 market:kline_clean 裁掉。
 *
 * 取数走交易所 REST，每次按交易所单次上限拿满；交易所确认没有的段记进 KlineFetchMemory 不再问。
 * 价格兜底见 MarketPriceFallback。
 *
 * 命令  php think market:kline_backfill
 * 触发  deploy/restart_market.sh
 * 单例  按交易所：pid 文件 runtime/market-relay_MarketKlineBackfill_{exchange}.pid（见 SingletonGuardTrait）
 * 依赖  配置 market.kline_backfill · market.kline_retention
 *
 * 用法:
 *   php think market:kline_backfill start -e huobi -d     # 后台常驻，只管 huobi 的源
 *   php think market:kline_backfill status -e bingx
 *   php think market:kline_backfill stop -e binance
 */
class MarketKlineBackfill extends MarketRestWorkerAbstract
{
    /** 扫描游标：存上一轮停下的 source_key（不存下标，源集合增删后仍能定位） */
    private string $klineSourceCursor = '';

    /**
     * 两层 K 线表同步的产出，窗口内累加、由指标上报清零
     *   seeded     从零播种的 (pair, period) 数
     *   replayed   有历史、补齐缺口的 (pair, period) 数
     */
    private array $pairSyncStat = ['seeded' => 0, 'replayed' => 0];

    /** 第 3 层本窗口补了几次，由指标上报清零 */
    private int $windowFixed = 0;

    public static function workerName(string $exchange): string
    {
        return 'market_relay_MarketKlineBackfill_' . $exchange;
    }

    protected function configure()
    {
        $this->configureWorker('market:kline_backfill', 'Market kline backfill worker: keep the latest N candles per period complete via exchange REST');
    }

    protected function logTag(): string
    {
        return 'Kline-Backfill';
    }

    protected function logChannel(): string
    {
        return 'market_kline_backfill';
    }

    protected function registerTasks(Output $output): void
    {
        // ⚠️ K 线回补走 isPadding=true 直写 exchange_pair_kline，与 MarketDbFlusher 并发写同一张表，
        //    flushKlineBuffer() 的取锁排序不能删
        $this->registerTask('kline_backfill', $this->scanInterval(), fn() => $this->klineBackfillTick($output));
    }

    protected function budgetSeconds(): float
    {
        return (float)($this->cfg('kline_backfill')['tick_budget_sec'] ?? 3);
    }

    protected function bannerDetail(): string
    {
        return 'interval=' . $this->scanInterval() . 's';
    }

    protected function workerMetrics(): array
    {
        return [
            'pair_sync'  => json_encode($this->pairSyncStat, JSON_UNESCAPED_UNICODE),
            // gap_empty 是全局共享的记录数（各交易所进程共用一份），只看趋势
            'kline_fill' => json_encode([
                'window'    => $this->windowFixed,
                'gap_empty' => KlineFetchMemory::gapCount(),
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    protected function resetWindowStats(): void
    {
        $this->pairSyncStat = ['seeded' => 0, 'replayed' => 0];
        $this->windowFixed = 0;
    }

    private function scanInterval(): int
    {
        return (int)($this->cfg('kline_backfill')['scan_interval'] ?? 30);
    }

    /** pair 层 K 线从零播种时从源层取最近多少根：与 pair 层保留量一致 */
    private function pairSeedCandles(string $period): int
    {
        return MarketHelper::klineKeep('pair', $period);
    }

    /** 取历史K线（交易所 REST）；返回 [['id'=>秒级ts,'open','high','low','close','vol','amount'], ...] 升序，失败 null */
    private function fetchKlines(array $src, string $period, int $sinceMs, int $limit, Output $output): ?array
    {
        $sourceKey = (string)$src['source_key'];

        $client = $this->restClient((string)$src['exchange']);
        if (!$client) {
            // 没有 REST 实现的交易所是配置现状不是故障，只在前台提示
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Kline-Backfill][kline]</comment> {$sourceKey} 跳过: exchange={$src['exchange']} 无 REST 实现");
            }
            return [];
        }

        $candles = $client->fetchRestKlines((string)$src['symbol'], (int)$src['type'], $period, $sinceMs, $limit);

        if (!Worker::$daemonize) {
            // fetchRestKlines() 失败返回 null：直接 count(null) 会抛 TypeError 中断整轮补数。
            // 只在前台（Windows 上 Workerman 恒为前台）才走到这里，所以线上 -d 从没暴露过
            $result = $candles === null ? 'FAIL' : count($candles) . ' candles';
            $output->writeln("<comment>[Kline-Backfill][kline]</comment> {$sourceKey}[{$period}] REST since=" . date('H:i:s', intdiv($sinceMs, 1000)) . " -> {$result}");
        }

        return $candles;
    }

    /**
     * K线补数：扫描 source_key × 可直接取数的周期，按层修复，配额或预算用完就停、下轮从游标续扫
     *
     * 目标只有一个：每个周期最近 klineKeep() 根（默认 2000）不缺。更早的不补，也会被 market:kline_clean 裁掉。
     * 流程：1 尾部滞后 → 2 pair 层重放 → 3 窗口补满 → 5 汇总周期重算
     * 边界：某层修了就 continue 到下一个周期
     *
     * 每次取数都按交易所单次上限拿满（MarketRestAbstract::klineMaxLimit）；交易所确认没有的段记进
     * KlineFetchMemory，之后不再为它发请求 —— 否则补不上的段每轮都要重新问一遍。
     * 取数失败按 (源,周期) 指数退避，见 fetchBackoff()
     */
    private function klineBackfillTick(Output $output): void
    {
        $cfg = $this->cfg('kline_backfill');
        if (empty($cfg['enable'])) {
            if (!Worker::$daemonize) {
                $output->writeln('<comment>[Kline-Backfill][kline]</comment> 已由 market.kline_backfill.enable 关闭');
            }
            return;
        }

        // 休市中的源也要扫：窗口里过去的洞跟现在开不开市无关。只有尾部按开休市判，见下
        $sources = $this->loadSources(null, false);
        if (empty($sources)) {
            if (!Worker::$daemonize) {
                $output->writeln('<comment>[Kline-Backfill][kline]</comment> market_data 无行情源配置，跳过');
            }
            return;
        }

        $gapPeriods = (int)($cfg['gap_periods'] ?? 2);
        $nowMs      = (int)(microtime(true) * 1000);

        $maxFixesPerTick = max(1, (int)($cfg['max_fixes_per_tick'] ?? 5));
        // 只用来读能力声明（单次上限、能否翻页）；真正取数在 fetchKlines() 里按本轮剩余预算另建
        $rest = MarketRestAbstract::make($this->exchange);

        // 每个周期的窗口起点：最近 klineKeep() 根的第一根
        $windowStarts = [];
        foreach (MarketHelper::klinePlan($this->exchange)['native'] as $periodSec) {
            $periodMs = $periodSec * 1000;
            $windowStarts[$periodSec] = intdiv($nowMs, $periodMs) * $periodMs
                - (MarketHelper::klineKeep('source', $periodSec) - 1) * $periodMs;
        }
        // 源层与 pair 层的统计都在轮首一次聚合查询取回，循环里不再逐个查库
        $stats = $this->klineStats(array_column($sources, 'source_key'), $windowStarts);

        $pairsBySource = [];
        foreach (Db::name('exchange_pair')->field('id, source_key, symbol_code, type')->select()->toArray() as $r) {
            $pairsBySource[(string)$r['source_key']][] = $r;
        }
        $pairLastTsMap = [];
        foreach (Db::name('exchange_pair_kline')->field('pair_id, period, MAX(ts) AS last_ts')
                     ->group('pair_id, period')->select()->toArray() as $r) {
            $pairLastTsMap[(int)$r['pair_id'] . '|' . (string)$r['period']] = (int)$r['last_ts'];
        }

        $ordered = $this->rotateByCursor($sources, $this->klineSourceCursor);
        $fixed = 0;
        $scanned = 0;
        $byLayer = ['tail' => 0, 'pair_replay' => 0, 'window' => 0, 'derive' => 0];
        // 收工原因：'' = 整轮扫完，'quota' = 撞 max_fixes_per_tick，'budget' = 撞 tick_budget_sec
        $stopReason = '';

        foreach ($ordered as $src) {
            $scanned++;
            $sourceKey = (string)$src['source_key'];
            $type      = (int)$src['type'];
            $limit     = $rest?->klineMaxLimit($type) ?? 0;
            $tradable  = MarketSessionService::tradableBySourceKey($sourceKey);

            // 只对「可直接用」的周期向交易所取数；汇总周期（火币日线）在下面第 5 层按基础周期重算。
            // 没有 REST 实现的交易所（$limit = 0）只做第 2 层与第 5 层
            foreach (MarketHelper::klinePlan($this->exchange)['native'] as $periodSec) {
                $period      = (string)$periodSec;
                $periodMs    = $periodSec * 1000;
                $windowStart = $windowStarts[$periodSec];
                $key         = $sourceKey . '|' . $period;

                [$lastTs, , , $winCount] = $stats[$key] ?? [0, 0, 0, 0];
                $expectedTs = intdiv($nowMs, $periodMs) * $periodMs;
                $canFetch   = $limit > 0 && !$this->fetchBackoff($key);

                if ($this->budgetExpired()) { $stopReason = 'budget'; break 2; }

                // 1 尾部滞后：最新一根落后 gap_periods 个周期以上。只在开市时补 ——
                //   休市中尾部本来就不会有新的，问了也是白问；开市后中间那段会变成窗口里的洞，由第 3 层补
                if ($canFetch && $tradable && $lastTs > 0
                    && intdiv($expectedTs - $lastTs, $periodMs) >= $gapPeriods
                    && !$this->tailBackoff($key, $lastTs)) {
                    $wrote = $this->klineBackfillFix($src, $period, max($lastTs, $windowStart - $periodMs), $expectedTs, $limit, $output);
                    $this->noteFetch($key, $wrote !== null);
                    if ($wrote === 0) $this->noteTailEmpty($key, $lastTs);
                    $byLayer['tail']++;
                    if (++$fixed >= $maxFixesPerTick) { $stopReason = 'quota'; break 2; }
                    continue;
                }

                // 2 pair 层重放：源层不缺，但 exchange_pair_kline 落后（实时同步漏写、新接入的交易对等）
                if ($lastTs > 0 && $this->replayPairKlineGap($sourceKey, $period, $lastTs, $periodMs, $nowMs, $output,
                        $pairsBySource[$sourceKey] ?? [], $pairLastTsMap)) {
                    $byLayer['pair_replay']++;
                    if (++$fixed >= $maxFixesPerTick) { $stopReason = 'quota'; break 2; }
                    continue;
                }

                // 3 窗口补满：[窗口起点, 最新一根] 里缺的根（一根都没有时补到上一根已收盘的，当前那根交给 WS）。
                //   缺的根数不超过已确认交易所没有的根数，就说明全都确认过了，连库都不用扫
                $upper   = $lastTs > 0 ? $lastTs : $expectedTs - $periodMs;
                $missing = $upper >= $windowStart ? intdiv($upper - $windowStart, $periodMs) + 1 - $winCount : 0;
                if ($canFetch && $missing > 0
                    && $missing > KlineFetchMemory::knownEmptyCount($sourceKey, $period, $periodMs, $windowStart, $upper)) {
                    $reachFrom = $rest->klineReachFromMs($type, $periodSec, $nowMs);
                    $pending = $this->pendingWindowGaps($sourceKey, $period, $periodMs, $windowStart, $upper, $reachFrom);
                    if ($pending !== null) {
                        [$holes, $missingTs] = $pending;
                        $ok = $this->fillKlineHole($src, $period, $periodMs, $holes, $missingTs, $limit, $reachFrom, $output);
                        $this->noteFetch($key, $ok);
                        $byLayer['window']++;
                        $this->windowFixed++;
                        if (++$fixed >= $maxFixesPerTick) { $stopReason = 'quota'; break 2; }
                        continue;
                    }
                }
            }

            // 5 汇总周期：不向交易所要，按库里的基础周期重算。平时由实时汇总（MarketService::deriveKlineLive）
            //   与基础周期补数后的重算（updateSourceKlineBatch）覆盖，这里只兜两种漏网：
            //   落后（最近一根已收盘的没算出来）、比基础周期浅（基础周期补了更早的，汇总没跟上）
            foreach (MarketHelper::klinePlan($this->exchange)['derived'] as $periodSec => $baseSec) {
                if ($this->budgetExpired()) { $stopReason = 'budget'; break 2; }
                $wrote = $this->rebuildDerivedIfBehind($src, $periodSec, $baseSec, $stats, $nowMs);
                if ($wrote > 0) {
                    $byLayer['derive']++;
                    if (++$fixed >= $maxFixesPerTick) { $stopReason = 'quota'; break 2; }
                }
            }
        }

        if ($stopReason !== '') {
            $this->advanceKlineCursor($ordered, $scanned);
        } else {
            $this->klineSourceCursor = '';
        }

        if ($stopReason === 'budget') $this->noteKlineBudgetBound($fixed, $maxFixesPerTick);

        if (!Worker::$daemonize) {
            $output->writeln(sprintf(
                '<comment>[Kline-Backfill][kline]</comment> sources=%d scanned=%d fixed=%d (tail=%d pair_replay=%d window=%d derive=%d) cursor=%s%s',
                count($sources), $scanned, $fixed,
                $byLayer['tail'], $byLayer['pair_replay'], $byLayer['window'], $byLayer['derive'],
                $this->klineSourceCursor,
                match ($stopReason) {
                    'quota'  => ' 配额用尽(max_fixes_per_tick)，下轮继续',
                    'budget' => ' 墙钟预算用尽(tick_budget_sec)，配额未跑满，下轮继续',
                    default  => '',
                }
            ));
        }
    }

    /**
     * @var array<string,string> rebuildDerivedIfBehind() 上次重算时的状态签名：「source_key|周期」=> 签名。
     * 基础周期中间有洞时，那一根汇总算不出来，「落后 / 偏浅」的判定会一直成立；状态没变就不再重算，
     * 免得每轮白查一遍库。基础周期一有变化（补了洞、来了新的），签名就变，会再试
     */
    private array $deriveTried = [];

    /**
     * 第 5 层：汇总周期落后或比基础周期浅时，按库里的基础周期重算缺的那段
     *
     * @param array<string,array{0:int,1:int,2:int,3:int}> $lastTsMap 见 klineStats()
     * @return int 写入的根数
     */
    private function rebuildDerivedIfBehind(array $src, int $periodSec, int $baseSec, array $lastTsMap, int $nowMs): int
    {
        $sourceKey = (string)$src['source_key'];
        $periodMs  = $periodSec * 1000;
        [$lastTs, , $oldestTs] = $lastTsMap[$sourceKey . '|' . $periodSec] ?? [0, 0, 0];
        [$baseLast, $baseCount, $baseOldest] = $lastTsMap[$sourceKey . '|' . $baseSec] ?? [0, 0, 0];
        if ($baseLast <= 0) return 0;   // 基础周期还一根都没有，等它先补

        // 基础 K 线齐全的第一根汇总 K 线从哪开始；最近一根已收盘的是哪根
        $firstFull = intdiv($baseOldest + $periodMs - 1, $periodMs) * $periodMs;
        $lastDone  = intdiv($nowMs, $periodMs) * $periodMs - $periodMs;

        $behind  = $lastTs < $lastDone;
        $shallow = $oldestTs <= 0 || $oldestTs > $firstFull;
        if (!$behind && !$shallow) return 0;

        $sig = "{$lastTs}|{$oldestTs}|{$baseLast}|{$baseCount}|{$baseOldest}";
        if (($this->deriveTried[$sourceKey . '|' . $periodSec] ?? '') === $sig) return 0;
        $this->deriveTried[$sourceKey . '|' . $periodSec] = $sig;

        $from = $shallow ? $firstFull : $lastTs;
        $wrote = MarketService::rebuildDerivedKlines(
            $this->exchange, (int)$src['type'], (string)$src['symbol'], $periodSec, $from, $nowMs
        );
        if ($wrote > 0 && !Worker::$daemonize) {
            fwrite(STDOUT, sprintf("[Kline-Backfill][derive] %s[%d] 由 %d 秒线重算 %d 根\n", $sourceKey, $periodSec, $baseSec, $wrote));
        }
        return $wrote;
    }

    /** K 线回补撞墙钟预算收工时记一条 warning（10 分钟节流），带本轮实际修了几个，供调 tick_budget_sec */
    private function noteKlineBudgetBound(int $fixed, int $quota): void
    {
        static $lastAt = 0;
        $now = time();
        if ($now - $lastAt < 600) return;
        $lastAt = $now;

        $budget = (float)($this->cfg('kline_backfill')['tick_budget_sec'] ?? 3);
        business_log('market_kline_backfill', sprintf(
            '[kline][%s] 本轮被墙钟预算收工：只修了 %d 个缺口，配额 %d 未跑满（tick_budget_sec=%s）。'
            . '轮转周期由预算而非配额决定，要补更快得调 tick_budget_sec',
            $this->exchange->value, $fixed, $quota, $budget
        ), 'warning');
    }

    /**
     * 每个 (source_key, period) 的最新一根、总根数、最早一根、窗口内根数，一次聚合查询取回
     *
     * @param string[] $sourceKeys 本进程负责的源
     * @param array<int,int> $windowStarts 周期(秒) => 窗口起点(毫秒)；不在里面的周期（汇总周期）窗口内根数记 0
     * @return array<string,array{0:int,1:int,2:int,3:int}> "{source_key}|{period}" => [最新ts, 根数, 最早ts, 窗口内根数]，ts 均为毫秒
     */
    private function klineStats(array $sourceKeys, array $windowStarts): array
    {
        if ($sourceKeys === []) return [];

        $case = 'CASE `period`';
        foreach ($windowStarts as $periodSec => $startMs) $case .= " WHEN '{$periodSec}' THEN " . (int)$startMs;
        $case .= ' ELSE ' . PHP_INT_MAX . ' END';

        $rows = Db::name('market_source_kline')
            ->whereIn('source_key', $sourceKeys)
            ->field("source_key, period, MAX(ts) AS last_ts, MIN(ts) AS first_ts, COUNT(*) AS cnt, SUM(ts >= ({$case})) AS win_cnt")
            ->group('source_key, period')
            ->select()->toArray();

        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['source_key'] . '|' . (string)$r['period']] = [
                (int)$r['last_ts'], (int)$r['cnt'], (int)$r['first_ts'], (int)$r['win_cnt'],
            ];
        }
        return $out;
    }

    /** 记下本轮扫到哪，下一轮从下一个源接着扫 */
    private function advanceKlineCursor(array $ordered, int $scanned): void
    {
        $this->klineSourceCursor = (string)($ordered[$scanned]['source_key']
            ?? ($ordered[0]['source_key'] ?? ''));
    }

    /** 取数失败后的退避(秒)：第 n 次连续失败后等 min(FAIL_BACKOFF_MAX, FAIL_BACKOFF_BASE × 2^(n-1)) */
    private const FAIL_BACKOFF_BASE = 60;
    private const FAIL_BACKOFF_MAX  = 1800;

    /** @var array<string,array{0:int,1:int}> 「source_key|周期」=> [连续失败次数, 退避到期时刻] */
    private array $fetchFailAt = [];

    /**
     * 这个 (源,周期) 是否还在取数失败的退避期内。
     * 没有退避的话，持续的错误（symbol 下架、填错）每轮都会再打一次，还会凑满 httpGetJson 的连续失败熔断，
     * 把同一家的价格兜底一起停掉
     */
    private function fetchBackoff(string $key): bool
    {
        return ($this->fetchFailAt[$key][1] ?? 0) > time();
    }

    /** 记一次取数结果：成功清零，失败退避加倍 */
    private function noteFetch(string $key, bool $ok): void
    {
        if ($ok) { unset($this->fetchFailAt[$key]); return; }
        $n = ($this->fetchFailAt[$key][0] ?? 0) + 1;
        $this->fetchFailAt[$key] = [$n, time() + min(self::FAIL_BACKOFF_MAX, self::FAIL_BACKOFF_BASE * 2 ** ($n - 1))];
    }

    /** 尾部取数为空后的退避(秒)：第 n 次连续为空后等 min(TAIL_EMPTY_MAX, TAIL_EMPTY_BASE × 2^(n-1)) */
    private const TAIL_EMPTY_BASE = 600;
    private const TAIL_EMPTY_MAX  = 14400;

    /** @var array<string,array{0:int,1:int,2:int}> 「source_key|周期」=> [当时的最新一根 ts, 连续为空次数, 退避到期时刻] */
    private array $tailEmptyAt = [];

    /**
     * 尾部刚问过、交易所也没有更新的：退避期内别再问。
     * 最新一根变了（WS 恢复、开市了）立刻作废 —— 所以休市日历漏了节假日时，长假里每个周期只问十几次，开市后照样马上补
     */
    private function tailBackoff(string $key, int $lastTs): bool
    {
        $hit = $this->tailEmptyAt[$key] ?? null;
        return $hit !== null && $hit[0] === $lastTs && $hit[2] > time();
    }

    private function noteTailEmpty(string $key, int $lastTs): void
    {
        $hit = $this->tailEmptyAt[$key] ?? null;
        $n = $hit !== null && $hit[0] === $lastTs ? $hit[1] + 1 : 1;
        $this->tailEmptyAt[$key] = [$lastTs, $n, time() + min(self::TAIL_EMPTY_MAX, self::TAIL_EMPTY_BASE * 2 ** ($n - 1))];
    }

    /**
     * 窗口 [windowStart, upper] 里还值得去要的缺口：扣掉 KlineFetchMemory 记过空的；
     * 交易所够不着的（火币现货早于最近 2000 根）直接记空、不发请求
     *
     * @param int $reachFrom MarketRestAbstract::klineReachFromMs()，0 = 不限
     * @return array{0:array<int,array{0:int,1:int}>,1:array<int,true>}|null [待补的段(新的在前), 窗口内全部缺失 ts]；null = 没有要补的
     */
    private function pendingWindowGaps(string $sourceKey, string $period, int $periodMs, int $windowStart, int $upper, int $reachFrom): ?array
    {
        $have = array_flip(array_map('intval', Db::name('market_source_kline')
            ->where('source_key', $sourceKey)->where('period', $period)
            ->where('ts', '>=', $windowStart)->where('ts', '<=', $upper)
            ->column('ts')));

        $missing = [];
        for ($ts = $windowStart; $ts <= $upper; $ts += $periodMs) {
            if (!isset($have[$ts])) $missing[$ts] = true;
        }

        if ($reachFrom > $windowStart) {
            foreach (KlineFetchMemory::holesOf($missing, $periodMs) as [$start, $end]) {
                if ($start >= $reachFrom) break;
                KlineFetchMemory::markGapEmpty($sourceKey, $period, $start, min($end, $reachFrom - $periodMs));
            }
        }

        $holes = KlineFetchMemory::holesOf(
            KlineFetchMemory::withoutKnownEmpty($sourceKey, $period, $periodMs, $missing), $periodMs
        );
        return $holes === [] ? null : [array_reverse($holes), $missing];
    }

    /**
     * 第 3 层：补最新的那一段，请求窗口右端对齐它的终点，顺带覆盖窗口内更早的段（一次请求补几段）。
     * 只写缺的那几根 —— 已有的行不重写：pair 层按极值累计，没必要重写。
     * 覆盖范围内取数成功却仍然缺着的，记进 KlineFetchMemory，之后不再为它发请求
     *
     * @param array<int,array{0:int,1:int}> $holes pendingWindowGaps() 给的待补段，新的在前
     * @param array<int,true> $missing 窗口内全部缺失 ts，写入白名单
     * @return bool false = 取数失败
     */
    private function fillKlineHole(array $src, string $period, int $periodMs, array $holes, array $missing, int $limit, int $reachFrom, Output $output): bool
    {
        $sourceKey   = (string)$src['source_key'];
        $windowStart = $holes[0][1] - ($limit - 1) * $periodMs;
        $fromMs      = $holes[0][1];
        foreach ($holes as [$start, $end]) {
            if ($end < $windowStart) break;
            $fromMs = max($start, $windowStart);
        }
        $fromMs = max($fromMs, $reachFrom);

        $candles = $this->fetchKlines($src, $period, $fromMs, $limit, $output);
        if ($candles === null) {
            business_log('market_kline_backfill', "[{$sourceKey}][{$period}] window fetch failed, from={$fromMs}", 'error');
            return false;
        }

        $write = array_values(array_filter($candles, fn($c) => isset($missing[(int)($c['id'] ?? 0) * 1000])));
        if ($write !== []) {
            MarketService::updateSourceKlineBatch($this->exchange, (int)$src['type'], (string)$src['symbol'], $period, $write);
        }

        // 记空之前先核对响应是不是真的从起点给的：交易所若忽略了起点、返回的是最近 N 根，
        // 「覆盖范围」的推算就不成立，照记会把起点到现在没返回的根全记成空、7 天不再问。
        // 合法的两种：有一根落在请求窗口内；或第一根正好是这一段右边紧挨着的那根
        // （币安对早于上市的起点会从首根开始给，此时首根就是库里已有的最老一根）
        $requestEnd = $fromMs + ($limit - 1) * $periodMs;
        $firstTs    = $candles === [] ? null : (int)($candles[0]['id'] ?? 0) * 1000;
        $trusted    = $firstTs === null || $firstTs <= $requestEnd || $firstTs === $holes[0][1] + $periodMs;

        $coveredTo = MarketRestAbstract::klineCoveredUntil($fromMs, (int)$period, $limit, $candles);
        $marked = $trusted ? KlineFetchMemory::markUncovered($sourceKey, $period, $periodMs, $holes, $fromMs, $coveredTo, $candles) : 0;
        if (!$trusted) {
            business_log('market_kline_backfill', sprintf('[%s][%s] 响应不像是从起点 %s 给的（第一根 %s），这次不记空',
                $sourceKey, $period, date('Y-m-d H:i', intdiv($fromMs, 1000)), date('Y-m-d H:i', intdiv($firstTs, 1000))), 'warning');
        }

        business_log('market_kline_backfill', sprintf('[%s][%s] window filled %d, confirmed empty %d segment(s), request %s ~ %s',
            $sourceKey, $period, count($write), $marked,
            date('Y-m-d H:i', intdiv($fromMs, 1000)), date('Y-m-d H:i', intdiv($coveredTo, 1000))), $write === [] ? 'info' : 'warning');
        if (!Worker::$daemonize) {
            $output->writeln(sprintf('<info>[Kline-Backfill][window]</info> %s[%s] 补上 %d 根，确认交易所没有 %d 段，请求 %s ~ %s',
                $sourceKey, $period, count($write), $marked,
                date('Y-m-d H:i', intdiv($fromMs, 1000)), date('Y-m-d H:i', intdiv($coveredTo, 1000))));
        }
        return true;
    }

    /**
     * 用已落库的 market_source_kline 重放 pair 层 K 线的滞后
     *
     * ⚠️ 只重放已收盘周期：未收盘那根还在被实时链路改写，同写会让 high/low 短暂回退。
     *
     * @return bool 是否重放了一个 pair（重放一个就返回）
     */
    private function replayPairKlineGap(string $sourceKey, string $period, int $sourceLastTs, int $periodMs, int $nowMs, Output $output, array $pairs, array $pairLastTsMap): bool
    {
        if ($pairs === []) return false;

        $currentPeriodStartTs = intdiv($nowMs, $periodMs) * $periodMs;
        $replayUpperTs = min($sourceLastTs, $currentPeriodStartTs - $periodMs);
        if ($replayUpperTs <= 0) return false;

        foreach ($pairs as $pair) {
            $pairId = (int)$pair['id'];
            // 取自轮首快照；补完一个就 return，不会读到自己刚写的
            $pairLastTs = $pairLastTsMap[$pairId . '|' . $period] ?? 0;
            if ($pairLastTs >= $replayUpperTs) continue;   // 已经追平

            if (!$pairLastTs) {
                // 从零播种最近一个保留量的根数：实时链路不会给 pair_last_ts=0 的 pair 补历史
                $seedFrom = $replayUpperTs - ($this->pairSeedCandles($period) - 1) * $periodMs;
                $rows = Db::name('market_source_kline')
                    ->where('source_key', $sourceKey)->where('period', $period)
                    ->where('ts', '>=', max(0, $seedFrom))->where('ts', '<=', $replayUpperTs)
                    ->order('ts asc')->select()->toArray();
                if (empty($rows)) continue;
                $this->pairSyncStat['seeded']++;
            } else {
                $rows = Db::name('market_source_kline')
                    ->where('source_key', $sourceKey)->where('period', $period)
                    ->where('ts', '>', $pairLastTs)->where('ts', '<=', $replayUpperTs)
                    ->order('ts asc')->select()->toArray();
                if (empty($rows)) continue;
                $this->pairSyncStat['replayed']++;
            }

            MarketService::batchSyncPairKlines($pair, $period, $rows);

            business_log(
                'market_kline_backfill',
                sprintf('[pair_id=%d][%s] %s %d candles from market_source_kline, %s ~ %d',
                    $pairId, $period, $pairLastTs ? 'replayed' : 'seeded', count($rows),
                    $pairLastTs ?: 'seed', $replayUpperTs),
                'warning'
            );
            if (!Worker::$daemonize) {
                $output->writeln(sprintf('<info>[Kline-Backfill][kline]</info> pair_id=%d[%s] %s %d 根 (pair K线补齐)',
                    $pairId, $period, $pairLastTs ? '重放' : '播种', count($rows)));
            }
            return true;
        }

        return false;
    }

    /**
     * 第 1 层：拉取尾部缺口 (lastTs, expectedTs] 的历史K线并批量落库
     *
     * @param array $src 行情源行
     * @param string $period
     * @param int $lastTs 缺口前最后一根的 ts(毫秒)
     * @param int $expectedTs 缺口终点(毫秒)，也是写入上界
     * @param int $limit 单次取数根数，按交易所上限拿满
     * @param Output $output
     * @return int|null null = 取数失败，该重试；否则为写入根数（0 = 交易所这一段也没有）
     */
    private function klineBackfillFix(array $src, string $period, int $lastTs, int $expectedTs, int $limit, Output $output): ?int
    {
        $sourceKey  = (string)$src['source_key'];
        $periodMs   = (int)$period * 1000;
        $sinceMs    = $lastTs + $periodMs;

        $candles = $this->fetchKlines($src, $period, $sinceMs, $limit, $output);

        // 失败与「这一段确实没有数据」必须分开，否则一次 429 会被当成交易所停着
        if ($candles === null) {
            business_log('market_kline_backfill', "[{$sourceKey}][{$period}] fetch failed, lastTs={$lastTs}, expectedTs={$expectedTs}", 'error');
            if (!Worker::$daemonize) {
                $output->writeln("<error>[Kline-Backfill][kline]</error> {$sourceKey}[{$period}] 取数失败 gap "
                    . date('H:i:s', intdiv($lastTs, 1000)) . ' ~ ' . date('H:i:s', intdiv($expectedTs, 1000)));
            }
            return null;
        }

        $filteredCandles = array_values(array_filter($candles, function ($candle) use ($sinceMs, $expectedTs) {
            $candleTsMs = (int)($candle['id'] ?? 0) * 1000;
            return $candleTsMs >= $sinceMs && $candleTsMs <= $expectedTs;
        }));

        if ($filteredCandles === []) {
            business_log('market_kline_backfill', "[{$sourceKey}][{$period}] fetch empty, lastTs={$lastTs}, expectedTs={$expectedTs}", 'warning');
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Kline-Backfill][kline]</comment> {$sourceKey}[{$period}] 取数为空 gap "
                    . date('H:i:s', intdiv($lastTs, 1000)) . ' ~ ' . date('H:i:s', intdiv($expectedTs, 1000)));
            }
            return 0;
        }

        $filled = MarketService::updateSourceKlineBatch($this->exchange, (int)$src['type'], (string)$src['symbol'], $period, $filteredCandles);

        business_log(
            'market_kline_backfill',
            "[{$sourceKey}][{$period}] filled {$filled}/" . count($candles) . " candles, gap {$lastTs} ~ {$expectedTs}",
            'warning'
        );
        if (!Worker::$daemonize) {
            $output->writeln(sprintf(
                '<info>[Kline-Backfill][kline]</info> %s[%s] filled=%d/%d (区间过滤后 %d) gap %s ~ %s',
                $sourceKey, $period, $filled, count($candles), count($filteredCandles),
                date('H:i:s', intdiv($lastTs, 1000)), date('H:i:s', intdiv($expectedTs, 1000))
            ));
        }

        return count($filteredCandles);
    }
}
