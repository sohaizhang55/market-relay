<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use app\lib\TelegramService;
use app\business\service\AdminNoticeService;

/**
 * 给 shell 脚本用的告警入口。
 *
 * 为什么需要它：部署与备份脚本跑在 crontab 里，**stdout/stderr 谁都看不到**。
 * 它们出了问题只能靠人主动去看日志文件，而人不会天天去看 ——
 * 备份连着失败几个月、在需要恢复的那天才发现，就是这么来的。
 *
 * 两条通道都发（后台通知 + Telegram），任一条通就算送到；
 * 都不通时退出码非 0，让调用方的日志里至少留下"连告警都发不出去"这句话。
 *
 *   php think alert:notify "数据库备份失败：mysqldump 退出码 1" --level=error
 */
class AlertNotify extends Command
{
    use \app\lib\AlertFanoutTrait;

    protected function configure()
    {
        $this->setName('alert:notify')
            ->addArgument('message', Argument::REQUIRED, '告警正文')
            ->addOption('title', 't', Option::VALUE_REQUIRED, '标题', '运维告警')
            ->addOption('level', 'l', Option::VALUE_REQUIRED, 'info|warning|error', 'warning')
            ->setDescription('从 shell 脚本发一条告警（后台通知 + Telegram）');
    }

    protected function execute(Input $input, Output $output)
    {
        $msg   = trim((string)$input->getArgument('message'));
        $title = (string)$input->getOption('title');
        $level = (string)$input->getOption('level');
        if ($msg === '') {
            $output->writeln('<error>[notify]</error> 正文为空');
            return 1;
        }
        if (!in_array($level, ['info', 'warning', 'error'], true)) $level = 'warning';

        // 先落盘：两条通道都可能不通，而日志是本机的，最不容易一起坏
        business_log('ops_alert', "[{$title}] {$msg}", $level);

        $sent = 0;
        try {
            app()->make(AdminNoticeService::class)->notifyAllAdmins($title, $msg, $level);
            $sent++;
        } catch (\Throwable $e) {
            $output->writeln('<comment>[notify]</comment> 后台通知失败：' . $e->getMessage());
        }

        $icon = $level === 'error' ? '⚠️' : ($level === 'info' ? 'ℹ️' : '⚠️');
        try {
            // 正文不加 Markdown 标记：脚本传来的内容含路径与下划线，
            // 加了反而会被 Telegram 的实体解析拒掉，见 TelegramService::sendMessage()
            if (TelegramService::sendMessage("[" . self::siteName() . "] {$icon} {$title}\n{$msg}")) $sent++;
        } catch (\Throwable $e) {
            $output->writeln('<comment>[notify]</comment> Telegram 失败：' . $e->getMessage());
        }

        if ($sent === 0) {
            $output->writeln('<error>[notify][FAIL]</error> 两条通道都没发出去，只留下了日志');
            return 1;
        }
        $output->writeln("<info>[notify]</info> 已送出（{$sent}/2 条通道）");
        return 0;
    }
}
