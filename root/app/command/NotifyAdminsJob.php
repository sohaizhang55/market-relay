<?php

declare(strict_types=1);

namespace app\command;

use app\business\service\AdminNoticeService;
use think\admin\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;

/**
 * 异步全量管理员通知处理脚本
 * Class NotifyAdminsJob
 * @package app\command
 */
class NotifyAdminsJob extends Command
{
    protected function configure()
    {
        $this->setName('xadmin:notify_admins')
             ->addArgument('data', Argument::REQUIRED, 'JSON serialized notification data')
             ->setDescription('Async broadcast notification to all administrators database records');
    }

    /**
     * 执行指令
     */
    protected function execute(Input $input, Output $output)
    {
        // AdminNoticeService::notifyAllAdmins 用 enbase64url 编码后再拼进命令行，
        // 避免 JSON 内的空格被队列执行器的朴素 explode(' ', $command) 拆分参数
        $json = debase64url($input->getArgument('data'));
        $params = json_decode($json, true);

        if (!$params || !isset($params['title'], $params['content'])) {
            $output->error("Invalid notification data");
            return;
        }

        $title = $params['title'];
        $content = $params['content'];
        $type = $params['type'] ?? 'info';

        // 获取所有活跃管理员
        $admins = Db::name('system_user')->where(['status' => 1, 'is_deleted' => 0])->column('id');
        
        $output->writeln("Starting broadcast to " . count($admins) . " admins...");

        foreach ($admins as $adminId) {
            // 直接操作数据库插入，避免在 Job 中再次产生递归广播循环
            Db::name('system_notification')->insert([
                'admin_id'  => (int)$adminId,
                'from_id'   => 0,
                'type'      => $type,
                'title'     => $title,
                'content'   => $content,
                'is_read'   => 0,
                'create_ts' => time(),
            ]);
        }

        $output->writeln("Broadcast completed.");
    }
}
