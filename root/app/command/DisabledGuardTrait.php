<?php

declare(strict_types=1);

namespace app\command;

use think\console\Output;
use Workerman\Worker;
use app\lib\WorkerService;

/**
 * 「按配置没启用」的常驻进程守卫 —— 目前只有选源进程（market.source_select.enable）用
 *
 * deploy/restart_market.sh 是全量下发的：所有 restart 指令都会执行一遍，
 * 由各指令自己按配置决定是否真的起来。
 *
 * 不该跑时不能直接 return：比如本机原本开着选源、改成关闭后再跑一次脚本，
 * restart 在 return 处就退出了，Workerman 的 restart 逻辑（读 pid 文件、给旧 master 发 SIGINT）
 * 根本没机会执行 —— 旧进程和 pid 文件原地不动，继续按旧配置切源。
 *
 * 所以"不该跑"时把动作降级成 stop 交给 Workerman 收尾：有残留进程就杀掉并清理 pid 文件，
 * 没有就打印 not run，两种情况都在 Worker::runAll() -> parseCommand() 内部 exit，
 * 不会继续走到真正的启动逻辑。
 */
trait DisabledGuardTrait
{
    /**
     * 「按配置不启动」的退出码，供 deploy/lib/run_daemon.sh 区分三种结果：
     * 0 = 真的起来了，3 = 未启用（已确保其未在运行），其它 = 真失败。
     *
     * 不能靠 Workerman 自己的退出码：stop 分支内部写死 exit(0)，跟"重启成功"混在一起分不开；
     * 也不宜让脚本去抓 stdout 关键字——成功路径带 -d 会 daemonize，$(...) 捕获可能因为子进程
     * 仍持有管道而一直挂住。所以用 register_shutdown_function 覆盖掉 Workerman 的 exit(0)。
     */
    public const EXIT_DISABLED = 3;

    /**
     * 按配置不该启动：打印原因，并把 action 降级为 stop 清理可能残留的旧进程
     *
     * @param string $taskName pid/日志文件名，必须与该指令正常启动时传给 WorkerService::init() 的完全一致，
     *                         否则停的是别人的 pid 文件（或者根本找不到，残留进程照样活着）
     * @param string $message  面向运维的不启动原因，直接输出到控制台
     */
    protected function stopForDisabled(string $taskName, Output $output, string $message): void
    {
        $output->writeln("<comment>{$message}</comment>");

        // 必须用 register_shutdown_function 覆盖：下面 Worker::runAll() 走的是
        // Workerman 的 stop 分支，那里写死了 exit(0)，正常 return 一个值根本传不出去。
        // 放在 Windows 分支之前，保证两个平台上都是同一个退出码。
        $code = self::EXIT_DISABLED;
        register_shutdown_function(static function () use ($code) {
            exit($code);
        });

        // Windows 下 Workerman 不走 pid 文件那套 (parseCommand 直接 return，继续跑下去会以零 worker
        // 空转卡住命令行窗口)，win_deploy/restart_market.bat 本来就是按窗口标题 taskkill 的，
        // 这里只提示不接管
        if (DIRECTORY_SEPARATOR !== '/') {
            $output->writeln("<comment>[Market] 本机为 Windows，请自行确认 {$taskName} 相关进程已关闭。</comment>");
            return;
        }

        $output->writeln("<comment>[Market] 降级为 stop，清理本机可能残留的进程: {$taskName}</comment>");

        // parseCommand() 取 argv 里第一个命中的命令词；调用方已经把原本的 action 从 argv 中摘掉了
        global $argv;
        $argv[1] = 'stop';

        WorkerService::init($taskName);
        Worker::runAll(); // stop 分支内部 exit，正常不会返回
    }
}
