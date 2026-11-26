<?php

use think\migration\Migrator;

/**
 * AllTick 下线后清 Redis 残留 —— 20261021110000_remove_alltick 只删了 MySQL
 *
 * 不清的后果：
 *   heartbeat:registry 里的 market_relay_Crawler_alltick_Spot / market_relay_MarketBackfill_alltick
 *     注册表只 sAdd 从不 sRem，不清就得在 MarketWorkers 里永远挂着「已下线」名单，否则心跳检查恒定误报
 *   market:dispatch:snap / dirty 里的源层旧帧，订阅方连上时会随快照重放出去
 *   热层、原始盘口 / 成交快照、worker 指标，后台监控页会一直显示一批永不更新的 AllTick 行
 *
 * 判据：key 名或 HASH field / SET 成员里含 ALLTICK_（源 key 前缀）或 _alltick（worker 名后缀）。
 * 全用 SCAN，不用 KEYS，免得在生产 Redis 上一次性阻塞。重复执行无副作用。
 *
 * down() 不恢复：这些都是缓存，没有可恢复的意义。
 */
class PurgeAlltickRedis extends Migrator
{
    /** 里面可能夹着 AllTick 字段 / 成员的容器 */
    private const HASHES = ['market:dispatch:snap', 'market:kline_history_floor'];
    private const SETS   = ['heartbeat:registry', 'market:metrics:workers', 'market:dispatch:dirty'];

    public function up()
    {
        $redis = redis_client();
        if (!$redis) {
            echo "  [skip] Redis 不可用，AllTick 的 Redis 残留未清理\n";
            return;
        }

        $keys = 0;
        foreach (['*ALLTICK_*', '*_alltick*'] as $pattern) {
            $it = null;
            while (($batch = $redis->scan($it, $pattern, 500)) !== false) {
                if ($batch) $keys += (int)$redis->del($batch);
                if ($it === 0) break;
            }
        }

        $fields = 0;
        foreach (self::HASHES as $hash) {
            $drop = array_filter((array)$redis->hKeys($hash), [$this, 'isAlltick']);
            if ($drop) $fields += (int)$redis->hDel($hash, ...$drop);
        }
        foreach (self::SETS as $set) {
            $drop = array_filter((array)$redis->sMembers($set), [$this, 'isAlltick']);
            if ($drop) $fields += (int)$redis->sRem($set, ...$drop);
        }

        echo "  [alltick] 删除 key {$keys} 个，HASH 字段 / SET 成员 {$fields} 个\n";
    }

    public function down()
    {
    }

    private function isAlltick(string $s): bool
    {
        return str_contains($s, 'ALLTICK_') || str_contains(strtolower($s), '_alltick');
    }
}
