<?php

use think\migration\Migrator;
use think\migration\db\Column;

class EnsureMarketSourceKlineUniqueKey extends Migrator
{
    /**
     * Up Method.
     */
    public function up()
    {
        $table = $this->table('market_source_kline');
        
        // 1. 如果没有这个唯一索引，则添加
        if (!$table->hasIndex(['source_key', 'period', 'ts'])) {
            
            // 由于采集进程一直在运行，DELETE 可能会有残留。
            // 直接 TRUNCATE 清空表以确保唯一键能顺利加上。
            $this->execute("TRUNCATE TABLE market_source_kline");

            // 添加唯一索引
            $table->addIndex(['source_key', 'period', 'ts'], [
                'unique' => true, 
                'name'   => 'idx_kline_source_unique'
            ])->save();
        }
    }

    /**
     * Down Method.
     */
    public function down()
    {
        $table = $this->table('market_source_kline');
        if ($table->hasIndex(['source_key', 'period', 'ts'])) {
            $table->removeIndexByName('idx_kline_source_unique')->save();
        }
    }
}
