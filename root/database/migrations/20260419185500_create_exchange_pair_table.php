<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateExchangePairTable extends Migrator
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
        $table = $this->table('exchange_pair', ['comment' => '交易对配置表']);
        $table->addColumn('symbol', 'string', ['limit' => 64, 'default' => '', 'comment' => '展示名称 (例 BTC/USDT)'])
            ->addColumn('symbol_code', 'string', ['limit' => 64, 'default' => '', 'comment' => '平台内部代码 (例 btcusdt)'])
            ->addColumn('type', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '交易类型 (1:现货, 2:合约)'])
            ->addColumn('base_coin', 'string', ['limit' => 32, 'default' => '', 'comment' => '基础币种 (例 BTC)'])
            ->addColumn('quote_coin', 'string', ['limit' => 32, 'default' => '', 'comment' => '计价币种 (例 USDT)'])
            ->addColumn('source_exchange', 'string', ['limit' => 64, 'default' => 'huobi', 'comment' => '行情来源交易所 (huobi,binance,okx)'])
            ->addColumn('source_symbol', 'string', ['limit' => 64, 'default' => '', 'comment' => '来源交易所的原始代码 (例 btcusdt)'])

            // 行情快照字段
            ->addColumn('last_price', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '最新成交价'])
            ->addColumn('open', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '24h开盘价'])
            ->addColumn('high', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '24h最高价'])
            ->addColumn('low', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '24h最低价'])
            ->addColumn('close', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '24h收盘价'])
            ->addColumn('amount', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '24h成交额'])
            ->addColumn('vol', 'decimal', ['precision' => 30, 'scale' => 10, 'default' => 0.0, 'comment' => '24h成交量'])

            // 业务时间戳 (Ticker Time)
            ->addColumn('market_ts', 'biginteger', ['default' => 0, 'comment' => '行情原始时间戳 (毫秒)'])

            // 精度与状态 (遵循 Rule 08: 1为ON, 2为OFF)
            ->addColumn('precision_price', 'integer', ['limit' => 4, 'default' => 2, 'comment' => '价格精度'])
            ->addColumn('precision_amount', 'integer', ['limit' => 4, 'default' => 4, 'comment' => '数量精度'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => '状态 (1:开启-ON, 2:禁用-OFF)'])
            ->addColumn('sort', 'integer', ['limit' => 11, 'default' => 0, 'comment' => '排序权重'])

            // 审计记录规范 (遵循 Rule 14: datetime 用 _at, int10 用 _ts)
            ->addColumn('create_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建日期'])
            ->addColumn('update_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'comment' => '更新日期'])
            ->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'comment' => '创建秒级时间戳'])
            ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'comment' => '更新秒级时间戳'])

            ->addIndex(['symbol_code', 'type'], ['unique' => true])
            ->addIndex(['source_exchange', 'source_symbol'])
            ->create();
    }
}
