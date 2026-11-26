<?php

use think\migration\Migrator;
use think\migration\db\Column;

class RestructureMarketKlineToExchangePairKline extends Migrator
{
    /**
     * Change Method.
     */
    public function up()
    {
        // 1. 重命名表 (如果存在)
        if ($this->hasTable('market_kline')) {
            $this->table('market_kline')->rename('exchange_pair_kline')->save();
        }

        $table = $this->table('exchange_pair_kline');

        // 检查是否已经存在 pair_id
        if (!$table->hasColumn('pair_id')) {
            $table->addColumn('pair_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '交易对ID', 'after' => 'id'])
                  ->save();
        }

        // 2. 数据迁移: 根据 symbol_code 和之前的 market_type (如果还存在) 映射 pair_id
        if ($table->hasColumn('market_type')) {
            $this->execute("UPDATE exchange_pair_kline k INNER JOIN exchange_pair p ON k.symbol_code = p.symbol_code AND k.market_type = p.type SET k.pair_id = p.id");
        }

        // 3. 清理无法匹配到 Pair 的脏数据 (pair_id 仍为 0 的记录)，防止索引冲突
        $this->execute("DELETE FROM exchange_pair_kline WHERE pair_id = 0");

        // 4. 重新整理索引
        // 移除旧索引
        if ($table->hasIndex(['symbol_code', 'period', 'ts'])) {
             $table->removeIndex(['symbol_code', 'period', 'ts'])->save();
        }

        if ($table->hasIndex(['symbol_code', 'market_type', 'period', 'ts'])) {
             $table->removeIndex(['symbol_code', 'market_type', 'period', 'ts'])->save();
        }

        // 添加新唯一索引
        if (!$table->hasIndex(['pair_id', 'period', 'ts'])) {
            $table->addIndex(['pair_id', 'period', 'ts'], ['unique' => true, 'name' => 'idx_pair_kline_unique'])
                  ->save();
        }
        
        if (!$table->hasIndex(['ts'])) {
            $table->addIndex(['ts'], ['name' => 'idx_pair_kline_ts'])
                  ->save();
        }

        // 4. 移除不再需要的冗余字段
        if ($table->hasColumn('market_type')) {
            $table->removeColumn('market_type')->save();
        }
    }

    public function down()
    {
        // 回滚逻辑 (重命名回去)
        if ($this->hasTable('exchange_pair_kline')) {
            $this->table('exchange_pair_kline')->rename('market_kline')->save();
        }
    }
}
