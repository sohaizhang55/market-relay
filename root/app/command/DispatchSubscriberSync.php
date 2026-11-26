<?php

declare(strict_types=1);

namespace app\command;

use app\business\service\DispatchSubscriberService;
use think\admin\Command;
use think\console\Input;
use think\console\Output;

/**
 * 分发鉴权兜底同步：重写 Redis 鉴权快照，并把订阅方最后来源 IP 落库
 *
 * 后台每次改订阅方或开关都会立即写快照，这里兜的是 Redis 被清空、重启丢数据的情况 ——
 * Go 分发机不连库，快照没了它只能沿用内存里上一次读到的。
 *
 * 命令  php think market:subscriber_sync
 * 触发  xadmin:task 队列（60s）· 手工
 * 单例  无（两步都幂等，重入无副作用）
 * 依赖  表 dispatch_subscriber · system_config(market_dispatch.auth_enabled)
 *       Redis market:dispatch:auth · market:dispatch:subscriber_seen
 *
 * 用法:
 *   php think market:subscriber_sync        # 重写快照 + 最后来源 IP 落库
 *
 * 退出码  0 = 两步都成功；1 = 有一步失败（原因见输出与 logs/inner_api）
 */
class DispatchSubscriberSync extends Command
{
    protected function configure()
    {
        $this->setName('market:subscriber_sync')
            ->setDescription('重写分发鉴权 Redis 快照，并把订阅方最后来源 IP 落库');
    }

    protected function execute(Input $input, Output $output)
    {
        $svc = DispatchSubscriberService::instance();

        [$ok, $msg, $snap] = $svc->publish();
        if (!$ok) {
            $output->writeln("<error>[FAIL]</error> {$msg}");
            return 1;
        }
        $output->writeln(sprintf('[OK] 快照已发布：鉴权%s，启用的订阅方 %d 个',
            $snap['enabled'] ? '开启' : '关闭', count($snap['subs'])));

        try {
            [, , $n] = $svc->syncSeen();
        } catch (\Throwable $e) {
            business_log('inner_api', "订阅方最后来源 IP 落库失败：{$e->getMessage()}", 'error');
            $output->writeln("<error>[FAIL]</error> 最后来源 IP 落库失败：{$e->getMessage()}");
            return 1;
        }
        $output->writeln("[OK] 最后来源 IP 落库 {$n} 条");
        return 0;
    }
}
