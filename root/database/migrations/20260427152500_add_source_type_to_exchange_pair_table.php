<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddSourceTypeToExchangePairTable extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('exchange_pair');
        $table->addColumn('source_type', 'integer', [
            'limit'   => 1,
            'default' => 1,
            'after'   => 'source_symbol',
            'comment' => '行情来源类型 (1:现货, 2:合约)'
        ])->update();
    }
}
