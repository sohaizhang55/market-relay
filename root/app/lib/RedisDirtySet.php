<?php

declare(strict_types=1);

namespace app\lib;

/**
 * 「脏集合」的原子取走。
 *
 * 场景：生产者持续把变更过的 key 塞进一个 SET，消费者定期把整批取走处理。
 * 直接 sMembers + del 会漏：两条命令之间新写入的成员会被 del 一并抹掉。
 *
 * SPOP key COUNT 一条命令原子弹出，弹出之后的写入自然留在集合里等下一轮。
 * 没有临时 key，也就没有「RENAME 之后、DEL 之前进程被杀」那种孤儿批次需要回收。要求 Redis ≥ 3.2。
 */
final class RedisDirtySet
{
    /**
     * @param \Redis $redis
     * @param string $dirtyKey 脏集合 key
     * @param int $limit 本轮最多取多少个，0 = 全部。消费者是单线程事件循环，
     *                   一轮取多少直接决定它被占住多久，见 MarketDbFlusher::DEFAULT_BATCH
     * @return array<int,string> 本轮取走的成员；集合为空或取走失败时返回空数组
     */
    public static function swapOut($redis, string $dirtyKey, int $limit = 0): array
    {
        try {
            $count = $limit > 0 ? $limit : (int)$redis->sCard($dirtyKey);
            if ($count <= 0) return [];
            $members = $redis->sPop($dirtyKey, $count);
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($members) ? $members : [];
    }
}
