<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateSystemNotification extends Migrator
{
    /**
     * 系统通知表迁移
     */
    public function change()
    {
        $table = $this->table('system_notification', ['comment' => '系统通知表']);
        $table->addColumn('admin_id', 'biginteger', ['signed' => false, 'null' => false, 'comment' => '接收管理员 ID'])
            ->addColumn('from_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '发送人ID(0为系统)'])
            ->addColumn('type', 'string', ['limit' => 20, 'default' => 'info', 'comment' => '类型(info,success,warning,danger)'])
            ->addColumn('title', 'string', ['limit' => 200, 'default' => '', 'comment' => '通知标题'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => '通知内容'])
            ->addColumn('is_read', 'integer', ['limit' => 1, 'default' => 0, 'comment' => '阅读状态(0未读,1已读)'])
            ->addColumn('create_ts', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '创建时间戳'])
            ->addColumn('read_ts', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '阅读时间戳'])
            ->addIndex(['admin_id', 'is_read'])
            ->create();
    }
}
