<?php

use think\migration\Migrator;

/**
 * 更新 market_calendar_holiday.close_type 的列注释 —— 补上新增的 3=时段覆盖
 *
 * 建表迁移（20260914100000）写的是「1=全日休市 2=时段调整(半日市)」，当时确实只有两种。
 * 2026-09-18 新增 MarketHolidayTypeEnum::OVERRIDE = 3 后这句就过时了，而列注释是 DBA 与
 * 运维直接 `SHOW CREATE TABLE` 时唯一看得到的说明 —— 不改的话，他们看到 close_type=3
 * 的行会判成脏数据。
 *
 * **为什么单开一条迁移而不是改那条建表迁移**：建表迁移在所有环境都已执行过，phinx 按
 * 版本号记录执行状态、不会因为文件内容变了就重跑。改它只能让新装的环境正确，已有环境
 * 的列注释永远停在旧值 —— 而线上正是已有环境。
 *
 * 三种类型的语义差别见 MarketHolidayTypeEnum 类注释，一句话：
 *   2 是裁剪、只能让当天区间变短；3 是整段替换、可以比常规更长（为真实的延后收盘而设）。
 *
 * 设计依据：.agents/plan/market_calendar_overview.md
 */
class UpdateCloseTypeComment extends Migrator
{
    /**
     * 只动注释，不动类型与默认值 —— tinyint(1) 装得下 3，没有结构变更。
     *
     * 用 change() 而非 up()/down()：phinx 能自行反解 changeColumn 的回滚动作，
     * 而这里回滚的语义（把注释改回只写 1/2）本身就是我们不希望发生的事，
     * 与其写一个会让注释重新失真的 down()，不如让它按默认行为走。
     */
    public function change()
    {
        $this->table('market_calendar_holiday')
            ->changeColumn('close_type', 'integer', [
                'limit'   => 1,
                'default' => 1,
                'null'    => false,
                'comment' => '休市类型 1=全日休市 2=时段调整(裁剪,只能变短) 3=时段覆盖(整段替换,可变长)，见 MarketHolidayTypeEnum',
            ])
            ->update();
    }
}
