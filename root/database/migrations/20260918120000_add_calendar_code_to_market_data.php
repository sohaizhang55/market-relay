<?php

use think\migration\Migrator;

/**
 * 新增 market_data.calendar_code —— 这个行情源跟哪套交易作息走（ISO 10383 MIC）
 *
 * 接替上一条迁移（20260918110000）删掉的 coin_list.calendar_code：休市判定的第一个消费方
 * 是采集机上的 market:price_check_expire，它扫的是 market_data，那一层没有 pair 也没有 coin。
 *
 * 取值写在 database/market_data.sql 种子里，与行同生命周期（market_data 全项目没有一处
 * INSERT，行由种子预置）。后台 business/Market 只有只读列表，刻意不给编辑入口。
 *
 * ⚠️ 本迁移后这一列**全部是空串**，且种子尚未补上这一列 —— 34 个 tradfi 标的至今 0 个行情源。
 * 空串 = 24/7 无休市，与今天行为完全一致，零回归。
 *
 * 设计依据：.agents/plan/market_calendar_overview.md §4.12
 */
class AddCalendarCodeToMarketData extends Migrator
{
    /**
     * 用 up/down 而不是 change()：虽然 addColumn 本身可反解，但与上一条迁移
     * （removeColumn，phinx 反解不了）保持同一种写法，免得回滚时一半能退一半不能。
     */
    public function up()
    {
        $this->table('market_data')
            ->addColumn('calendar_code', 'string', [
                'limit'   => 16,
                'default' => '',
                // 空串与 NULL 在这里语义不同：空串明确表示「24/7 无休市」，
                // NULL 是「没人填过」。判定代码不该去区分这两种，所以不允许 NULL
                'null'    => false,
                'after'   => 'source_key',
                'comment' => '该源跟哪套交易作息走 ISO 10383 MIC，空=24/7 无休市，取值见 MarketCalendarEnum',
            ])
            ->addIndex(['calendar_code'], ['name' => 'idx_market_data_calendar_code'])
            ->update();
    }

    public function down()
    {
        $this->table('market_data')
            ->removeIndexByName('idx_market_data_calendar_code')
            ->removeColumn('calendar_code')
            ->update();
    }
}
