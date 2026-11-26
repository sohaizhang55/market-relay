<?php

use think\migration\Migrator;

class AddMarkPriceToExchangePair extends Migrator
{
    public function change()
    {
        $table = $this->table('exchange_pair');

        $table->addColumn('mark_price', 'decimal', [
            'precision' => 30,
            'scale'     => 10,
            'null'      => false,
            'default'   => '0.0000000000',
            'after'     => 'last_price',
            'comment'   => '标记价格 (暂等于 last_price，独立存字段方便以后单独演进算法，供强平判断使用)',
        ]);

        $table->save();
    }
}
