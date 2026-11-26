<?php

use think\migration\Migrator;
use think\migration\db\Column;

class UniqueSymbolCodeRefactor extends Migrator
{
    /**
     * 重构 symbol_code 为全局唯一
     * - exchange_pair: UNIQUE KEY (symbol_code, type) => UNIQUE KEY (symbol_code)
     * - exchange_pair_kline: 删除 pair_id, 唯一键改为 (symbol_code, period, ts)
     * - exchange_spot_order / exchange_time_order: 新增 symbol 展示冗余列
     *
     * 注意: 执行本迁移前，必须先运行数据迁移:
     *   php think onceTask --task migrate_symbol_code
     */
    public function up()
    {
        // 1. exchange_pair: 唯一键从联合改为单列
        $pairTable = $this->table('exchange_pair');
        if ($pairTable->hasIndex(['symbol_code', 'type'])) {
            $pairTable->removeIndex(['symbol_code', 'type'])->save();
        }
        if (!$pairTable->hasIndex(['symbol_code'])) {
            $pairTable->addIndex(['symbol_code'], ['unique' => true, 'name' => 'symbol_code'])->save();
        }

        // 2. exchange_pair_kline: 删除 pair_id, 改用 symbol_code 唯一键
        $klineTable = $this->table('exchange_pair_kline');

        if ($klineTable->hasIndex(['pair_id', 'period', 'ts'])) {
            $klineTable->removeIndex(['pair_id', 'period', 'ts'])->save();
        }

        if (!$klineTable->hasIndex(['symbol_code', 'period', 'ts'])) {
            $klineTable->addIndex(['symbol_code', 'period', 'ts'], ['unique' => true, 'name' => 'idx_pair_kline_unique'])->save();
        }

        if ($klineTable->hasColumn('pair_id')) {
            $klineTable->removeColumn('pair_id')->save();
        }

        // market-relay：分发机的库没有业务表，订单表不存在就跳过
        if (!$this->hasTable('exchange_spot_order') || !$this->hasTable('exchange_time_order')) return;

        // 3. exchange_spot_order: 新增 symbol 展示冗余列
        $spotOrderTable = $this->table('exchange_spot_order');
        if (!$spotOrderTable->hasColumn('symbol')) {
            $spotOrderTable->addColumn('symbol', 'string', [
                'limit' => 64,
                'default' => '',
                'comment' => '展示名称快照 (如 BTC/USDT)',
                'after' => 'symbol_code',
            ])->save();
        }

        // 4. exchange_time_order: 新增 symbol 展示冗余列
        $timeOrderTable = $this->table('exchange_time_order');
        if (!$timeOrderTable->hasColumn('symbol')) {
            $timeOrderTable->addColumn('symbol', 'string', [
                'limit' => 64,
                'default' => '',
                'comment' => '展示名称快照 (如 BTC/USDT)',
                'after' => 'symbol_code',
            ])->save();
        }
    }

    public function down()
    {
        // 1. exchange_pair: 恢复联合唯一键
        $pairTable = $this->table('exchange_pair');
        if ($pairTable->hasIndex(['symbol_code'])) {
            $pairTable->removeIndex(['symbol_code'])->save();
        }
        $pairTable->addIndex(['symbol_code', 'type'], ['unique' => true, 'name' => 'symbol_code'])->save();

        // 2. exchange_pair_kline: 恢复 pair_id
        $klineTable = $this->table('exchange_pair_kline');

        if (!$klineTable->hasColumn('pair_id')) {
            $klineTable->addColumn('pair_id', 'integer', [
                'limit' => 11,
                'default' => 0,
                'comment' => '交易对ID',
                'after' => 'id',
            ])->save();
        }

        if ($klineTable->hasIndex(['symbol_code', 'period', 'ts'])) {
            $klineTable->removeIndex(['symbol_code', 'period', 'ts'])->save();
        }
        $klineTable->addIndex(['pair_id', 'period', 'ts'], ['unique' => true, 'name' => 'idx_pair_kline_unique'])->save();

        // market-relay：分发机的库没有业务表，订单表不存在就跳过
        if (!$this->hasTable('exchange_spot_order') || !$this->hasTable('exchange_time_order')) return;

        // 3. 移除订单表的 symbol 列
        $spotOrderTable = $this->table('exchange_spot_order');
        if ($spotOrderTable->hasColumn('symbol')) {
            $spotOrderTable->removeColumn('symbol')->save();
        }

        $timeOrderTable = $this->table('exchange_time_order');
        if ($timeOrderTable->hasColumn('symbol')) {
            $timeOrderTable->removeColumn('symbol')->save();
        }
    }
}
