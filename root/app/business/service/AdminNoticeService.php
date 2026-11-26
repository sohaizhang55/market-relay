<?php

declare(strict_types=1);

namespace app\business\service;

use app\service\trait\ServiceResult;
use think\admin\Service;
use think\facade\Db;

/**
 * 后台通知服务
 * @class AdminNoticeService
 * @package app\business\service
 */
class AdminNoticeService extends Service
{
    use ServiceResult;

    /**
     * 获取通知汇总摘要
     * @param int $adminId 管理员ID
     * @return array [bool $status, string $msg, array $data]
     */
    public function getSummary(int $adminId): array
    {
        return $this->toSuccess([
            'unread' => $this->getUnreadCount($adminId),
            'list'   => $this->getLatestNotices($adminId, 5),
        ], 'fetch_notice_success');
    }

    /**
     * 获取未读通知数
     */
    public function getUnreadCount(int $adminId): int
    {
        return Db::name('system_notification')->where(['admin_id' => $adminId, 'is_read' => 0])->count();
    }

    /**
     * 获取最新通知列表
     */
    public function getLatestNotices(int $adminId, int $limit = 5): array
    {
        return Db::name('system_notification')->where(['admin_id' => $adminId])
            ->order('create_ts desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 推送系统通知
     */
    public function push(int $toId, string $title, string $content, string $type = 'info', array $extra = []): bool
    {
        $res = Db::name('system_notification')->insert([
            'admin_id'  => $toId,
            'from_id'   => 0,
            'type'      => $type,
            'title'     => $title,
            'content'   => $content,
            'is_read'   => 0,
            'create_ts' => time(),
        ]) > 0;

        if ($res) {
            // 获取最新未读总数，消除前端延迟
            $unreadCount = $this->getUnreadCount($toId);
            // 同步进行 WebSocket 实时推送
            WsService::pushNotice($toId, $title, $unreadCount);
        }

        return $res;
    }

    /**
     * 标记为已读
     */
    public function read(int $id, int $adminId): bool
    {
        return Db::name('system_notification')->where(['id' => $id, 'admin_id' => $adminId])
            ->update(['is_read' => 1, 'read_ts' => time()]) > 0;
    }

    /**
     * 清空全部已读
     */
    public function clearAll(int $adminId): bool
    {
        return Db::name('system_notification')->where(['admin_id' => $adminId, 'is_read' => 0])
            ->update(['is_read' => 1, 'read_ts' => time()]) >= 0;
    }

    /**
     * 通知所有已激活的后台管理员 (高性能广播 + 异步持久化)
     */
    public function notifyAllAdmins(string $title, string $content, string $type = 'info'): bool
    {
        // 1. 立即执行 WebSocket 全量广播 (O(1) 速度)
        WsService::broadcastNoticeToAdmins($title);

        // 2. 将耗时的数据库插入操作存入异步队列 (xadmin:task)
        $data = json_encode([
            'title'   => $title,
            'content' => $content,
            'type'    => $type
        ], JSON_UNESCAPED_UNICODE);

        // 队列执行器（vendor/zoujingli/think-library Queue::doRunAction）用朴素的 explode(' ', $command)
        // 拆分命令行参数，不识别引号分组；title/content 一旦带空格，JSON 就会被截断或拆成多个参数，
        // 导致 "Invalid notification data" / "Too many argument"。这里用 URL-safe base64 编码整个 JSON
        // 载荷，规避空格问题，NotifyAdminsJob 端用 debase64url 解回来。
        $payload = enbase64url($data);

        // ⚠️ system_queue.command 是 varchar(500)，而这里塞的是整条内容的 base64。
        // 超长时 MySQL 抛 "Data too long for column 'command'"，而这个异常会**冒到调用方**——
        // 告警命令因此整个中断，连后面的 Telegram 都发不出去。
        // 也就是说：**故障越大、涉及的对象越多，告警越发不出去**，
        // 而这恰恰是最需要它工作的时刻。
        //
        // 处理方式是截断而不是放弃：正文的完整版调用方都已经写进 business_log 了，
        // 站内通知只需要够人判断"出了什么事、该去看哪里"。
        if (strlen($payload) > self::PAYLOAD_MAX) {
            $keep = $content;
            // 二分逼近：base64 长度与原文非线性（多字节字符），直接按比例算会切在半个汉字上
            while ($keep !== '' && strlen(enbase64url(json_encode(
                ['title' => $title, 'content' => $keep . self::TRUNCATED_MARK, 'type' => $type],
                JSON_UNESCAPED_UNICODE
            ))) > self::PAYLOAD_MAX) {
                $keep = mb_substr($keep, 0, max(0, mb_strlen($keep) - 20));
            }
            $content = $keep . self::TRUNCATED_MARK;
            $payload = enbase64url(json_encode(
                ['title' => $title, 'content' => $content, 'type' => $type],
                JSON_UNESCAPED_UNICODE
            ));
            business_log('admin_notice', "通知正文过长已截断（标题：{$title}），完整内容见对应业务日志", 'warning');
        }

        // 使用 ThinkAdmin 提供的 sysqueue 助手函数推送到 system_queue
        // 注意：sysqueue 成功时会返回任务编号字符串，因此需转为 bool
        //
        // 整段包起来：入队失败**不能**让调用方中断。调用方几乎都是告警路径，
        // 而"一条通道坏了把另一条也带走"是告警系统最不该有的行为
        try {
            return !empty(sysqueue('后台全量通知广播', "xadmin:notify_admins {$payload}", 0));
        } catch (\Throwable $e) {
            business_log('admin_notice', "站内通知入队失败：{$e->getMessage()}（标题：{$title}）", 'error');
            return false;
        }
    }

    /**
     * 队列 command 字段的可用长度。
     *
     * system_queue.command 是 varchar(500)，前面还要放 "xadmin:notify_admins " 这 21 个字符，
     * 再留一点余量给将来可能变长的命令名。
     */
    private const PAYLOAD_MAX = 460;

    private const TRUNCATED_MARK = '…（内容过长已截断，完整内容见业务日志）';
}
