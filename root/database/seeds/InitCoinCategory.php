<?php

use think\migration\Seeder;

class InitCoinCategory extends Seeder
{
    public function run(): void
    {
        $sqlPath = __DIR__ . '/../coin_category.sql';

        if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);
            if (!empty($sql)) {
                $this->execute($sql);
                echo "Success: '{$sqlPath}' executed.\n";
            } else {
                echo "Warning: '{$sqlPath}' is empty.\n";
            }
        } else {
            echo "Error: '{$sqlPath}' not found.\n";
        }
    }
}
