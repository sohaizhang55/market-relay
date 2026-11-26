<?php

use think\migration\Migrator;

/**
 * AllTick 金属 / 能源 8 个源的 calendar_code 从 TBD_* 占位换成 ALLTICK_* 手写日历
 *
 * 种子是 INSERT IGNORE，改不到存量行，只能靠本迁移。只改仍是占位或空串的行，后台已手工改过的不覆盖。
 * 日历数据随 database/market_calendar_holiday.json 由 market:import_calendar 导入。
 *
 * 设计依据：.agents/plan/market_alltick_calendar.md
 */
class UpdateAlltickCalendarCode extends Migrator
{
    /** source_key => [新日历, 原占位] */
    private const SOURCES = [
        'ALLTICK_gold_1'      => ['ALLTICK_METAL', 'TBD_METAL'],
        'ALLTICK_silver_1'    => ['ALLTICK_METAL', 'TBD_METAL'],
        'ALLTICK_platinum_1'  => ['ALLTICK_METAL', 'TBD_METAL'],
        'ALLTICK_palladium_1' => ['ALLTICK_METAL', 'TBD_METAL'],
        'ALLTICK_copper_1'    => ['ALLTICK_BASE',  'TBD_BASE'],
        'ALLTICK_usoil_1'     => ['ALLTICK_WTI',   'TBD_ENERGY'],
        'ALLTICK_ukoil_1'     => ['ALLTICK_BRENT', 'TBD_ENERGY'],
        'ALLTICK_ngas_1'      => ['ALLTICK_METAL', 'TBD_ENERGY'],
    ];

    public function up()
    {
        foreach (self::SOURCES as $sourceKey => [$code, $placeholder]) {
            $this->execute(sprintf(
                "UPDATE `market_data` SET `calendar_code` = '%s' WHERE `source_key` = '%s' AND `calendar_code` IN ('', '%s')",
                $code, $sourceKey, $placeholder
            ));
        }
    }

    public function down()
    {
        foreach (self::SOURCES as $sourceKey => [$code, $placeholder]) {
            $this->execute(sprintf(
                "UPDATE `market_data` SET `calendar_code` = '%s' WHERE `source_key` = '%s' AND `calendar_code` = '%s'",
                $placeholder, $sourceKey, $code
            ));
        }
    }
}
