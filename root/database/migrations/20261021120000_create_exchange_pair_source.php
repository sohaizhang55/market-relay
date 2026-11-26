<?php

use think\migration\Migrator;

/**
 * 交易对数据源绑定表 —— 主备切换第一阶段
 *
 * 一个 pair 绑一个主源 + 任意多个备源，按 priority 从小到大排（1 = 主源）。这是**配置**，人在后台改；
 * exchange_pair.source_key 是**状态**（当前生效源），由第二阶段的选源进程维护，本阶段恒等于主源，行为不变。
 * 交易日历仍只挂在源上（market_data.calendar_code），pair 的开休市跟当前生效源走。
 *
 * 本迁移按现有数据生成初始绑定（只补缺，唯一键挡重复，重跑安全）：
 *   主源  priority=1，取 exchange_pair.source_key
 *   备源  priority=2，火币 / 币安之间同 symbol 同类型自动配对：source_key 是 EXCHANGE_symbol_type，
 *         换掉交易所前缀就是对方的同一标的；对方在 market_data 里有这一行才配
 * 新装机走种子 exchange_pair.sql 末尾的同一组语句（InitMarketData 先于 InitExchangePair 跑，见 deploy/init.sh）。
 *
 * 各列显式写 'null' => false：phinx 的 addColumn() 默认建可空列，只给 default 挡不住 NULL。
 */
class CreateExchangePairSource extends Migrator
{
    /** 自动配对备源的交易所，[前缀, 对方前缀] */
    private const PEERS = [['BINANCE_', 'HUOBI_'], ['HUOBI_', 'BINANCE_']];

    public function up()
    {
        $this->table('exchange_pair_source', ['engine' => 'InnoDB', 'comment' => '交易对数据源绑定(主备切换)'])
            ->addColumn('pair_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'exchange_pair.id（只在本机用，不出机器）'])
            ->addColumn('source_key', 'string', ['limit' => 64, 'null' => false, 'comment' => '数据源，对应 market_data.source_key'])
            ->addColumn('priority', 'integer', ['limit' => 10, 'default' => 1, 'null' => false, 'comment' => '优先级 1=主源，越大越靠后；生效源 = 按此排序第一个健康的源'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'null' => false, 'comment' => '状态 1=启用 2=停用，见 CommonStatusEnum；停用的源不参与选源'])
            ->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'null' => false, 'comment' => '备注'])
            ->addColumn('create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0])
            ->addColumn('update_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0])
            // 同一个源不能在一个 pair 下绑两次
            ->addIndex(['pair_id', 'source_key'], ['unique' => true, 'name' => 'uk_pair_source'])
            // 采集热路径按源反查「哪些 pair 绑了我」
            ->addIndex(['source_key'], ['name' => 'idx_pair_source_source_key'])
            ->create();

        foreach (self::seedStatements() as $sql) {
            $this->execute($sql);
        }
    }

    public function down()
    {
        $this->table('exchange_pair_source')->drop()->save();
    }

    /** 初始绑定的生成语句，与种子 exchange_pair.sql 末尾一致 */
    private static function seedStatements(): array
    {
        $out = ["INSERT IGNORE INTO `exchange_pair_source` (`pair_id`, `source_key`, `priority`, `status`, `create_ts`, `update_ts`)"
            . " SELECT `id`, `source_key`, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `exchange_pair`"
            . " WHERE `source_key` IS NOT NULL AND `source_key` <> ''"];

        foreach (self::PEERS as [$from, $to]) {
            $out[] = sprintf(
                "INSERT IGNORE INTO `exchange_pair_source` (`pair_id`, `source_key`, `priority`, `status`, `create_ts`, `update_ts`)"
                . " SELECT p.`id`, m.`source_key`, 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()"
                . " FROM `exchange_pair` p JOIN `market_data` m ON m.`source_key` = CONCAT('%s', SUBSTRING(p.`source_key`, %d))"
                . " WHERE p.`source_key` LIKE '%s%%'",
                $to, strlen($from) + 1, str_replace('_', '\\\\_', $from)
            );
        }
        return $out;
    }
}
