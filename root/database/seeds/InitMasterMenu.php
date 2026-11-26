<?php

use think\migration\Seeder;

class InitMasterMenu extends Seeder
{
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeders is available here:
     * http://docs.phinx.org/en/latest/seeding.html
     */
    public function run(): void
    {
        // 1. 清空现有的菜单表
        $this->execute("TRUNCATE TABLE system_menu");

        // 2. 执行外部 SQL 文件，与本 seeder 同仓：root/database/system_menu.sql
        $sqlPath = __DIR__ . '/../system_menu.sql';

        if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);
            if (!empty($sql)) {
                // 执行 SQL
                $this->execute($sql);
                echo "Success: '{$sqlPath}' executed.\n";
            } else {
                echo "Warning: '{$sqlPath}' is empty.\n";
            }
        } else {
            echo "Error: '{$sqlPath}' not found. Please export your menu table to this location.\n";
        }
    }
}
