<?php

use think\migration\Migrator;

class AddMarkPriceToMarketData extends Migrator
{
    public function change()
    {
        $table = $this->table('market_data');

        $table->addColumn('mark_price', 'decimal', [
            'precision' => 20,
            'scale'     => 8,
            'null'      => false,
            'default'   => '0.00000000',
            'after'     => 'bbo_ts',
            'comment'   => '标记价格 (暂等于成交价 close，独立存字段方便以后单独演进算法)',
        ]);

        $table->save();
    }
}
