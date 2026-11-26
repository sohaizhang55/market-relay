<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateMarketDataTable extends Migrator
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
    public function change()
    {
        $table = $this->table('market_data', ['engine' => 'InnoDB', 'comment' => '币种行情数据表']);
        $table->addColumn('exchange', 'string', ['limit' => 64, 'default' => 'huobi', 'comment' => '交易所代码 (如 huobi)'])
            ->addColumn('symbol', 'string', ['limit' => 64, 'comment' => '币种代码 (如 xrpusdt)'])
            ->addColumn('open', 'decimal', ['precision' => 20, 'scale' => 8, 'default' => 0, 'comment' => '24h开盘价'])
            ->addColumn('close', 'decimal', ['precision' => 20, 'scale' => 8, 'default' => 0, 'comment' => '当前价 (24h收盘价)'])
            ->addColumn('low', 'decimal', ['precision' => 20, 'scale' => 8, 'default' => 0, 'comment' => '24h最低价'])
            ->addColumn('high', 'decimal', ['precision' => 20, 'scale' => 8, 'default' => 0, 'comment' => '24h最高价'])
            ->addColumn('amount', 'decimal', ['precision' => 20, 'scale' => 8, 'default' => 0, 'comment' => '24h成交额(USDT)'])
            ->addColumn('vol', 'decimal', ['precision' => 20, 'scale' => 8, 'default' => 0, 'comment' => '24h成交量'])
            ->addColumn('count', 'integer', ['default' => 0, 'comment' => '24h成交笔数'])
            ->addColumn('update_ts', 'biginteger', ['default' => 0, 'comment' => '火币原始毫秒级时间戳'])
            ->addColumn('create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
            ->addColumn('update_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP', 'comment' => '更新时间'])
            ->addIndex(['exchange', 'symbol'], ['unique' => true, 'name' => 'idx_exchange_symbol'])
            ->create();
    }
}
