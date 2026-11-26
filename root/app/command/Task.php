<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\admin\service\QueueService;
use think\admin\model\SystemQueue;
use think\facade\Log;

/**
 * 初始化系统定时任务
 * Class Task
 */
class Task extends Command
{
    protected $log_info = [
        'source' => 'ThinkCommand',
        'command' => 'xadmin:task',
        'class' => 'Task',
    ];

    /** 队列状态。与 think-library Queue 命令的 STATE_* 一致；1/2/4 都算「还活着」，见 registerQueueTask */
    private const STATE_WAIT  = 1;
    private const STATE_LOCK  = 2;
    private const STATE_DONE  = 3;
    private const STATE_ERROR = 4;

    protected function configure()
    {
        $this->setName('xadmin:task')->setDescription('Register all periodic tasks into System Queue');
    }

    protected function execute(Input $input, Output $output)
    {
        // 记录日志, 使用 record 方法，可以记录其他参数或 context
        // preview log：{"time":"2026-04-02 20:07:47","type":"info","source":"ThinkCommand","command":"push:test_notice","class":"PushTestNotice","msg":"User login","user_id":123}
        // 使用场景：业务日志，需要带一些参数，比如 user_id, order_id, product_id, 或其他业务或配置的参数
        Log::record([
            ...$this->log_info,
            'msg' => 'Task command execute',
        ], 'info');
        $output->writeln("Registering periodic tasks...");

        // $this->registerQueueTask($output, '通知消息推送 (测试/5s)', 'push:test_notice', 5);
        $this->registerQueueTask($output, '行情价格过期检查 (60s)', 'market:price_check_expire', 60);
        // 反方向：判休市却仍在收数据 = 日历配错/过期。配置问题不用 60s 那么急
        $this->registerQueueTask($output, '休市判定巡检 (300s)', 'market:session_check', 300);
        $this->registerQueueTask($output, '常驻指令心跳健康检查 (15s)', 'command_heartbeat:check_expire', 15);
        // 选源进程（常驻事件循环）只把告警入队，由这里定时发出，见 MarketSourceSelect::report()
        $this->registerQueueTask($output, '选源告警发送 (15s)', 'market:source_select_notify', 15);
        $this->registerQueueTask($output, 'K线定期清理 (86400s)', 'market:kline_clean', 86400);
        // 后台改动会立即写快照，这里兜 Redis 被清空的情况，顺带把订阅方最后来源 IP 落库
        $this->registerQueueTask($output, '分发鉴权同步 (60s)', 'market:subscriber_sync', 60);

        $this->warnOrphanTasks($output);

        $output->writeln("All tasks initialized. Please ensure 'php think xadmin:queue start' is running.");
    }

    /** 本次注册过的命令，供 warnOrphanTasks 比对 */
    private array $registered = [];

    /**
     * 队列里还活着、但本文件已经不再注册的周期任务。
     *
     * 命令改名之后旧行不会自己消失：队列照调，而那个命令已经不存在 —— 每轮失败、
     * 被 clean 拉回 WAIT、再失败。只报不动手：system_queue 里也有别处投递的任务，
     * 误杀比留着噪音严重。
     *
     * ⚠️ `xadmin:` 开头的是框架自己的维护任务，本文件本来就不该注册它们。
     * 尤其 `xadmin:queue clean` —— 把失败的循环任务从 ERROR 拉回 WAIT 的正是它，
     * 报成孤儿会误导人去停掉队列的自愈机制。
     */
    private function warnOrphanTasks(Output $output): void
    {
        try {
            $rows = SystemQueue::mk()->master()
                ->where('loops_time', '>', 0)
                ->whereIn('status', [self::STATE_WAIT, self::STATE_LOCK, self::STATE_ERROR])
                ->column('command');
            $orphans = array_filter(
                array_unique(array_diff($rows, $this->registered)),
                static fn(string $cmd) => !str_starts_with($cmd, 'xadmin:')
            );
            if (!$orphans) return;
            $output->error('[ORPHAN] 队列里这些周期任务本文件已不再注册，确认是否还需要：'
                . implode('、', $orphans));
            Log::record([...$this->log_info, 'msg' => '孤儿周期任务：' . implode(',', $orphans)], 'error');
        } catch (\Exception $e) {
            $output->error('孤儿任务检查失败：' . $e->getMessage());
        }
    }

    /**
     * 注册队列任务检查
     * @param Output $output
     * @param string $title 任务标题
     * @param string $command 任务执行命令
     * @param int $loops 循环间隔
     */
    private function registerQueueTask(Output $output, string $title, string $command, int $loops)
    {
        $this->registered[] = $command;

        try {
            // ⚠️ 判重按 command、状态含 ERROR —— 两处都踩过。
            //
            // 按 title 判：标题改一个字就会再注册一条，而旧行还活着，两条并行调同一个命令，
            // 同一条告警于是被推两遍。
            // 漏掉 ERROR：循环任务失败后是 4，而 xadmin:queue clean 会把 loops_time>0 的
            // ERROR 行拉回 WAIT（见 think-library Queue 命令），所以 4 是「还活着」。
            // 恰好在那个窗口跑一次 xadmin:task 就凭空多一条 —— 几次之后就是一条告警推 N 遍。
            $alive = [self::STATE_WAIT, self::STATE_LOCK, self::STATE_ERROR];
            $rows = SystemQueue::mk()->master()
                ->where('command', '=', $command)
                ->whereIn('status', $alive)->select()->toArray();

            // 排序决定「留哪条」：ERROR 排最后。它要等 xadmin:queue clean 才会被拉回 WAIT，
            // 而 clean 不是监听器自动跑的 —— 留下它、砍掉健康那条，任务就一直是死的
            usort($rows, static fn($a, $b) =>
                [(int)$a['status'] === self::STATE_ERROR, (int)$a['id']]
                <=> [(int)$b['status'] === self::STATE_ERROR, (int)$b['id']]);

            if (!$rows) {
                QueueService::register($title, $command, 0, [], 0, $loops);
                $output->writeln("[OK] Registered '{$command}' (Interval: {$loops}s)");
                Log::record([...$this->log_info, 'msg' => "{$title} command end"], 'info');
                return;
            }

            // 只留排序后的第一条，多的收敛掉。必须置 DONE 不能置 ERROR —— 后者会被 clean 拉回 WAIT
            if (count($rows) > 1) {
                $extra = array_slice($rows, 1);
                SystemQueue::mk()->whereIn('id', array_column($extra, 'id'))
                    ->update(['status' => self::STATE_DONE, 'exec_desc' => '重复注册，已自动收敛']);
                $msg = sprintf("'%s' 有 %d 条并行注册，已收敛掉 %d 条 —— 此前同一条告警会被推 %d 遍",
                    $command, count($rows), count($extra), count($rows));
                $output->error("[DEDUP] {$msg}");
                Log::record([...$this->log_info, 'msg' => $msg], 'error');
            }

            // 标题只是给人看的，代码改了就跟着刷新，不要因此新注册一条
            if ((string)$rows[0]['title'] !== $title) {
                SystemQueue::mk()->where('id', '=', $rows[0]['id'])->update(['title' => $title]);
                $output->writeln(sprintf("[RENAME] '%s' 标题 '%s' → '%s'", $command, $rows[0]['title'], $title));
            }

            $output->writeln("[OK] Task '{$command}' already started successfully.");
            Log::record([...$this->log_info, 'msg' => "{$title} command already started"], 'info');
        } catch (\Exception $e) {
            $output->error("Registration failed for '{$command}': " . $e->getMessage());
            Log::record([...$this->log_info, 'msg' => 'Task command error: ' . $e->getMessage()], 'error');
        }
    }
}
