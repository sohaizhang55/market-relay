<?php

use think\migration\Migrator;

class DropBboFieldsFromMarketData extends Migrator
{
    public function up()
    {
        // ------------------------------------------------------------------
        // 下线 BBO 影子模式：market_data 的 bbo_bid / bbo_ask / bbo_ts 一并删除
        //
        // BBO 自 2026-07-10 起以影子模式采集 (只落库 + 打 bbo_shadow 偏差日志)，
        // 从未接入任何定价链路：last_price 由 ticker close 驱动、mark_price 独立采集、
        // 两个 MarketMaker 用 depth 的 ts 判新鲜度，三条链路都不读这三个字段；
        // 分发机的 6 个频道 (price/kline/depth/trade/mark_price/funding) 里也没有 BBO 通道，
        // 所以 B/C 机上这三列一直是 0。
        //
        // 影子期观察结论已取得，故连同 WS 订阅、落库入口、后台展示列一起下线：
        // 每个 BBO tick 原本都要多查一次 exchange_pair 并写一行日志，而 BBO 是所有频道里
        // 最高频的 (逐笔盘口变化即推)，这部分开销全部省掉。
        // ------------------------------------------------------------------
        $table = $this->table('market_data');
        if ($table->hasColumn('bbo_bid')) {
            $table->removeColumn('bbo_bid')->save();
        }

        $table = $this->table('market_data');
        if ($table->hasColumn('bbo_ask')) {
            $table->removeColumn('bbo_ask')->save();
        }

        $table = $this->table('market_data');
        if ($table->hasColumn('bbo_ts')) {
            $table->removeColumn('bbo_ts')->save();
        }
    }

    public function down()
    {
        // 按 20260710150000_add_bbo_fields_to_market_data 的原定义重建。
        // 注意：只恢复列结构，历史数据不会回来；且 after 定位沿用原来的 source_key，
        // 回滚后三列会插在 source_key 之后 (即 mark_price 等列之前)，与删除前的物理顺序
        // 不完全一致，不影响读写。
        $table = $this->table('market_data');
        if (!$table->hasColumn('bbo_bid')) {
            $table->addColumn('bbo_bid', 'decimal', [
                'precision' => 20,
                'scale'     => 8,
                'null'      => false,
                'default'   => '0.00000000',
                'after'     => 'source_key',
                'comment'   => '最优买一价 (BBO，影子模式，暂不参与定价)',
            ])->save();
        }

        $table = $this->table('market_data');
        if (!$table->hasColumn('bbo_ask')) {
            $table->addColumn('bbo_ask', 'decimal', [
                'precision' => 20,
                'scale'     => 8,
                'null'      => false,
                'default'   => '0.00000000',
                'after'     => 'bbo_bid',
                'comment'   => '最优卖一价 (BBO，影子模式，暂不参与定价)',
            ])->save();
        }

        $table = $this->table('market_data');
        if (!$table->hasColumn('bbo_ts')) {
            $table->addColumn('bbo_ts', 'biginteger', [
                'null'      => false,
                'default'   => 0,
                'after'     => 'bbo_ask',
                'comment'   => 'BBO 原始毫秒级时间戳',
            ])->save();
        }
    }
}
