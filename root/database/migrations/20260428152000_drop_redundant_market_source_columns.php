<?php

use think\migration\Migrator;
use think\migration\db\Column;

class DropRedundantMarketSourceColumns extends Migrator
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
        $table = $this->table('exchange_pair');
        
        // 删除冗余字段
        if ($table->hasColumn('source_exchange')) {
            $table->removeColumn('source_exchange')->save();
        }
        if ($table->hasColumn('source_symbol')) {
            $table->removeColumn('source_symbol')->save();
        }
        if ($table->hasColumn('source_type')) {
            $table->removeColumn('source_type')->save();
        }
    }

    public function down()
    {
        $table = $this->table('exchange_pair');
        $table->addColumn('source_exchange', 'string', ['limit' => 20, 'default' => '', 'comment' => '行情来源交易所'])
              ->addColumn('source_symbol', 'string', ['limit' => 20, 'default' => '', 'comment' => '行情来源符号'])
              ->addColumn('source_type', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '行情来源类型'])
              ->save();
    }
}
