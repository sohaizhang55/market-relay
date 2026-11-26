<?php

use think\migration\Migrator;

/**
 * 删除 AllTick 行情源 IWM.US（ALLTICK_iwm.us_1）—— AllTick 一直取不到它的数据
 *
 * 种子已删掉这一行，但种子是 INSERT IGNORE，删不掉存量行，只能靠本迁移。
 * 连同它的源层 K 线一起删；exchange_pair 与 coin_list 不动（业务配置，下架与否由后台决定）。
 */
class RemoveAlltickIwmSource extends Migrator
{
    private const SOURCE_KEY = 'ALLTICK_iwm.us_1';

    public function up()
    {
        $this->execute("DELETE FROM `market_source_kline` WHERE `source_key` = '" . self::SOURCE_KEY . "'");
        $this->execute("DELETE FROM `market_data` WHERE `source_key` = '" . self::SOURCE_KEY . "'");
    }

    /** 只恢复源行（取值与删除前的种子一致），K 线由 market:backfill 重新补 */
    public function down()
    {
        $this->execute("INSERT IGNORE INTO `market_data`"
            . " (`exchange`, `symbol`, `type`, `source_key`, `calendar_code`, `category_code`, `create_at`, `update_at`)"
            . " VALUES ('alltick', 'IWM.US', 1, '" . self::SOURCE_KEY . "', 'ALLTICK_XNYS', 'index', NOW(), NOW())");
    }
}
