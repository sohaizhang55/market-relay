<?php

use think\migration\Migrator;

/**
 * 行情订阅方表：一个订阅方一条、各持一个 token
 *
 * 订阅方 IP 经常变，按 IP 认人靠不住，所以改成按 token 认：WS 握手头与 /inner_api/* 的
 * X-Inner-Token 命中哪一条，就是哪个订阅方，并把那一刻的来源 IP 记进 last_ws_* / last_api_*。
 * 运行时不直接查这张表，读的是 DispatchSubscriberService::publish() 写进 Redis 的快照。
 */
class CreateDispatchSubscriberTable extends Migrator
{
    public function change()
    {
        if ($this->hasTable('dispatch_subscriber')) return;

        $this->table('dispatch_subscriber', ['comment' => '行情订阅方（分发鉴权 token）'])
            ->addColumn('name', 'string', ['limit' => 64, 'default' => '', 'comment' => '订阅方名称，监控页与日志里显示'])
            ->addColumn('token', 'string', ['limit' => 64, 'default' => '', 'comment' => '鉴权 token，订阅方放在 X-Inner-Token 头里'])
            ->addColumn('status', 'integer', ['limit' => 2, 'default' => 1, 'comment' => '状态 @see app\\enum\\CommonStatusEnum (1:启用, 2:停用)'])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => '备注'])
            ->addColumn('last_ws_ip', 'string', ['limit' => 45, 'default' => '', 'comment' => '最后一次 WS 连接的来源 IP'])
            ->addColumn('last_ws_at', 'datetime', ['null' => true, 'comment' => '最后一次 WS 连接时间'])
            ->addColumn('last_ws_ts', 'integer', ['limit' => 10, 'default' => 0, 'comment' => '最后一次 WS 连接秒级时间戳'])
            ->addColumn('last_api_ip', 'string', ['limit' => 45, 'default' => '', 'comment' => '最后一次 inner_api 请求的来源 IP'])
            ->addColumn('last_api_at', 'datetime', ['null' => true, 'comment' => '最后一次 inner_api 请求时间'])
            ->addColumn('last_api_ts', 'integer', ['limit' => 10, 'default' => 0, 'comment' => '最后一次 inner_api 请求秒级时间戳'])
            ->addColumn('create_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
            ->addColumn('update_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'comment' => '更新时间'])
            ->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'comment' => '创建秒级时间戳'])
            ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'comment' => '更新秒级时间戳'])
            ->addIndex(['token'], ['unique' => true, 'name' => 'uk_dispatch_subscriber_token'])
            ->create();
    }
}
