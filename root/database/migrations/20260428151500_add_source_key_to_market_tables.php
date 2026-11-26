<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddSourceKeyToMarketTables extends Migrator
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
     * automatically reverse them:
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
        // 1. 为 market_data 添加 source_key
        $marketData = $this->table('market_data');
        if (!$marketData->hasColumn('source_key')) {
            $marketData->addColumn('source_key', 'string', [
                'limit'   => 64,
                'default' => NULL,
                'comment' => '数据源唯一键 (PROVIDER_SYMBOL_TYPE)'
            ])->save();


            // 数据填充后再加唯一索引
            $marketData->addIndex(['source_key'], ['unique' => true])->save();
        }

        // 2. 为 exchange_pair 添加 source_key
        $exchangePair = $this->table('exchange_pair');
        if (!$exchangePair->hasColumn('source_key')) {
            $exchangePair->addColumn('source_key', 'string', [
                'limit'   => 64,
                'default' => NULL,
                'comment' => '行情源唯一键 (对应 market_data.source_key)'
            ])->save();

            // 增加普通索引
            $exchangePair->addIndex(['source_key'])->save();
        }
    }

    public function down()
    {
        $this->table('market_data')->removeColumn('source_key')->save();
        $this->table('exchange_pair')->removeColumn('source_key')->save();
    }
}
