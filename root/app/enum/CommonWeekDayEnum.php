<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 星期（ISO-8601：1=周一 … 7=周日）
 *
 * 取值刻意对齐 PHP 的 date('N') / DateTime::format('N')，判定时拿到的星期可以直接比较，
 * 不必做任何偏移。**不用 date('w')**（0=周日、6=周六）—— 那一套里周日是 0，
 * 时段表算周内分钟偏移 `(week_day - 1) * 1440` 会算出 -1440，跨周段直接错位。
 *
 * 做成 Common 前缀的通用枚举而不是时段表专属：星期是日历概念不是行情概念，
 * 将来任何按周排班的功能都该复用这一份（Coding_Standards §9 通用前缀）。
 *
 * 影响面
 *   配置  无
 *   消费  business/MarketCalendar 时段列表与表单 · 休市判定（阶段三）
 *   校验  MarketCalendar::_session_add_form_filter · MarketImportCalendar
 *   落地  market_calendar_session.open_week_day / close_week_day
 *   前端  无 —— 未开 EXPORT_API，星期不对前端暴露
 *
 * 设计依据：.agents/plan/market_calendar_module.md §2.6
 */
enum CommonWeekDayEnum: int
{
    use BaseEnum;

    #[Label('周一')]
    case MONDAY = 1;

    #[Label('周二')]
    case TUESDAY = 2;

    #[Label('周三')]
    case WEDNESDAY = 3;

    #[Label('周四')]
    case THURSDAY = 4;

    #[Label('周五')]
    case FRIDAY = 5;

    #[Label('周六')]
    case SATURDAY = 6;

    #[Label('周日')]
    case SUNDAY = 7;

    /** 一周的分钟数，周内偏移的模 */
    public const MINUTES_PER_WEEK = 7 * 24 * 60;

    /**
     * 把「星期 + HH:MM」压成 0–10079 的周内分钟偏移。
     *
     * 时段重叠检测必须在这个尺度上做，不能比 HH:MM 字符串：黄金那种
     * 「周日 17:00 → 周一 17:00」的跨天段，两头的 HH:MM 完全相等，
     * 字符串比较会得出「收盘不晚于开盘」，整段被判非法。
     *
     * @param int    $weekDay 1–7
     * @param string $hhmm    HH:MM（两位补零）
     * @return int 0–10079
     */
    public static function minuteOfWeek(int $weekDay, string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return ($weekDay - 1) * 1440 + $h * 60 + $m;
    }
}
