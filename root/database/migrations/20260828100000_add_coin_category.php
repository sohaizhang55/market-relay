<?php

use think\migration\Migrator;

/**
 * 产品类型：新建 coin_category，coin_list 以 category_code 归类
 * 交易类型 (exchange_pair.type) > 产品类型 (coin_category) > 产品 (exchange_pair)；类型是币的属性，与交易类型无关
 * 存量币靠 category_code 默认值落 crypto，无需回填。
 *
 * 各列显式写 'null' => false：phinx 的 addColumn() 默认是可空的，只给 default 不给 null
 * 建出来仍是 NULL 允许列。category_code 一旦为 NULL，三个列表接口的 JOIN coin_category 会落空，
 * 该产品直接从列表消失；title_langs 例外，多语言标题本来就允许缺省
 */
class AddCoinCategory extends Migrator
{
    public function change()
    {
        $this->table('coin_category', ['engine' => 'InnoDB', 'comment' => '产品类型表'])
            ->addColumn('code', 'string', ['limit' => 20, 'null' => false, 'comment' => '类型代码 (crypto/rwa/metal/energy/index/stock…)'])
            ->addColumn('title', 'string', ['limit' => 50, 'default' => '', 'null' => false, 'comment' => '默认标题'])
            ->addColumn('title_langs', 'text', ['null' => true, 'comment' => '多语言标题 JSON，如 {"zh_CN":"...","en_US":"..."}'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'null' => false, 'comment' => '状态 1=ON 2=OFF；OFF 时该类型及其产品不在前端列表出现'])
            ->addColumn('sort', 'integer', ['default' => 0, 'null' => false, 'comment' => '排序权重'])
            ->addColumn('create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0])
            ->addColumn('update_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0])
            ->addIndex(['code'], ['unique' => true, 'name' => 'idx_category_code'])
            ->addIndex(['status', 'sort'], ['name' => 'idx_category_status_sort'])
            ->create();

        // market-relay：分发机的库没有业务表，表不存在就跳过
        if ($this->hasTable('coin_list')) {
            $this->table('coin_list')
                ->addColumn('category_code', 'string', ['limit' => 20, 'default' => 'crypto', 'null' => false, 'after' => 'coin_icon', 'comment' => '产品类型 → coin_category.code'])
                ->addIndex(['category_code'], ['name' => 'idx_coin_category_code'])
                ->update();
        }
    }
}
