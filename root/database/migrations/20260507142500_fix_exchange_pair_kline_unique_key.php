<?php

use think\migration\Migrator;
use think\migration\db\Column;

class FixExchangePairKlineUniqueKey extends Migrator
{
    public function up()
    {
        $table = $this->table('exchange_pair_kline');
        
        // 1. 移除那个错误的、缺少币种标识的索引
        if ($table->hasIndex(['period', 'ts'])) {
            $table->removeIndexByName('idx_pair_kline_unique')->save();
        }

        // 2. 添加正确的、包含 pair_id 的唯一索引
        if (!$table->hasIndex(['pair_id', 'period', 'ts'])) {
            
            // 同样，为了防止索引创建失败，先清空这个表（因为里面的数据已经全乱了）
            $this->execute("TRUNCATE TABLE exchange_pair_kline");

            $table->addIndex(['pair_id', 'period', 'ts'], [
                'unique' => true, 
                'name'   => 'idx_pair_period_ts_unique'
            ])->save();
        }
    }

    public function down()
    {
        $table = $this->table('exchange_pair_kline');
        if ($table->hasIndex(['pair_id', 'period', 'ts'])) {
            $table->removeIndexByName('idx_pair_period_ts_unique')->save();
        }
    }
}
