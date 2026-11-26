<?php

use think\migration\Migrator;

/**
 * 更新 coin_category.status 的列注释 —— 写明它刻意不参与下单校验
 *
 * 原注释「OFF 时该类型及其产品不在前端列表出现」只描述了现象，读起来像「目前只做到这里」。
 * 实际是有意的职责划分：能否交易只看 exchange_pair.status 与玩法表 status，本列不参与。
 * 列注释是 DBA 与运维 SHOW CREATE TABLE 时唯一看得到的说明，不写清楚就会有人顺手把它
 * 接进下单链路，从此两个开关都能拦单、排查成本翻倍。
 *
 * 单开一条迁移而不是改建表 seed：该表由 coin_category seed 建，已在所有环境执行过。
 *
 * 设计依据：.agents/plan/market_calendar_overview.md §4.13
 */
class UpdateCoinCategoryStatusComment extends Migrator
{
    /** 只动注释，不动类型与默认值 */
    public function change()
    {
        $this->table('coin_category')
            ->changeColumn('status', 'integer', [
                'default' => 1,
                'null'    => false,
                'comment' => '状态 1=ON 2=OFF；只控制前端列表是否展示，刻意不参与下单校验（下单只看 exchange_pair.status 与玩法表）',
            ])
            ->update();
    }
}
