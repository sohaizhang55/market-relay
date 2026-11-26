<?php

declare(strict_types=1);

namespace app\lib;

use app\business\service\AdminNoticeService;

/**
 * 告警的多通道分发：逐条通道独立发送，任何一条抛异常都不能把其余的带走。
 *
 * ⚠️ 这个隔离不是防御性编程的客套。站内通知那一条会因为
 * `system_queue.command` 是 `varchar(500)` 而在正文超长时抛 MySQL 异常，
 * 于是**故障涉及的对象越多，整条告警链越发不出去** —— 而那正是最需要它工作的时刻。
 * 现网的价格告警把所有陈旧交易对列在正文里，超过十几个就会踩到。
 *
 * 抽成 trait 而不是在两个命令里各写一份：这段逻辑原来是复制粘贴的，
 * 而复制粘贴的告警代码只要修了其中一份，另一份就开始腐烂 ——
 * 而腐烂的那一份不会有任何人发现，它只是在某次故障里安静地少发一条。
 */
trait AlertFanoutTrait
{
    /**
     * @param string $title   站内通知标题
     * @param string $content 站内通知正文（超长会被 AdminNoticeService 截断，完整版在业务日志里）
     * @param string $level   info|warning|error
     * @param string $tgText  Telegram 正文（通常带 Markdown 标记，与站内通知不同）
     */
    private function fanout(string $title, string $content, string $level, string $tgText): void
    {
        try {
            app()->make(AdminNoticeService::class)->notifyAllAdmins($title, $content, $level);
        } catch (\Throwable $e) {
            $this->fanoutLog("站内通知失败：{$e->getMessage()}（{$title}）");
        }
        try {
            TelegramService::sendMessage($tgText);
        } catch (\Throwable $e) {
            $this->fanoutLog("Telegram 失败：{$e->getMessage()}（{$title}）");
        }
    }

    /** 写日志本身也要包住：这里已经在异常处理路径上，business_log 是同步 fsync */
    private function fanoutLog(string $msg): void
    {
        try {
            business_log('alert_channel', $msg, 'error');
        } catch (\Throwable $ignored) {
        }
    }

    /** 站点名。三处告警各写一遍 `sysconf('site_name') ?: 'market-relay'`，其中一处曾漏掉兜底 */
    private static function siteName(): string
    {
        return (string)(sysconf('site_name') ?: 'market-relay');
    }
}
