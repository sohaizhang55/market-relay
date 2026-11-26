<?php

use think\migration\Migrator;

/**
 * 节假日休市日历 —— 传统市场标的的计划性休市（全日休市 / 半日市）
 *
 * 归属日历走 coin_list.calendar_code（ISO 10383 MIC，迁移 20260910130000 已建），
 * 空串 = 24/7 加密标的，判定入口短路返回 OPEN，根本不会查到本表。
 *
 * **本表单独存在得不出任何休市结论** —— 休市判定是「落在时段表某段内 ∧ ¬当日假日」，
 * 「这天不是假日」推不出「现在开盘」。时段表（market_calendar_session）属阶段二，
 * 判定函数属阶段三。本迁移只把假日数据备好。
 *
 * 不建日历主表：MIC / 中文名 / IANA 时区写在 app\enum\MarketCalendarEnum，
 * 时区在 DB 里存 0 次而不是 1 次，且 timezone() 的 match 无 default —— 新增市场忘配时区
 * 当场抛 UnhandledMatchError，比表里留个空 timezone 早得多。
 *
 * 三个列的存在理由：
 *   open_time/close_time  半日市当天的时段覆盖。语义是**上下限裁剪**不是整段替换：
 *                         当日实际时段 = 时段表该星期的所有段 ∩ [open_time, close_time]，空段丢弃。
 *                         港股半日市 12:00 收盘时，下午段 13:00-16:00 裁成空、自动消失，
 *                         不必为「午休」单独写规则。跨午夜时段按**收盘日**归属。
 *   source                区分脚本导入与人工录入。market:import_calendar 只覆盖
 *                         source='exchange_calendars' 的行，运维手工加的临时调休不被冲掉。
 *                         没有这一列，导入命令就只能全删重建或完全不敢删。
 *   status                交易所撤销临时调休时留痕比删行可查。
 *
 * 各列显式写 'null' => false：phinx 的 addColumn() 默认建可空列，只给 default 挡不住 NULL。
 * calendar_code 为 NULL 会让该行永远匹配不上任何标的，表现为「配了假日但节假日照常开盘」——
 * 正是最难发现的那个方向。
 *
 * 设计依据：.agents/plan/market_calendar_module.md §2.3 / §2.4
 */
class CreateMarketCalendarHoliday extends Migrator
{
    public function change()
    {
        $this->table('market_calendar_holiday', ['engine' => 'InnoDB', 'comment' => '节假日休市日历'])
            ->addColumn('calendar_code', 'string', ['limit' => 16, 'null' => false, 'comment' => '日历归属 ISO 10383 MIC，取值见 MarketCalendarEnum'])
            ->addColumn('holiday_date', 'date', ['null' => false, 'comment' => '交易所本地自然日 (不是 UTC 日)'])
            ->addColumn('close_type', 'integer', ['limit' => 1, 'default' => 1, 'null' => false, 'comment' => '休市类型 1=全日休市 2=时段调整(半日市)，见 MarketHolidayTypeEnum'])
            ->addColumn('open_time', 'string', ['limit' => 5, 'default' => '', 'null' => false, 'comment' => '时段调整日开盘本地时间 HH:MM，空=不改开盘'])
            ->addColumn('close_time', 'string', ['limit' => 5, 'default' => '', 'null' => false, 'comment' => '时段调整日收盘本地时间 HH:MM，空=不改收盘'])
            ->addColumn('title', 'string', ['limit' => 64, 'default' => '', 'null' => false, 'comment' => '假日名称，如 Independence Day'])
            ->addColumn('source', 'string', ['limit' => 32, 'default' => 'manual', 'null' => false, 'comment' => '数据来源 exchange_calendars=脚本导入 manual=人工录入；导入命令只覆盖前者'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'null' => false, 'comment' => '状态 1=启用 2=停用；停用的规则不参与休市判定'])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'null' => false, 'comment' => '备注'])
            ->addColumn('create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0])
            ->addColumn('update_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0])
            // 同一本日历同一天只能有一条规则，否则判定取哪条不确定
            ->addIndex(['calendar_code', 'holiday_date'], ['unique' => true, 'name' => 'uk_holiday_calendar_date'])
            // 判定按「时刻所在的交易所本地自然日」查表，日期是唯一的高频查询维度
            ->addIndex(['holiday_date'], ['name' => 'idx_holiday_date'])
            ->create();
    }
}
