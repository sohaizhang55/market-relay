<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddGoogleAuthToSystemUser extends Migrator
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
        $table = $this->table('system_user');
        $table->addColumn('google_auth_secret', 'string', ['limit' => 255, 'null' => true, 'comment' => 'Google 2FA 密钥'])
            ->update();
    }
}
