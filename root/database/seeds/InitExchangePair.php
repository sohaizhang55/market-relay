<?php

use think\migration\Seeder;

class InitExchangePair extends Seeder
{
    /**
     * 导入 exchange_pair.sql。
     *
     * 文件是 INSERT IGNORE + 不写死 id，去重靠 idx_symbol_type (symbol_code, type)，
     * 已存在的交易对不会被覆盖，随时可重跑。
     *
     * 每行的 source_key 必须在 market_data 里存在（先跑 InitMarketData），
     * 否则该交易对永远收不到行情。
     */
    public function run(): void
    {
        $sqlPath = __DIR__ . '/../exchange_pair.sql';

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
            echo "Error: '{$sqlPath}' not found.\n";
        }
    }
}
