<?php

use think\migration\Migrator;

/**
 * 后台"原始 K 线采集"列表页 (Market::source_kline()) 默认无筛选条件按 ts desc 排序，
 * market_source_kline 原本只有 (source_key, period, ts) 联合唯一索引，排序列 ts 不在
 * 索引最左侧，MySQL 无法用该索引排序。EXPLAIN 验证过：96571 行时无筛选查询 rows=87611，
 * type=ALL + Using filesort，带 period 筛选时同样全表扫描。表还在持续增长(暂无清理机制，
 * 见项目记忆 project_kline_backfill_mechanism)，只会越来越慢。
 * 补两条索引：
 *   - idx_msk_ts：覆盖默认无筛选按 ts desc 排序（后台列表页最常见的打开方式）
 *   - idx_msk_period_ts：覆盖按 period 筛选 + ts desc 排序（切换周期下拉筛选）
 */
class AddTsIndexToMarketSourceKline extends Migrator
{
    public function change()
    {
        $table = $this->table('market_source_kline');
        $table
            ->addIndex(['ts'], ['name' => 'idx_msk_ts'])
            ->addIndex(['period', 'ts'], ['name' => 'idx_msk_period_ts'])
            ->update();
    }
}
