<?php

use think\migration\Migrator;

/**
 * market_data.source_key 改为 NOT NULL
 *
 * 唯一索引允许多个 NULL，漏填 source_key 的行能绕过去重，与已有源归一成同一 key 后互相覆盖。
 * 先按 MarketHelper::makeSourceKey 的规则回填空值，回填后仍有空值（symbol 缺失）则中止。
 */
class SetMarketDataSourceKeyNotNull extends Migrator
{
    public function up()
    {
        // 与已有 source_key 撞车时 UPDATE 报 Duplicate entry 整句回滚，正是要暴露的重复源
        $this->execute(
            "UPDATE `market_data` SET `source_key` = CONCAT(
                UPPER(`exchange`), '_',
                LOWER(REPLACE(REPLACE(REPLACE(`symbol`, '-', ''), '_', ''), '/', '')), '_',
                `type`
            ) WHERE `source_key` IS NULL OR `source_key` = ''"
        );

        $left = $this->fetchAll("SELECT `id` FROM `market_data` WHERE `source_key` IS NULL OR `source_key` = '' LIMIT 20");
        if ($left) {
            throw new \RuntimeException('market_data 存在无法回填 source_key 的行，id: ' . implode(',', array_column($left, 'id')));
        }

        $this->execute(
            "ALTER TABLE `market_data` MODIFY `source_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '数据源唯一键 (PROVIDER_SYMBOL_TYPE)'"
        );
    }

    public function down()
    {
        $this->execute(
            "ALTER TABLE `market_data` MODIFY `source_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '数据源唯一键 (PROVIDER_SYMBOL_TYPE)'"
        );
    }
}
