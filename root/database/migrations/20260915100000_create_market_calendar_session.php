<?php

use think\migration\Migrator;

/**
 * 开市时段表 —— 传统市场标的的周内固定交易时段（「黄金周末休市」那一类规则）
 *
 * 归属日历走 coin_list.calendar_code（ISO 10383 MIC），空串 = 24/7 加密标的，
 * 判定入口短路返回 OPEN，根本不会查到本表。
 *
 * 本表是**开市白名单**，不是休市黑名单。存的是「什么时候开」，休市是「没有任何一段
 * 覆盖」的自然结果。黄金只配 5 行开市时段，周五 17:00 到周日 17:00 没有任何段覆盖，
 * 那 48 小时就是周末休市 —— 不需要单独配一条「周末休市」规则。
 *
 * 选白名单的三个理由：
 *   1. 同一个结构装得下全部形态：黄金跨午夜、美股每天只开 6.5 小时、港股午休两段；
 *      黑名单要表达「美股每天只开 6.5 小时」得写成「00:00-09:30 休 + 16:00-24:00 休」，更啰嗦。
 *   2. 跨午夜段用黑名单没法写：黄金周五 17:00 收盘，「周末休市」跨了两个自然日。
 *   3. 配漏了的方向是安全的：白名单漏配 = 判休市（下不了单，一眼能发现）；
 *      黑名单漏配 = 判开盘（能下单但上游无深度），正是最难发现的那个方向。
 *
 * ── 为什么开盘和收盘各带自己的星期 ─────────────────────────────────────────
 * 因为交易所的「一天」不是从午夜算的。黄金一个交易日是「前日 17:00 → 当日 17:00」，
 * 而假日休的是这个**交易日**，不是自然日。
 *
 * 2026-04-03 耶稣受难日 CMES 全日休市，实测 4/2(周四) 17:00 收盘后直接跳到
 * 4/5(周日) 17:00 才开 —— 4/2 晚上那 7 小时也没有交易。存成一行
 * 「周四17:00 → 周五17:00」，收盘日写着周五，周五放假整行关掉，这 7 小时才关得对。
 *
 * 若改成「切在午夜」的两行（周四17:00-24:00 + 周五00:00-17:00），周四那行归属周四、
 * 不是假日，会在交易所关着的时候放行下单。实测这么拆每年错 56 小时（2026）、
 * 62 小时（2027），其中约 1/4 是「不该开却放行」。而且它平时完全正常，
 * 只有碰上假日才错 —— 测试环境跑一周看不出任何问题。
 *
 * ── 时间怎么存 ─────────────────────────────────────────────────────────────
 * 存交易所**本地时间**，不存 UTC；时区在 app\enum\MarketCalendarEnum::timezone()。
 * 这样美国夏令时切换那天，黄金本地时间永远是周五 17:00 收，一行数据都不用改
 * （北京时间自己从周六 07:00 变成 06:00）。存 UTC 则每年两次人工切表。
 *
 * ── 区间语义是左闭右开 [open, close) ───────────────────────────────────────
 * 黄金相邻两段在 17:00 首尾相接（周一段的收盘 == 周二段的开盘）。用闭区间的话
 * 17:00 同时属于两段，重叠检测必然误报，25 行一行都存不进去。
 *
 * 本表单独存在**得不出完整结论** —— 休市判定是「落在本表某段内 ∧ ¬当日假日」，
 * 假日表见迁移 20260914100000，判定函数属阶段三。
 *
 * 各列显式写 'null' => false：phinx 的 addColumn() 默认建可空列，只给 default 挡不住 NULL。
 *
 * 设计依据：.agents/plan/market_calendar_module.md §2.6
 */
class CreateMarketCalendarSession extends Migrator
{
    public function change()
    {
        $this->table('market_calendar_session', ['engine' => 'InnoDB', 'comment' => '开市时段表(周内固定规则)'])
            ->addColumn('calendar_code', 'string', ['limit' => 16, 'null' => false, 'comment' => '日历归属 ISO 10383 MIC，取值见 MarketCalendarEnum'])
            ->addColumn('open_week_day', 'integer', ['limit' => 1, 'null' => false, 'comment' => '开盘星期 1-7 (ISO,1=周一)，见 CommonWeekDayEnum'])
            ->addColumn('open_time', 'string', ['limit' => 5, 'null' => false, 'comment' => '开盘本地时间 HH:MM (两位补零)'])
            ->addColumn('close_week_day', 'integer', ['limit' => 1, 'null' => false, 'comment' => '收盘星期 1-7；与开盘不同即跨天，如黄金周日17:00->周一17:00'])
            ->addColumn('close_time', 'string', ['limit' => 5, 'null' => false, 'comment' => '收盘本地时间 HH:MM；区间左闭右开 [open,close)'])
            ->addColumn('source', 'string', ['limit' => 32, 'default' => 'manual', 'null' => false, 'comment' => '数据来源 exchange_calendars=脚本导入 manual=人工录入；导入命令只覆盖前者'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'null' => false, 'comment' => '状态 1=启用 2=停用；停用的时段不参与休市判定'])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'null' => false, 'comment' => '备注'])
            ->addColumn('create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0])
            ->addColumn('update_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0])
            // 判定按「某本日历在某个星期有哪些段」查，这是唯一的高频查询维度
            ->addIndex(['calendar_code', 'open_week_day'], ['name' => 'idx_session_calendar_week'])
            // 不建唯一索引：同一本日历同一星期可以有多段（港股上午+下午），
            // 唯一性由「时段不得重叠」的业务校验保证，那不是任何列组合能表达的约束
            ->create();
    }
}
