<?php

use think\migration\Migrator;

class AddMissingTsColumns extends Migrator
{
    public function up()
    {
        // market-relay：分发机的库没有业务表，业务表这一段整体跳过
        if ($this->hasTable('app_user_account')) {
            // app_user_account
            $t = $this->table('app_user_account');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `app_user_account` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `app_user_account` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");

            // app_user_demo_account
            $t = $this->table('app_user_demo_account');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `app_user_demo_account` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `app_user_demo_account` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");

            // app_users
            $t = $this->table('app_users');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `app_users` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `app_users` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");

            // cms_article
            $t = $this->table('cms_article');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `cms_article` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `cms_article` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");

            // cms_banner
            $t = $this->table('cms_banner');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `cms_banner` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `cms_banner` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");

            // cms_category
            $t = $this->table('cms_category');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `cms_category` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `cms_category` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");

            // cms_notice
            $t = $this->table('cms_notice');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `cms_notice` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `cms_notice` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");

            // coin_list
            $t = $this->table('coin_list');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `coin_list` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `coin_list` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");

            // exchange_spot_trade
            $t = $this->table('exchange_spot_trade');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->update();
            $this->execute("UPDATE `exchange_spot_trade` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");

            // lang_conf
            $t = $this->table('lang_conf');
            $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
              ->addColumn('update_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'update_at'])
              ->update();
            $this->execute("UPDATE `lang_conf` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");
            $this->execute("UPDATE `lang_conf` SET `update_ts`=UNIX_TIMESTAMP(`update_at`) WHERE `update_at` IS NOT NULL");
        }

        // market_data
        $t = $this->table('market_data');
        $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
          ->update();
        $this->execute("UPDATE `market_data` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");

        // market_source_kline
        $t = $this->table('market_source_kline');
        $t->addColumn('create_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'create_at'])
          ->update();
        $this->execute("UPDATE `market_source_kline` SET `create_ts`=UNIX_TIMESTAMP(`create_at`) WHERE `create_at` IS NOT NULL");

        // market-relay：分发机的库没有业务表，表不存在就跳过
        if ($this->hasTable('staking_order')) {
            // staking_order — date 类型字段, UNIX_TIMESTAMP(date) 返回当天零点时间戳
            $t = $this->table('staking_order');
            $t->addColumn('start_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'start_at'])
              ->addColumn('expire_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'expire_at'])
              ->addColumn('last_settled_ts', 'integer', ['limit' => 10, 'default' => 0, 'after' => 'last_settled_at'])
              ->update();
            $this->execute("UPDATE `staking_order` SET `start_ts`=UNIX_TIMESTAMP(`start_at`) WHERE `start_at` IS NOT NULL");
            $this->execute("UPDATE `staking_order` SET `expire_ts`=UNIX_TIMESTAMP(`expire_at`) WHERE `expire_at` IS NOT NULL");
            $this->execute("UPDATE `staking_order` SET `last_settled_ts`=UNIX_TIMESTAMP(`last_settled_at`) WHERE `last_settled_at` IS NOT NULL");
        }
    }

    public function down()
    {
        foreach ([
            'app_user_account'    => ['create_ts', 'update_ts'],
            'app_user_demo_account' => ['create_ts', 'update_ts'],
            'app_users'           => ['create_ts', 'update_ts'],
            'cms_article'         => ['create_ts', 'update_ts'],
            'cms_banner'          => ['create_ts', 'update_ts'],
            'cms_category'        => ['create_ts', 'update_ts'],
            'cms_notice'          => ['create_ts', 'update_ts'],
            'coin_list'           => ['create_ts', 'update_ts'],
            'exchange_spot_trade' => ['create_ts'],
            'lang_conf'           => ['create_ts', 'update_ts'],
            'market_data'         => ['create_ts'],
            'market_source_kline' => ['create_ts'],
            'staking_order'       => ['start_ts', 'expire_ts', 'last_settled_ts'],
        ] as $tableName => $columns) {
            // market-relay：分发机的库没有业务表，表不存在就跳过
            if (!$this->hasTable($tableName)) continue;
            $t = $this->table($tableName);
            foreach ($columns as $col) {
                $t->removeColumn($col);
            }
            $t->update();
        }
    }
}
