<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 节假日休市类型 —— 一条假日规则是「整天不开」还是「只改当天时段」
 *
 * FULL_CLOSE  全日休市。当天落在时段表任何段内的时刻一律 CLOSED，不看时间
 * PARTIAL     时段调整（半日市）。当天时段被**裁剪**到 [open_time, close_time] 之内，只能变短
 * OVERRIDE    时段覆盖。当天时段被**替换**成 [open_time, close_time]，可以比常规更长
 *
 * 兜底 FULL_CLOSE：列默认值即 1。假日表里存在一行却读不出类型时，判为整天不开是保守方向 ——
 * 误判休市只是少做几笔生意，误判开盘则是在没有上游深度的时候放用户进来下单。
 *
 * PARTIAL 的语义是**上下限裁剪**不是整段替换：
 *   当日实际时段 = 时段表该星期的所有段 ∩ [open_time, close_time]，空段丢弃
 * 港股半日市 12:00 收盘时，下午段 13:00-16:00 裁成 13:00-12:00 即空、自动消失，
 * 不必为「午休」单独写规则。换成「替换成一段」就得额外规定替换掉哪一段。
 *
 * ── 为什么 OVERRIDE 要单独一个 case，而不是放开 PARTIAL 的方向 ──────────
 * PARTIAL「只能变短」是一道安全护栏，不是疏漏：配错一行的后果被限定在「误拦」
 * （该开市却判休市 —— 用户下不了单会报障，一眼能发现），而不是「休市期间放行下单」
 * （能下单但上游无深度，最难发现的方向）。假日表现有一千多行，全部依赖这条护栏。
 *
 * 但确实存在**真实的延后收盘**：XTAE（特拉维夫）周五常规 13:50 收（安息日前提前），
 * 而逾越节前的那个周五反而延后到 14:15 —— 每年 1 天、25 分钟，PARTIAL 装不下
 * （min(13:50, 14:15) = 13:50，那条记录被完全忽略）。
 *
 * 所以新增 OVERRIDE 而不是改 PARTIAL：现有行全是 1 和 2，语义与风险一个字没变；
 * 只有**明确标成 3** 的行才具备扩大区间的能力，爆炸半径仅限这几行。
 * 这类行极少 —— 68 本日历 2026-2027 两年合计只有 2 条（XTAE 每年 1 条）。
 *
 * ⚠ OVERRIDE 必须**同时**给 open_time 与 close_time。只给一头就无法确定另一头该用什么：
 * 沿用时段表的话，「覆盖」就退化成了裁剪；留空则区间没有边界。导入命令会挡下只给一头的行。
 *
 * 影响面
 *   配置  无
 *   消费  休市判定（阶段三）· business/MarketCalendar 列表与表单
 *   校验  MarketCalendar::_holiday_add_form_filter · MarketImportCalendar
 *   落地  market_calendar_holiday.close_type
 *   前端  无 —— 未开 EXPORT_API，前端只看得到最终的可否下单
 *
 * 设计依据：.agents/plan/market_calendar_module.md §2.4 / §2.5
 */
enum MarketHolidayTypeEnum: int
{
    use BaseEnum;

    #[Label('全日休市')]
    case FULL_CLOSE = 1;

    #[Label('时段调整')]
    case PARTIAL = 2;

    #[Label('时段覆盖')]
    case OVERRIDE = 3;
}
