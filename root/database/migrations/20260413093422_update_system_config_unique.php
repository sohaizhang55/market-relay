<?php

declare(strict_types=1);

use think\migration\Migrator;

class UpdateSystemConfigUnique extends Migrator
{
    /**
     * Up Method.
     * 防止配置项重复，清理垃圾数据并添加 (type, name) 复合唯一索引
     */
    public function up()
    {
        // 1. 系统预处理：清理重复的配置项（保留 ID 最大的一条）
        // 依据是 type 和 name 必须同时满足唯一性
        $this->execute("DELETE t1 FROM system_config t1 INNER JOIN system_config t2 ON t1.type = t2.type AND t1.name = t2.name AND t1.id < t2.id");

        // 2. 结构变更：添加复合唯一索引
        $table = $this->table('system_config');

        // 检查复合索引是否已存在
        if (!$table->hasIndex(['type', 'name'])) {
            $table->addIndex(['type', 'name'], [
                'unique' => true,
                'name'   => 'idx_system_config_unique_type_name'
            ])->update();
        }
    }

    /**
     * Down Method.
     * 回滚变更：移除复合唯一索引
     */
    public function down()
    {
        $table = $this->table('system_config');
        // 注意：Phinx 移除复合索引需要传入数组名
        if ($table->hasIndex(['type', 'name'])) {
            $table->removeIndex(['type', 'name'])->update();
        }
    }
}
