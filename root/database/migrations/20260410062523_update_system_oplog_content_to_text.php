<?php

use think\migration\Migrator;
use think\migration\db\Column;

class UpdateSystemOplogContentToText extends Migrator
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('system_oplog');
        $table->changeColumn('content', 'text', [
            'null'    => true,
            'comment' => '操作内容'
        ])->update();
    }
}
