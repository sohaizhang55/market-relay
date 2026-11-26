<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateMarketSourceKlineTable extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('market_source_kline', ['engine' => 'InnoDB', 'comment' => '原始交易所K线数据表']);
        $table->addColumn('symbol', 'string', ['limit' => 64, 'comment' => '币种代码 (例 btcusdt)'])
            ->addColumn('exchange', 'string', ['limit' => 64, 'comment' => '交易所代码 (例 huobi)'])
            ->addColumn('type', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '行情类型 (1:现货, 2:合约)'])
            ->addColumn('period', 'string', ['limit' => 10, 'comment' => 'K 线周期 (1min, 5min, etc.)'])
            ->addColumn('open', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0, 'comment' => '开盘价'])
            ->addColumn('high', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0, 'comment' => '最高价'])
            ->addColumn('low', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0, 'comment' => '最低价'])
            ->addColumn('close', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0, 'comment' => '收盘价'])
            ->addColumn('vol', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0, 'comment' => '成交量'])
            ->addColumn('amount', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0, 'comment' => '成交额'])
            ->addColumn('ts', 'biginteger', ['comment' => 'K 线起始时间戳（毫秒）'])
            ->addColumn('create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
            ->addIndex(['exchange', 'symbol', 'type', 'period', 'ts'], ['unique' => true, 'name' => 'idx_main_kline_lookup'])
            ->create();
    }
}
