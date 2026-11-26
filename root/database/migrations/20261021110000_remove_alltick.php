<?php

use think\migration\Migrator;

/**
 * AllTick 下线：删掉它的行情源与源层 K 线，作废 5 本 ALLTICK_* 手写日历
 *
 * 种子与代码已删（采集器、REST 客户端、交易所枚举、日历枚举），但种子是 INSERT IGNORE，
 * 删不掉存量行，只能靠本迁移。外汇 / 金属能源已由 BingX、币安接手。
 *
 *   market_data / market_source_kline   物理删除，同 20260930110000_remove_alltick_iwm_source
 *     例外：还有 exchange_pair 绑着的源**跳过不删**并打印 —— 删了那个 pair 就静默断流。
 *     这种源要先在后台把 pair 改绑或下架，再手工删
 *   market_calendar_holiday / _session  作废（status=2），不分 source：日历枚举里已没有这 5 本，
 *     后台手工加的调休也一并失去意义。import_calendar 的孤儿清理只扫 JSON 里出现过的日历，清不到它们
 *
 * down() 只恢复日历的 status；源行与 K 线不恢复（要回滚请从 git 历史取回种子重跑，K 线由 market:backfill 重补）。
 */
class RemoveAlltick extends Migrator
{
    private const CODES = "'ALLTICK_METAL','ALLTICK_WTI','ALLTICK_BRENT','ALLTICK_BASE','ALLTICK_XNYS'";

    public function up()
    {
        $bound = array_column($this->fetchAll(
            "SELECT DISTINCT p.`source_key` FROM `exchange_pair` p"
            . " JOIN `market_data` m ON m.`source_key` = p.`source_key` WHERE m.`exchange` = 'alltick'"
        ), 'source_key');

        $skip = $bound ? " AND `source_key` NOT IN ('" . implode("','", array_map('addslashes', $bound)) . "')" : '';
        $this->execute("DELETE FROM `market_source_kline` WHERE `source_key` IN"
            . " (SELECT `source_key` FROM (SELECT `source_key` FROM `market_data` WHERE `exchange` = 'alltick'{$skip}) t)");
        $this->execute("DELETE FROM `market_data` WHERE `exchange` = 'alltick'{$skip}");

        foreach (['market_calendar_holiday', 'market_calendar_session'] as $table) {
            $this->execute("UPDATE `{$table}` SET `status` = 2, `update_ts` = UNIX_TIMESTAMP()"
                . " WHERE `calendar_code` IN (" . self::CODES . ") AND `status` = 1");
        }

        foreach ($bound as $sourceKey) {
            echo "  [skip] {$sourceKey} 仍有 exchange_pair 绑定，未删除 —— 先在后台改绑或下架该 pair\n";
        }
    }

    public function down()
    {
        foreach (['market_calendar_holiday', 'market_calendar_session'] as $table) {
            $this->execute("UPDATE `{$table}` SET `status` = 1, `update_ts` = UNIX_TIMESTAMP()"
                . " WHERE `calendar_code` IN (" . self::CODES . ") AND `status` = 2");
        }
    }
}
