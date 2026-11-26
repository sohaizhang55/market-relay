<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddSourceKeyToSourceKlineTable extends Migrator
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     */
    public function up()
    {
        $table = $this->table('market_source_kline');
        
        if (!$table->hasColumn('source_key')) {

            // 1. 清理旧数据 (结构更动时清空)
            $this->execute("TRUNCATE TABLE market_source_kline");

            // 2. 添加 source_key 字段
            $table->addColumn('source_key', 'string', [
                'limit'   => 64,
                'default' => '',
                'after'   => 'id',
                'comment' => '行情源唯一键 (PROVIDER_SYMBOL_TYPE)'
            ])->save();

            // 3. 移除旧索引 (增加存在性检查)，添加基于 source_key 的新索引
            if ($table->hasIndex(['exchange', 'symbol', 'type', 'period', 'ts'])) {
                $table->removeIndexByName('idx_main_kline_lookup')->save();
            }

            $table->addIndex(['source_key', 'period', 'ts'], [
                'unique' => true, 
                'name'   => 'idx_kline_source_lookup'
            ])->save();

        }
    }

    public function down()
    {
        $table = $this->table('market_source_kline');
        
        // 回滚操作：仅移除新加的索引和字段
        if ($table->hasIndex(['source_key', 'period', 'ts'])) {
            $table->removeIndexByName('idx_kline_source_lookup')->save();
        }
        $table->addIndex(['exchange', 'symbol', 'type', 'period', 'ts'], [
            'unique' => true, 
            'name'   => 'idx_main_kline_lookup'
        ])->save();
        
        $table->removeColumn('source_key')->save();
    }
}
