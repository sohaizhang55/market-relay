<?php

use think\migration\Migrator;
use think\migration\db\Column;

class RefactorToPairId extends Migrator
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function up()
    {
        // 1. 修改 exchange_pair 表：保持 id 不变，仅更新索引
        $table = $this->table('exchange_pair');
        if ($table->hasIndex(['symbol_code'])) {
            $table->removeIndex(['symbol_code'])->update();
        }
        $table->addIndex(['symbol_code', 'type'], ['unique' => true, 'name' => 'idx_symbol_type'])->update();

        // 2. 需要处理的关联表清单
        $configTables = ['exchange_spot', 'exchange_time', 'exchange_contract'];
        $dataTables = ['exchange_spot_order', 'exchange_spot_trade', 'exchange_time_order', 'exchange_time_risk', 'exchange_pair_kline'];

        // 处理配置表 (需要唯一索引)
        foreach ($configTables as $tableName) {
            // market-relay：分发机的库没有业务表，表不存在就跳过
            if (!$this->hasTable($tableName)) continue;
            $t = $this->table($tableName);
            if ($t->hasColumn('symbol_code')) { $t->removeColumn('symbol_code'); }
            if (!$t->hasColumn('pair_id')) {
                $t->addColumn('pair_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '交易对ID']);
            }
            $t->addIndex(['pair_id'], ['unique' => true, 'name' => 'idx_pair_id'])->update();
        }

        // 处理数据表 (需要普通索引)
        foreach ($dataTables as $tableName) {
            // market-relay：分发机的库没有业务表，表不存在就跳过
            if (!$this->hasTable($tableName)) continue;
            $t = $this->table($tableName);
            if ($t->hasColumn('symbol_code')) { $t->removeColumn('symbol_code'); }
            if (!$t->hasColumn('pair_id')) {
                $t->addColumn('pair_id', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '交易对ID']);
            }
            $t->addIndex(['pair_id'], ['name' => 'idx_pair_id'])->update();
        }
    }

    public function down()
    {
        // 由于用户明确表示数据不需要迁移且会清空，回滚逻辑可以暂不实现，或者按需还原
    }
}
