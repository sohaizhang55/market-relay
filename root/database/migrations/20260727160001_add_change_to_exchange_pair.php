<?php

use think\migration\Migrator;

class AddChangeToExchangePair extends Migrator
{
    public function change()
    {
        $this->table('exchange_pair')
            ->addColumn('change', 'decimal', ['precision' => 12, 'scale' => 4, 'default' => '0.0000', 'after' => 'open', 'comment' => '24h涨跌幅% (随 last_price/open 每次行情落库同步计算)'])
            ->update();
    }
}
