<?php

use think\migration\Migrator;

/**
 * 新增 market_data.category_code —— 行情源的品类（加密 / 指数 / 金属 / 能源）
 *
 * type 是现货 / 合约，且编进了 source_key，不能再塞品类。取值沿用 coin_category.code，
 * 但口径是「这个源交易的是什么」：火币/币安上都是代币（XAUT、TSLAX 也是），一律 crypto；
 * 只有 alltick 是传统市场。coin_list.category_code 按代币挂钩的资产分类，两者不同，不从那边回填。
 */
class AddCategoryCodeToMarketData extends Migrator
{
    /** alltick 源的品类，与 database/market_data.sql 种子一致 */
    private const ALLTICK = [
        'index' => [
            'ALLTICK_.spx.us_1', 'ALLTICK_.dji.us_1', 'ALLTICK_.ixic.us_1',
            'ALLTICK_hsi.hk_1', 'ALLTICK_hscei.hk_1', 'ALLTICK_hstech.hk_1',
        ],
        'metal' => [
            'ALLTICK_gold_1', 'ALLTICK_silver_1', 'ALLTICK_platinum_1', 'ALLTICK_palladium_1', 'ALLTICK_copper_1',
        ],
        'energy' => [
            'ALLTICK_usoil_1', 'ALLTICK_ukoil_1', 'ALLTICK_ngas_1',
        ],
    ];

    public function up()
    {
        $this->table('market_data')
            ->addColumn('category_code', 'string', [
                'limit'   => 20,
                'default' => 'crypto',
                'null'    => false,
                'after'   => 'calendar_code',
                'comment' => '行情源品类 → coin_category.code',
            ])
            ->addIndex(['category_code'], ['name' => 'idx_market_data_category_code'])
            ->update();

        foreach (self::ALLTICK as $category => $sourceKeys) {
            $this->execute(sprintf(
                "UPDATE `market_data` SET `category_code` = '%s' WHERE `source_key` IN ('%s')",
                $category, implode("','", $sourceKeys)
            ));
        }
    }

    public function down()
    {
        $this->table('market_data')
            ->removeIndexByName('idx_market_data_category_code')
            ->removeColumn('category_code')
            ->update();
    }
}
