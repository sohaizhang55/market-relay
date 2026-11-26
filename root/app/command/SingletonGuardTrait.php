<?php

declare(strict_types=1);

namespace app\command;

use think\console\Output;
use Workerman\Worker;
use app\lib\CommandHeartBeatService;

/**
 * 常驻进程单例守卫：已经有一套在跑就拒绝再起一套。
 *
 * ── 为什么需要它 ──────────────────────────────────────────────────────────
 *
 * Workerman 自带单例保护（start 时读 pid 文件、发现 master 还活着就拒绝），
 * 但那道保护的前提是**pid 文件指得对**。而 app\lib\WorkerService::init() 里
 *
 *     Worker::$pidFile = runtime_path() . $name . '.pid';
 *
 * 是每次 start 覆盖写的。于是 pid 文件一旦对不上（被清过、或某次从别的位置启动过）：
 *
 *     start #1 → pidfile = PID_1
 *     start #2 → pidfile = PID_2      ← PID_1 就此永久孤儿化，脚本再也够不着
 *     stop     → 只杀 PID_2
 *
 * 现网因此叠出过 **4 个合约撮合引擎同时撮同一个订单簿、4 个结算引擎同时结同一批仓位**，
 * 而三层观测全部说"正常"：心跳 key 是 setex 覆盖写的、体检脚本的 grep 模式认的是
 * `market:subscribe` 而 Workerman 早把进程名改成了 `market_relay_MarketSubscriber`。
 *
 * ── 判据：不是"有没有别人在跑"，而是"有没有我管不着的别人在跑" ──────────────
 *
 * 这个区别是整个守卫的关键。`restart` = stop + start，而本守卫跑在 Worker::runAll()
 * **之前** —— 那一刻旧 master 一定还活着。若判据是"有没有别人"，每一次正常重启都会被
 * 自己拒掉，那比重复进程更糟。
 *
 * 所以判据是**它归不归 pid 文件管**：
 *   活实例的 master == pid 文件里的 PID  → restart 马上就会停掉它，放行
 *   活实例的 master != pid 文件里的 PID  → 孤儿，脚本永远够不着，拒绝
 *   pid 文件不存在而有活实例             → 更要拒绝：连管都管不着，绝不能再叠一套
 *
 * 最后那一条正是现网那次的形态。
 */
trait SingletonGuardTrait
{
    /**
     * 「已有实例在跑，本次启动被拒绝」的退出码。
     *
     * 与 DisabledGuardTrait::EXIT_DISABLED(3) 并列，让 deploy/lib/run_daemon.sh
     * 把三种结果分开报：0 = 起来了，3 = 按配置未启用（已确保没在跑），4 = 重复（**服务还在跑**）。
     * 4 和 3 的处置完全不同：3 可以不管，4 必须去看那个孤儿。
     */
    public const EXIT_DUPLICATE = 4;

    /**
     * @param string $workerName 与 CommandHeartBeatService::start() 收到的名字一致
     *                           （各命令都传 $worker->name），否则查的是别人的注册表
     * @param string $action     当前动作。start / restart 两道都查；stop 只查第一道（master 属于别的用户），
     *                           孤儿那道不能拦 stop，否则会把「停止」一并拒掉；status / reload 不守卫
     */
    protected function guardSingleton(string $workerName, string $action, Output $output): void
    {
        if (!in_array($action, ['start', 'restart', 'stop'], true)) return;

        // ── 第一道：pid 文件管的那个 master 在，但属于别的用户 ──
        // Workerman 的 stop 用 posix_kill($master, 0) 判活，没权限时同样返回 false，
        // 于是它打印 "not run"：stop 什么也没停，restart 则直接再起一套。
        // stop 也要拦 —— 不拦它照样「成功」退出，人会以为已经停了
        $managed = $this->pidFromPidFile();
        if ($managed > 0 && function_exists('posix_kill') && !@posix_kill($managed, 0)) {
            $owner = CommandHeartBeatService::pidOwner($managed) ?: '其他用户';
            $this->refuseDuplicate(sprintf(
                '[单例守卫] %s 的 master pid=%d 属于 %s，当前用户 %s 无权停止它，本次 %s 已拒绝（%s）。',
                $workerName, $managed, $owner, $this->currentUser(), $action,
                $action === 'stop' ? '继续执行 Workerman 会误报"没在跑"，实际什么也没停' : '继续执行会再起一套、两套并行'
            ), "处置：用 {$owner} 执行 kill -SIGINT {$managed}，确认 ps 清空后再用 deploy 启动。行情进程一律用 deploy 起，不要用 root / sudo。", $output);
        }
        if ($action === 'stop') return;

        // ── 第二道：有活实例不归 pid 文件管（孤儿） ──
        $orphans = $this->findOrphanInstances($workerName);
        if (empty($orphans)) return;

        $list = implode(', ', array_map(static function ($o) {
            $owner = CommandHeartBeatService::pidOwner($o['pid']);
            return 'pid=' . $o['pid'] . ($owner !== '' ? "({$owner})" : '');
        }, $orphans));
        $this->refuseDuplicate(sprintf(
            '[单例守卫] %s 已有 %d 个本机实例在跑，且它们不归本命令的 pid 文件管（%s）——'
            . '再起一套会变成多实例并行。本次启动已拒绝。',
            $workerName, count($orphans), $list
        ), '处置：ps -eo user,pid,ppid,lstart,args | grep \'WorkerMan: master process\''
            . ' 找到对应 master 后用它的属主 kill -SIGINT，确认 ps 清空再用 deploy 重启。', $output);
    }

    /** 当前进程的用户名，只用于提示文案 */
    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && isset($pw['name'])) return (string)$pw['name'];
        }
        return (string)(get_current_user() ?: '?');
    }

    /**
     * 打印、留痕并以 EXIT_DUPLICATE 退出。
     *
     * @param string $msg  错误正文（进 ops_alert / 事件流水）
     * @param string $hint 处置提示（只打印到控制台）
     */
    private function refuseDuplicate(string $msg, string $hint, Output $output): void
    {
        $output->writeln("<error>{$msg}</error>");
        $output->writeln("<comment>{$hint}</comment>");

        // 两条留痕：ops_alert 会走 Telegram / 站内通知，event 进监控页的事件流水。
        // 只打印到控制台是不够的 —— run_daemon.sh 是把输出重定向到临时文件再 cat 的，
        // 而真正需要知道这件事的人未必在看那个终端
        try {
            business_log('ops_alert', $msg, 'error');
            \app\lib\MarketMetrics::event('duplicate_instance', $msg);
        } catch (\Throwable $e) {
            // 留痕失败不能反过来把守卫本身带崩：这里已经在拒绝路径上了
        }

        exit(self::EXIT_DUPLICATE);
    }

    /**
     * 本机上「活着、但不归当前 pid 文件管」的实例。
     *
     * @return array<int,array{pid:int,ts:int}>
     */
    private function findOrphanInstances(string $workerName): array
    {
        // liveInstances() 自己会剪掉过期的、以及本机上 pid 已死的 field，
        // 所以这里拿到的都是真活着的
        $live = CommandHeartBeatService::liveInstances($workerName);
        if (empty($live)) return [];

        // ⚠️ 判据依赖 /proc 取父进程号（注册表记的是 worker 的 pid，pid 文件里是 master 的）。
        // 读不到 /proc 就分不出「归 pid 文件管的 worker」和「孤儿」——
        // 那样每一次正常 restart 都会被判成重复而拒掉，比重复进程更糟。
        // 所以这种平台上直接放弃守卫：**宁可漏判，不可误伤**。
        if (!@is_dir('/proc')) return [];

        $host    = CommandHeartBeatService::hostName();
        $managed = $this->pidFromPidFile();

        $orphans = [];
        foreach ($live as $inst) {
            if ($inst['host'] !== $host) continue;   // 别的机器上的实例不归本机管，也验不了它的 pid

            // 注册表记的是 **worker** 的 pid，而 pid 文件里是 **master** 的。
            // 所以两头都要认：自己是 master，或自己的父进程是 master
            if ($managed > 0 && ($inst['pid'] === $managed || $this->ppidOf($inst['pid']) === $managed)) {
                continue;   // restart 的 stop 阶段马上会收拾它，不算孤儿
            }
            $orphans[] = ['pid' => $inst['pid'], 'ts' => $inst['ts']];
        }
        return $orphans;
    }

    /**
     * 当前命令 pid 文件里记的 master PID；文件不存在、内容不是数字、或那个进程已死都返回 0。
     *
     * 返回 0 的语义是「没有任何我管得着的实例」—— 此时只要有活实例就一律判孤儿，
     * 这正是现网那次的形态：pid 文件对不上，于是每次启动都叠一套上去。
     */
    private function pidFromPidFile(): int
    {
        $file = Worker::$pidFile ?? '';
        if ($file === '' || !@is_file($file)) return 0;

        $pid = (int)trim((string)@file_get_contents($file));
        if ($pid <= 0) return 0;
        // 记着的那个已经死了。不能直接看 posix_kill 返回值：属于别的用户时它也返回 false，
        // 会把「活着但我停不掉」误当成「死了」，见 CommandHeartBeatService::pidExists()
        if (!CommandHeartBeatService::pidExists($pid)) return 0;

        return $pid;
    }

    /**
     * 读 /proc/{pid}/stat 拿父进程号。非 Linux 或读不到返回 0。
     *
     * ⚠️ 必须从**最后一个右括号**之后开始切：stat 的第 2 个字段是进程名，
     * 而 Workerman 把进程名改成了 "WorkerMan: worker process  market_relay_Xxx"，
     * 里面既有空格也可能有括号。按空格 explode 会取错列，而取错列的后果是
     * 把正常重启误判成孤儿 —— 恰恰是这个守卫最不能犯的错。
     */
    private function ppidOf(int $pid): int
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        return is_string($stat) ? self::parsePpid($stat) : 0;
    }

    /**
     * 从 /proc/{pid}/stat 的内容里切出父进程号。取不到返回 0。
     *
     * 读与解析分开，是因为**解析才是容易错的那一半**，而它需要能被独立验证：
     * stat 的第 2 个字段是进程名且带一对圆括号，而 Workerman 把进程名改成了
     * "WorkerMan: worker process  market_relay_Xxx" —— 里面既有空格、也可能有括号。
     * 按空格 explode 会取错列，取错列就会把正常 restart 误判成孤儿，
     * 而那恰恰是这个守卫最不能犯的错。所以固定从**最后一个右括号**之后开始切。
     */
    public static function parsePpid(string $stat): int
    {
        $close = strrpos($stat, ')');
        if ($close === false) return 0;

        // 右括号之后依次是: state ppid ...
        $rest = preg_split('/\s+/', trim(substr($stat, $close + 1)));
        return isset($rest[1]) ? (int)$rest[1] : 0;
    }
}
