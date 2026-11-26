<?php

use think\migration\Migrator;

class AddBboFieldsToMarketData extends Migrator
{
    public function change()
    {
        $table = $this->table('market_data');

        $table->addColumn('bbo_bid', 'decimal', [
            'precision' => 20,
            'scale'     => 8,
            'null'      => false,
            'default'   => '0.00000000',
            'after'     => 'source_key',
            'comment'   => '最优买一价 (BBO，影子模式，暂不参与定价)',
        ]);

        $table->addColumn('bbo_ask', 'decimal', [
            'precision' => 20,
            'scale'     => 8,
            'null'      => false,
            'default'   => '0.00000000',
            'after'     => 'bbo_bid',
            'comment'   => '最优卖一价 (BBO，影子模式，暂不参与定价)',
        ]);

        $table->addColumn('bbo_ts', 'biginteger', [
            'null'      => false,
            'default'   => 0,
            'after'     => 'bbo_ask',
            'comment'   => 'BBO 原始毫秒级时间戳',
        ]);

        $table->save();
    }
}
