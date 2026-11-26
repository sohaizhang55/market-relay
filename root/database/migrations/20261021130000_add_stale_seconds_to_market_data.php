<?php

use think\migration\Migrator;

/**
 * market_data 新增 ticker / depth 断流秒数 —— 主备切换判断「这个源断流了」的阈值
 *
 * 挂在源上而不是绑定表上：秒数描述的是源本身的推送习惯（某交易所的某个币多久推一帧），
 * 与它被哪个 pair 拿去用无关，同一个源只该有一个答案（与 calendar_code 同理）。
 *
 * 两路分开判：深度断流 = 连接或订阅坏了；ticker 断流 = 价格那一路单独坏了而深度还在推。
 * 新鲜度来自 MarketSourceHealth（只记 WS 帧，REST 兜底不算）。0 = 这一路不判。
 *
 * 种子 market_data.sql 的 INSERT 不列这两列，新行一律取默认值；要单独调的源改种子或另写迁移。
 */
class AddStaleSecondsToMarketData extends Migrator
{
    public function up()
    {
        $this->table('market_data')
            ->addColumn('ticker_stale_sec', 'integer', [
                'default' => 30,
                'null'    => false,
                'after'   => 'category_code',
                'comment' => 'ticker 断流秒数：超过这么久没收到 WS 价格帧判该源不健康，0=不判',
            ])
            ->addColumn('depth_stale_sec', 'integer', [
                'default' => 15,
                'null'    => false,
                'after'   => 'ticker_stale_sec',
                'comment' => '深度断流秒数：超过这么久没收到 WS 深度帧判该源不健康，0=不判',
            ])
            ->update();
    }

    public function down()
    {
        $this->table('market_data')
            ->removeColumn('ticker_stale_sec')
            ->removeColumn('depth_stale_sec')
            ->update();
    }
}
