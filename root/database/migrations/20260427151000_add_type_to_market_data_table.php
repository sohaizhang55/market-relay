<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddTypeToMarketDataTable extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('market_data');
        
        // 1. 移除旧的唯一索引
        if ($table->hasIndex(['exchange', 'symbol'])) {
            $table->removeIndexByName('idx_exchange_symbol');
        }
        
        // 2. 添加 type 字段 (1:现货, 2:合约)
        $table->addColumn('type', 'integer', [
            'limit'   => 1,
            'default' => 1,
            'after'   => 'symbol',
            'comment' => '行情类型 (1:现货, 2:合约)'
        ]);
        
        // 3. 添加新的复合唯一索引
        $table->addIndex(['exchange', 'symbol', 'type'], [
            'unique' => true, 
            'name'   => 'idx_exchange_symbol_type'
        ]);
        
        $table->update();
    }
}
