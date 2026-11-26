<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 发送选源进程的告警（切走 / 切回 / 全部不健康 / 锁定 / 恢复）
 *
 * market:source_select 是常驻事件循环，不能自己发：站内通知要同步连后台 Gateway 推送、
 * Telegram 是同步 HTTP，连不上时一条就卡几秒，一轮几十条时选源会停摆。所以它只把告警
 * RPUSH 进 Redis 队列，由本命令定时取出，逐条走站内通知与 Telegram（每个交易对一条，不合并）。
 *
 * 命令  php think market:source_select_notify
 * 触发  xadmin:task 注册的循环任务，每 15 秒（见 app\command\Task）
 * 单例  无：LPOP 是原子的，偶尔重叠跑也不会重复发
 * 依赖  Redis market:source_select:alerts（MarketSourceSelect::ALERT_QUEUE_KEY）· 站内通知 · Telegram 配置
 *
 * 用法:
 *   php think market:source_select_notify      # 手动清一次队列
 */
class MarketSourceSelectNotify extends Command
{
    use \app\lib\AlertFanoutTrait;

    /** 一次最多发多少条，剩下的下一轮再发：整家交易所断流时可能一下子几十条，别让一轮跑太久 */
    private const BATCH = 100;

    protected function configure()
    {
        $this->setName('market:source_select_notify')
            ->setDescription('Send queued alerts of market:source_select (admin notice + Telegram)');
    }

    protected function execute(Input $input, Output $output)
    {
        $redis = redis_client();
        if (!$redis) {
            $output->writeln('<error>Redis 不可用，本轮跳过</error>');
            return;
        }

        $sent = 0;
        while ($sent < self::BATCH && ($raw = $redis->lPop(\app\command\MarketSourceSelect::ALERT_QUEUE_KEY)) !== false) {
            $a = json_decode((string)$raw, true);
            if (!is_array($a) || empty($a['msg'])) continue;

            $title = (string)($a['title'] ?? '行情源切换');
            $msg   = (string)$a['msg'];
            // 正文放进代码块：source_key 带下划线，旧版 Markdown 会把成对的 _ 渲染成斜体，源名就显示错了
            $this->fanout($title, $msg, (string)($a['level'] ?? 'warning'),
                '*[' . self::siteName() . "] {$title}*\n```\n{$msg}\n```");
            $sent++;
        }

        $left = (int)$redis->lLen(\app\command\MarketSourceSelect::ALERT_QUEUE_KEY);
        $output->writeln("已发送 {$sent} 条" . ($left ? "，队列里还有 {$left} 条留到下一轮" : ''));
    }
}
