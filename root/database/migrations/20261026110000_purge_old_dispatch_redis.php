<?php

use think\migration\Migrator;

/**
 * 清掉旧版本分发留下的 Redis 键 —— 现行代码不读不写，也没有 TTL，不清会一直占着
 *
 *   market:dispatch:snap       按 source_key 分发时代的快照 HASH（分发协议 v3 前）
 *   market:dispatch:dirty      同上的脏集合
 *   market:dispatch:pair_snap  pair 分发早期、现货/合约还没拆成两个分发进程时的快照 HASH（没有类型后缀）
 *
 * 现行的键是 market:dispatch:pair_snap:{type} / pair_dirty:{type}（MarketSnapshotStore），名字带冒号和类型，
 * 与上面三个不重名，删除不影响分发。重复执行无副作用。
 * down() 不恢复：这些都是缓存，没有可恢复的意义。
 */
class PurgeOldDispatchRedis extends Migrator
{
    private const KEYS = ['market:dispatch:snap', 'market:dispatch:dirty', 'market:dispatch:pair_snap'];

    public function up()
    {
        $redis = redis_client();
        if (!$redis) {
            echo "  [skip] Redis 不可用，旧分发键未清理\n";
            return;
        }

        $n = (int)$redis->del(...self::KEYS);
        echo "  [dispatch] 删除旧分发 Redis key {$n} 个\n";
    }

    public function down()
    {
    }
}
