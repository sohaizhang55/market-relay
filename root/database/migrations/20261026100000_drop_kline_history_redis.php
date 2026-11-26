<?php

use think\migration\Migrator;

/**
 * K 线只保留最近 2000 根之后，market:kline_history 与它的「历史到底」记录一起下线 —— 清掉 Redis 残留
 *
 *   market:kline_history_floor  KlineHistoryFloor：往更早补到底的 (源,周期)，已无人读写
 *   market:kline_seeded         拆分期间短暂存在过的播种记录，已由「窗口补满」取代
 *
 * 不清也不影响运行，只是两个永远不会再变的 hash。重复执行无副作用。
 * down() 不恢复：这些都是缓存，没有可恢复的意义。
 */
class DropKlineHistoryRedis extends Migrator
{
    public function up()
    {
        $redis = redis_client();
        if (!$redis) {
            echo "  [skip] Redis 不可用，market:kline_history_floor / market:kline_seeded 未清理\n";
            return;
        }

        $n = (int)$redis->del('market:kline_history_floor', 'market:kline_seeded');
        echo "  [kline] 删除 Redis key {$n} 个\n";
    }

    public function down()
    {
    }
}
