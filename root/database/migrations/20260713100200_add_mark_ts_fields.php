<?php

use think\migration\Migrator;

class AddMarkTsFields extends Migrator
{
    public function change()
    {
        $marketData = $this->table('market_data');
        $marketData->addColumn('mark_ts', 'biginteger', [
            'null'      => false,
            'default'   => 0,
            'after'     => 'mark_price',
            'comment'   => '标记价格原始毫秒级时间戳',
        ]);
        $marketData->save();

        $exchangePair = $this->table('exchange_pair');
        $exchangePair->addColumn('mark_ts', 'biginteger', [
            'null'      => false,
            'default'   => 0,
            'after'     => 'mark_price',
            'comment'   => '标记价格原始毫秒级时间戳，独立于 market_ts 用于判断 mark_price 是否过期',
        ]);
        $exchangePair->save();
    }
}
