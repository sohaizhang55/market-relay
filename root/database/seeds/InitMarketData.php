<?php

use think\migration\Seeder;

class InitMarketData extends Seeder
{
    /**
     * 导入 market_data.sql。
     *
     * ⚠️ 刻意**不** TRUNCATE。原先第一行是 `TRUNCATE TABLE market_data`，在跑着的库上重跑等于
     * 把全部实时行情清零：close/update_ts/mark_ts/funding_rate_ts 全没了，getPrice() 按
     * stale_seconds 全线返回 null，下单/强平/结算连锁拒绝，funding_rate_ts 归零还会让
     * contract:funding 按 3600s 新鲜度判定整期跳过收费。空库上 TRUNCATE 本来就是空操作，
     * 所以去掉它对装机没有任何影响，却让这个 seed 从"只能装机跑"变成"随时可重跑"。
     * 文件本身是 INSERT IGNORE + 不写死 id，去重靠 idx_exchange_symbol_type 与 source_key。
     *
     * 真要清空重来用 php think onceTask -t clear_exchange_data，那是个明确的破坏性动作。
     */
    public function run(): void
    {
        $sqlPath = __DIR__ . '/../market_data.sql';

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
            echo "Error: '{$sqlPath}' not found. Please export your config table to this location.\n";
        }
    }
}
