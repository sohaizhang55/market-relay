<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 休市判定结果 —— 某个标的此刻能不能交易
 *
 * OPEN    开市。落在时段表某段内，且该段的收盘日没有全日休市假日
 * CLOSED  休市。计划内的、由交易日历推出来的不可交易
 *
 * 兜底 CLOSED：判定过程中任何一处拿不准（日历数据缺失、calendar_code 不在
 * MarketCalendarEnum 里、库读不出来）一律判 CLOSED。误拦会被用户当场报障，
 * 误放行则是「休市期间放进来下单、上游没有深度」，最难发现的方向。
 *
 * ── 为什么不与链路状态合并成一个枚举（2026-09-23 定案）──────────────────
 * 「市场采集架构优化」那边有一套 HALTED / QUIET / CONN_LOST，曾考虑并成一个五态枚举。
 * 结论是**不合并**，两者回答的是不同的问题：
 *   本枚举    计划内 · 可预知 · 由**日历**决定
 *   链路状态  故障   · 不可预知 · 由**观测**决定
 * 合并会让「判定源只认本地日历、否决观测反推休市」（market_session_module.md §六-1）
 * 这条已定结论在类型上失去约束力 —— 一个枚举里同时装着两种来源的值，
 * 迟早有人拿观测结果去写休市那几个 case，而那正是这个模块开头就否决掉的做法。
 * 代价是要同时表达两个维度的地方（如 ExchangePairService::attachPriceHealth）
 * 得返回两个字段而不是一个状态值，这是有意付的。
 *
 * 值用 1/2 而不是字符串：Coding_Standards §8 的状态码约定（1=开启 2=关闭），
 * 与 CommonStatusEnum 同构。本枚举**不落库**，只在判定链路里传递。
 *
 * 影响面
 *   配置  无 —— 日历随代码走，判定不读配置
 *   消费  MarketSessionService::state() 的返回值 · 阶段 3.3 起的各豁免与拦截点
 *   校验  无 —— 不接受外部输入，只由判定函数产出
 *   落地  无 —— 不落库、不写 Redis（缓存的是日历数据不是判定结果，见 MarketSessionService）
 *   前端  无 —— 未开 EXPORT_API，前端只看得到最终的可否下单
 *
 * 设计依据：.agents/plan/market_session_phase3.md §3.2 / §五-3
 */
enum MarketSessionStateEnum: int
{
    use BaseEnum;

    #[Label('开市')]
    case OPEN = 1;

    #[Label('休市')]
    case CLOSED = 2;

    /** 判定拿不准时的兜底，见类注释 */
    public const FALLBACK = self::CLOSED;

    /** 能否交易 —— 给调用方省掉一次 ::CASE->value 比较 */
    public function tradable(): bool
    {
        return $this === self::OPEN;
    }
}
