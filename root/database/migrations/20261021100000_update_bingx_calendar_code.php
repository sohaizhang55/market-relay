<?php

use think\migration\Migrator;

/**
 * BingX 10 个源的 calendar_code 从空串（= 24/7）换成 BingX 手写日历
 *
 * 种子是 INSERT IGNORE，改不到存量行，只能靠本迁移。只改仍是空串的行，后台已手工改过的不覆盖。
 * 日历数据随 database/market_calendar_holiday.json 由 market:import_calendar 导入。
 *
 * 设计依据：MarketCalendarEnum 的 BingX 组注释
 */
class UpdateBingxCalendarCode extends Migrator
{
    /** source_key => 日历 */
    private const SOURCES = [
        'BINGX_ncfxeur2usdusdt_2'        => 'BINGX_FX',
        'BINGX_ncfxeur2gbpusdt_2'        => 'BINGX_FX',
        'BINGX_ncfxeur2chfusdt_2'        => 'BINGX_FX',
        'BINGX_ncfxusdsgd2usdusdt_2'     => 'BINGX_FX',
        'BINGX_ncfxgbp2usdusdt_2'        => 'BINGX_FX',
        'BINGX_ncfxusd2jpyusdt_2'        => 'BINGX_FX',
        'BINGX_ncfxaud2usdusdt_2'        => 'BINGX_FX',
        'BINGX_ncfxusd2chfusdt_2'        => 'BINGX_FX',
        'BINGX_ncfxusd2cadusdt_2'        => 'BINGX_FX',
        'BINGX_nccoheatingoil2usdusdt_2' => 'BINGX_ENERGY',
    ];

    public function up()
    {
        foreach (self::SOURCES as $sourceKey => $code) {
            $this->execute(sprintf(
                "UPDATE `market_data` SET `calendar_code` = '%s' WHERE `source_key` = '%s' AND `calendar_code` = ''",
                $code, $sourceKey
            ));
        }
    }

    public function down()
    {
        foreach (self::SOURCES as $sourceKey => $code) {
            $this->execute(sprintf(
                "UPDATE `market_data` SET `calendar_code` = '' WHERE `source_key` = '%s' AND `calendar_code` = '%s'",
                $sourceKey, $code
            ));
        }
    }
}
