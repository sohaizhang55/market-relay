<?php

use think\migration\Migrator;

/**
 * 作废 AllTick 金属 / 能源 4 本日历借来的假日 —— 改为无假日，节假日照常开市
 *
 * 这 4 本原先借 CMES / IEPA 的假日（20260929100000 那一版导入过）。JSON 里已不再有它们的假日，
 * 而 market:import_calendar 的孤儿清理只扫 JSON 里出现过的日历、且作废比例 100% 会被阈值挡下，
 * 清不掉，只能靠本迁移。只动导入写入的行（source=exchange_calendars），后台手工加的不碰；
 * 作废是 status=2 不是删除。没导入过的机器上影响 0 行。
 *
 * 设计依据：.agents/plan/market_alltick_calendar.md
 */
class ClearAlltickBorrowedHolidays extends Migrator
{
    private const CODES = "'ALLTICK_METAL','ALLTICK_WTI','ALLTICK_BRENT','ALLTICK_BASE'";

    public function up()
    {
        $this->execute("UPDATE `market_calendar_holiday` SET `status` = 2, `update_ts` = UNIX_TIMESTAMP()"
            . " WHERE `calendar_code` IN (" . self::CODES . ") AND `source` = 'exchange_calendars' AND `status` = 1");
    }

    public function down()
    {
        $this->execute("UPDATE `market_calendar_holiday` SET `status` = 1, `update_ts` = UNIX_TIMESTAMP()"
            . " WHERE `calendar_code` IN (" . self::CODES . ") AND `source` = 'exchange_calendars' AND `status` = 2");
    }
}
