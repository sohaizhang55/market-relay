<?php

use think\migration\Migrator;

/**
 * AllTick 美股 ETF / 个股 23 个源的 calendar_code 从 XNYS 换成 ALLTICK_XNYS（含盘前盘后）
 *
 * XNYS 只有常规盘 09:30-16:00，AllTick 美股盘前盘后也可交易（纽约 04:00-20:00）。
 * 三个现货指数（.SPX.US / .DJI.US / .IXIC.US）只在常规盘计算，仍用 XNYS，不在此列。
 * 种子是 INSERT IGNORE，改不到存量行，只能靠本迁移。只改仍是 XNYS 或空串的行，后台已手工改过的不覆盖。
 *
 * 设计依据：.agents/plan/market_alltick_calendar.md
 */
class UpdateAlltickUsStockCalendarCode extends Migrator
{
    /** 与 database/market_data.sql 种子一致 */
    private const TICKERS = [
        'spy', 'qqq', 'iwm', 'nvda', 'tsla', 'aapl', 'msft', 'amzn', 'googl', 'meta', 'avgo', 'tsm',
        'amd', 'mu', 'intc', 'orcl', 'nflx', 'baba', 'mstr', 'coin', 'hood', 'crcl', 'pltr',
    ];

    private function sourceKeys(): string
    {
        return "'" . implode("','", array_map(fn($t) => "ALLTICK_{$t}.us_1", self::TICKERS)) . "'";
    }

    public function up()
    {
        $this->execute("UPDATE `market_data` SET `calendar_code` = 'ALLTICK_XNYS'"
            . " WHERE `source_key` IN (" . $this->sourceKeys() . ") AND `calendar_code` IN ('', 'XNYS')");
    }

    public function down()
    {
        $this->execute("UPDATE `market_data` SET `calendar_code` = 'XNYS'"
            . " WHERE `source_key` IN (" . $this->sourceKeys() . ") AND `calendar_code` = 'ALLTICK_XNYS'");
    }
}
