<?php

declare(strict_types=1);

namespace app\lib;

/**
 * 常驻进程的通用健康判定，「行情链路监控」（采集 / 落库等进程）与「分发监控」（PHP 分发机）两页共用。
 *
 * 只负责所有进程都适用的三条：活没活着、指标上报坏没坏、挂着的定时器还转不转。
 * 各类进程自己的判据（分发机看丢包与发送缓冲、采集器看交易对数）由各页在 common() 放行之后接着判。
 *
 * 判定结果统一是 {level, text, reason}：level 为 crit / warn / info / ok，
 * 颜色经 MarketMetrics::levelColor() / levelBadge() 映射。
 * 判据按**严重度**排序，命中即返回，所以 reason 永远是当下最要紧的那一条。
 */
final class WorkerHealth
{
    public static function crit(string $reason): array
    {
        return ['level' => 'crit', 'text' => '异常', 'reason' => $reason];
    }

    public static function warn(string $reason): array
    {
        return ['level' => 'warn', 'text' => '注意', 'reason' => $reason];
    }

    public static function info(string $reason): array
    {
        return ['level' => 'info', 'text' => '空闲', 'reason' => $reason];
    }

    public static function ok(string $reason): array
    {
        return ['level' => 'ok', 'text' => '正常', 'reason' => $reason];
    }

    /**
     * 通用判定：命中任何一条异常就返回判定，全部通过返回 null 交给调用方继续判。
     *
     * @param array      $metrics   进程指标 HASH（market:metrics:worker:{name}），从未上报为 []
     * @param bool       $alive     心跳键 heartbeat:cmd:{name} 是否存在
     * @param float|null $metricAge 指标距今秒数，null = 从未上报
     */
    public static function common(array $metrics, bool $alive, ?float $metricAge): ?array
    {
        // ── 先判"还活着吗" ────────────────────────────────────────────────
        // 这里有两个**互相独立**的信号，必须一起看，否则会把结论下反：
        //   heartbeat:cmd:{name}  由 CommandHeartBeatService 自己的 5 秒定时器写
        //   update_ts             由各进程的 10 秒 stat 定时器写
        // 两个定时器互不相干。所以「心跳在 + 指标停更」证明的恰恰是**事件循环还在转**
        // （否则心跳也写不出去），只是 stat 定时器那一条路断了。
        //
        // ⚠️ 这一段原来直接把"指标停更"判成"事件循环被阻塞"，既没看心跳、也没给别的可能性。
        // 而 Workerman 会**吞掉定时器回调里的异常并继续调度**（Timer::tick 与
        // Events\Select::safeCall 都是 catch 之后 echo），异常若发生在 putWorker() 之前，
        // 指标就永久冻结而进程毫发无损、行情照常流。于是最常见的真实情况是
        // 「观测坏了」，却被报成「进程卡死」—— 把人引去查一个根本不存在的阻塞。
        if (!$alive && $metricAge === null) {
            return self::crit('进程未运行');
        }

        // 进程自己报上来的上报异常，优先于任何基于 metric_age 的推测：这是实据，不是猜测
        $statErr = trim((string)($metrics['stat_error'] ?? ''));
        if ($statErr !== '') {
            $at = trim((string)($metrics['stat_error_at'] ?? ''));
            // 报错早于本次启动 = 上一条命的残留，说的不是现在。必须讲清楚，
            // 否则"改完代码重启了还在报同样的错"会让人以为没修好（这事已经发生过一次）
            if (self::statErrorIsStale($metrics)) {
                return self::warn(sprintf(
                    '这条错误发生在本次启动之前（%s），是重启前的残留、不是当前故障；'
                    . '下一轮上报成功后会自动清掉。当前进程已运行 %s',
                    $at, self::duration((int)($metrics['uptime_sec'] ?? 0))
                ));
            }
            return self::crit(sprintf(
                '指标上报本身出错（%s）：%s —— 进程仍在运行，先修观测而不是查阻塞',
                $at !== '' ? $at : '时间未知', $statErr
            ));
        }

        if ($metricAge !== null && $metricAge > 60) {
            return $alive
                ? self::crit("指标已 {$metricAge}s 未更新，但心跳仍在（心跳是另一个定时器）——"
                    . "说明事件循环没有被阻塞，是 stat 定时器抛了异常被 Workerman 吞掉。"
                    . "对照「数据组新鲜度」：若它还在走，就更确定是观测坏了而不是进程卡了；"
                    . "日志看 runtime/log 的 *_market_stat_guard.log")
                : self::crit("指标已 {$metricAge}s 未更新，心跳也已过期 —— 进程多半已经不在了，"
                    . "而不是卡死；先 ps 确认，再看是谁把它拉起来的");
        }
        if (!$alive) {
            return self::warn('心跳已过期，但仍在上报指标；确认是否为刚重启或心跳注册异常');
        }

        // ── 任务级判活：进程活着 ≠ 它挂的定时器还在转 ──────────────────────────
        //
        // Workerman 会**吞掉定时器回调里的异常并继续调度**，所以"进程心跳正常、
        // 某个任务早就不跑了"是完全可能的，而且从进程视角一点看不出来。
        // tasks 由 EventLoopBusyTrait::timed() 顺手记下（桶名 => {last, runs, ms}），
        // 覆盖每一个被 timed() 包过的 Timer，不需要各处新增埋点。
        //
        // 阈值自校准：不写死每个任务的周期（写死就得跟着代码同步，迟早不同步），
        // 用 uptime/runs 估平均间隔，超过 3 倍且至少 60 秒才算停。
        // 3 倍是给调度抖动与"这一轮恰好没活干"留的余量。
        // ⚠️ 前提是 tasks 里只有**周期**任务：「有事件才跑」的任务长时间没事件就会被误判成停了
        // （Go 分发机的 dispatch_event 就是这样被误报的，它因此不再走这里，归 Go 分发监控页）
        $dead = self::deadTasks($metrics);
        if ($dead) {
            return self::crit('以下任务的定时器已停：' . implode('、', $dead)
                . ' —— 进程还活着，多半是回调抛了异常被 Workerman 吞掉，'
                . '查 runtime/log 里对应业务的日志');
        }
        return null;
    }

    /**
     * 超过自身平均周期 3 倍（至少 60 秒）没再跑的任务，判据见 common() 的任务级判活。
     *
     * @return list<string> 每项形如「snapshot_tick(停了5分，平均0s一轮)」，没有停的任务返回 []
     */
    private static function deadTasks(array $metrics): array
    {
        $tasks  = json_decode((string)($metrics['tasks'] ?? ''), true);
        $uptime = (int)($metrics['uptime_sec'] ?? 0);
        if (!is_array($tasks) || !$tasks || $uptime <= 0) return [];

        $nowMs = (int)(microtime(true) * 1000);
        $dead  = [];
        foreach ($tasks as $bucket => $t) {
            $last = (int)($t['last'] ?? 0);
            $runs = max(1, (int)($t['runs'] ?? 0));
            if ($last <= 0) continue;
            $avgSec  = $uptime / $runs;
            $limitMs = (int)(max(60, $avgSec * 3) * 1000);
            $idleMs  = $nowMs - $last;
            if ($idleMs > $limitMs) {
                $dead[] = sprintf('%s(停了%s，平均%.0fs一轮)', $bucket, self::duration((int)($idleMs / 1000)), $avgSec);
            }
        }
        return $dead;
    }

    /**
     * stat_error 是不是上一条命留下的：报错时刻早于本次进程启动时刻。
     *
     * 会需要这个判断，是因为 stat_error 存在 Redis 的 worker hash 里、活得比进程久 ——
     * 修好代码重启之后，如果清除逻辑没覆盖到"新进程第一次成功"这种情况，卡片就会挂着重启前的旧错误，
     * 让人以为没修好。清除逻辑已经修了（见 MarketMetrics::guardStat），这里是第二道保险。
     */
    public static function statErrorIsStale(array $metrics): bool
    {
        $at     = trim((string)($metrics['stat_error_at'] ?? ''));
        $bootTs = (int)($metrics['boot_ts'] ?? 0);
        if ($at === '' || $bootTs <= 0) return false;
        $errTs = strtotime($at);
        return $errTs !== false && $errTs * 1000 < $bootTs;
    }

    /** 秒 -> "3天2小时" / "45分" / "12秒"，只保留两个量级，够看就行 */
    public static function duration(int $sec): string
    {
        if ($sec < 60) return "{$sec}秒";
        $m = intdiv($sec, 60);
        if ($m < 60) return "{$m}分";
        $h = intdiv($m, 60);
        $m %= 60;
        if ($h < 24) return $m > 0 ? "{$h}小时{$m}分" : "{$h}小时";
        $d = intdiv($h, 24);
        $h %= 24;
        return $h > 0 ? "{$d}天{$h}小时" : "{$d}天";
    }
}
