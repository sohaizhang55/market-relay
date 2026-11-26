<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateExchangePairKlineTable extends Migrator
{
    public function change()
    {
        if (!$this->hasTable('exchange_pair_kline')) {
            $table = $this->table('exchange_pair_kline', ['comment' => '行情K线数据表']);
            $table->addColumn('symbol_code', 'string', ['limit' => 64, 'default' => '', 'comment' => '交易对代码 (例 btcusdt)'])
                ->addColumn('period', 'string', ['limit' => 10, 'default' => '1m', 'comment' => 'K线周期 (1m,5m,1h,1d等)'])
                ->addColumn('open', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '开盘价'])
                ->addColumn('high', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '最高价'])
                ->addColumn('low', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '最低价'])
                ->addColumn('close', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '收盘价'])
                ->addColumn('vol', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '成交量'])
                ->addColumn('amount', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '成交额'])
                ->addColumn('ts', 'biginteger', ['limit' => 20, 'default' => 0, 'comment' => '起始时间戳 (毫秒)'])

                // 索引
                ->addIndex(['symbol_code', 'period', 'ts'], ['unique' => true, 'name' => 'idx_pair_kline_unique'])
                ->addIndex(['ts'], ['name' => 'idx_kline_ts'])
                ->create();
        }
    }
}
