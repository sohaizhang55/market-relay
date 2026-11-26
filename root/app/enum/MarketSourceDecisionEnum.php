<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 选源进程对一个交易对的本轮决定
 *
 * KEEP      保持当前生效源
 * FAILOVER  切走：当前生效源断流（或不在启用的绑定里），立即切到优先级最高的健康源
 * RECOVER   切回：优先级更高的源已连续健康满 market.source_select.recover_sec，切回去
 * ALL_DOWN  当前生效源断流，且没有任何健康的备源：保持不动并告警
 *
 * 兜底 KEEP：拿不准就不动，交易对停在原地最多是行情停更（有过期告警兜着），乱切会让价格来回跳。
 *
 * 影响面
 *   配置  market.source_select.*（enable / recover_sec / flap_window_sec / flap_max / notify）
 *   消费  market:source_select 据此切源
 *   校验  无 —— 只由 SourceSelector::decide() 产出
 *   落地  无 —— 结果体现在 exchange_pair.source_key 与切换日志里，本身不落库
 *   前端  无
 */
enum MarketSourceDecisionEnum: int
{
    use BaseEnum;

    #[Label('保持')]
    case KEEP = 1;

    #[Label('切走')]
    case FAILOVER = 2;

    #[Label('切回')]
    case RECOVER = 3;

    #[Label('全部不健康')]
    case ALL_DOWN = 4;

    /** 拿不准时的兜底，见类注释 */
    public const FALLBACK = self::KEEP;
}
