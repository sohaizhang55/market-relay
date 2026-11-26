<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketChannelEnum;

/**
 * 行情分发快照存储（覆盖语义）
 *
 * 唯一的行情传输路径。它取代过的堆积式队列(market:dispatch:queue)已于本批改动删除，
 * 两者的差别正是选它的理由：
 *   队列：每帧一条 list 元素，无长度上限。订阅方停摆 75 秒 ≈ 积压 12000 帧，
 *         恢复时要逐条重放历史盘口 —— 几乎保证再次停摆，积压随停摆时长线性无界增长。
 *   快照：同一数据组只留最新值。无论停摆多久，HASH 里始终约 165 个字段，
 *         恢复只需一个 tick。**积压有硬上限且与停摆时长无关**，这是覆盖式最核心的收益，
 *         比"省带宽"重要得多（实测总量才 133 KB/s，带宽从来不是问题）。
 *
 * 结构（按 pair 类型各一套，{type} = MarketDataTypeEnum 的值，1 现货 / 2 合约）：
 *   market:dispatch:pair_snap:{type}   HASH   field => 帧 JSON（覆盖写）
 *   market:dispatch:pair_dirty:{type}  SET    本轮变更过的 field
 *   field = "{channel}|{pair}"，kline 追加 "|{period}"；pair = "{symbol_code}_{type}"，见 MarketPublisher::pairKey()
 *
 * 线上只分发 pair 层（分发协议 v3）：订阅方只认 pair，不再关心数据来自哪个交易所。
 * 旧版本的 market:dispatch:snap / dirty（按 source_key）与 market:dispatch:pair_snap（拆类型前）
 * 已由迁移 20261026110000_purge_old_dispatch_redis 清掉。
 *
 * ⚠️ 为什么按类型拆键而不是共用一套：现货与合约各由一个独立的 market:dispatch 进程分发（各自端口），
 * 而分发机是用 SPOP **整个取走**脏集合的。两个进程共用一个脏集合，就会互相把对方的字段取走，
 * 两边都静默丢帧。所以一个类型一个脏集合，进程只碰自己那一套。
 *
 * ── 竞态语义（重要，决定了为什么只有覆盖式在这里是安全的）──────────────────
 * 分发机每 tick 用 SPOP 原子取走脏集合，再 HMGET 取值。两种交错：
 *   A) 采集器在 SPOP 之后 SADD  → 留在集合里，下一 tick 取到。正确。
 *   B) 采集器在 SPOP 之前 SADD、在 HMGET 之后 HSET
 *      → 本轮发出的是上一版值，且下一 tick 会把同一字段再发一次。
 * B 在堆积语义下是"乱序/重复投递"的严重问题，但在覆盖语义下完全无害：
 * 重复投递同一个数据组的最新状态是幂等的，晚一个 tick 也只是晚 100ms。
 * 换句话说，**覆盖语义正是让这里不需要任何锁的原因**。
 */
final class MarketSnapshotStore
{
    /** 快照 HASH 前缀，后接 pair 类型：field => 帧 JSON */
    public const SNAP_KEY_PREFIX = 'market:dispatch:pair_snap:';

    /** 脏字段 SET 前缀，后接 pair 类型 */
    public const DIRTY_KEY_PREFIX = 'market:dispatch:pair_dirty:';

    /**
     * 影子脏字段 SET 前缀，后接 pair 类型。只在 market.dispatch.shadow_dirty 打开时写，
     * 给并行试跑的 Go 分发机（market-relay/go）取：脏集合是 SPOP 整个取走的，两个分发机共用一份会互相抢走字段。
     * 没人消费时它最多也只有快照字段数那么大（SET 去重），不会无限增长。
     */
    public const SHADOW_DIRTY_KEY_PREFIX = 'market:dispatch:pair_dirty_shadow:';

    /** shadow_dirty 开关的进程内缓存：put() 是采集热路径，不每次读配置。改开关要重启采集进程 */
    private static ?bool $shadowDirty = null;

    /** 某类 pair 的快照 HASH 键 */
    public static function snapKey(int $type): string
    {
        return self::SNAP_KEY_PREFIX . $type;
    }

    /** 某类 pair 的脏字段 SET 键 */
    public static function dirtyKey(int $type): string
    {
        return self::DIRTY_KEY_PREFIX . $type;
    }

    /**
     * 组装快照 field。与 MarketMetrics::groupField() 口径一致，
     * 这样监控页能把「快照里有没有这个字段」和「它多久没更新了」直接对上号。
     */
    public static function makeField(MarketChannelEnum $channel, string $pair, string $suffix = ''): string
    {
        $field = $channel->value . '|' . $pair;
        return $suffix === '' ? $field : $field . '|' . $suffix;
    }

    /**
     * 拆回 field 的三段。分发机侧统计与监控页用。
     * @return array{channel:string, pair:string, suffix:string}|null
     */
    public static function parseField(string $field): ?array
    {
        $parts = explode('|', $field);
        if (count($parts) < 2) return null;
        return [
            'channel'    => $parts[0],
            'pair'       => $parts[1],
            'suffix'     => $parts[2] ?? '',
        ];
    }

    /**
     * 写入一个数据组的最新帧（覆盖）并标脏。
     *
     * HSET + SADD 合并成一次 pipeline，省掉一半 Redis 往返 —— 这是采集器热路径。
     *
     * @param int $type pair 类型，决定写进哪一套键（由对应类型的分发进程取走）
     */
    public static function put(MarketChannelEnum $channel, int $type, string $pair, array $payload, string $suffix = ''): bool
    {
        $redis = redis_client();
        if (!$redis) return false;

        $field = self::makeField($channel, $pair, $suffix);
        $json = json_encode($payload);
        if ($json === false) return false;

        self::$shadowDirty ??= (bool)\think\facade\Config::get('market.dispatch.shadow_dirty', false);

        try {
            $pipe = $redis->multi(\Redis::PIPELINE)
                ->hSet(self::snapKey($type), $field, $json)
                ->sAdd(self::dirtyKey($type), $field);
            if (self::$shadowDirty) {
                $pipe->sAdd(self::SHADOW_DIRTY_KEY_PREFIX . $type, $field);
            }
            $pipe->exec();
        } catch (\Throwable $e) {
            return false;
        }
        return true;
    }

    /** 原子取走本轮脏字段。复用 RedisDirtySet，与 MarketService 落库侧同一套语义 */
    public static function swapDirtyFields($redis, int $type): array
    {
        return RedisDirtySet::swapOut($redis, self::dirtyKey($type));
    }

    /**
     * 批量取回若干 field 的帧 JSON。
     * @return array<string,string> field => json，取不到的 field 会被跳过
     */
    public static function readFields($redis, int $type, array $fields): array
    {
        if (empty($fields)) return [];
        try {
            $values = $redis->hMGet(self::snapKey($type), $fields);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($values)) return [];

        $out = [];
        foreach ($values as $field => $json) {
            // 字段在 HMGET 之前刚被删掉、或 Redis 侧本来就不存在，跳过即可
            if ($json === false || $json === null || $json === '') continue;
            $out[$field] = (string)$json;
        }
        return $out;
    }

    /**
     * 取回整份快照。供订阅方连接时的一次性全量重放使用。
     *
     * 覆盖语义下这份 HASH 的大小有硬上限(= 数据组总数，约 165 个字段)，
     * 与订阅方停摆多久无关 —— 所以这里可以放心 HGETALL，不需要分页游标。
     * 这也正是覆盖式相对堆积式最核心的收益：恢复代价恒定。
     *
     * ── 关于「孤儿字段」──────────────────────────────────────────────────────
     * 快照 HASH 是覆盖写、没有 TTL：某个交易对从 exchange_pair 里移除后，它的字段会
     * 一直留在这里，于是每个新接上的订阅方都会在重放里收到它一次(幂等的旧值)。
     *
     * 这里**刻意不做清理**，因为清理需要一份"当前还在分发的 pair 全集"，
     * 而拿它的两条路都不划算：
     *   · 分发机查 exchange_pair —— 分发机是**刻意不持有 DB 连接**的
     *     (见 MarketDispatcher::initWorkerEnv() 里的 Db::close())，为这件事把它拉回
     *     DB 依赖，代价远大于收益
     *   · 按"多久没更新"推断 —— 会把真正的长时间断采一并删掉，反而抹掉故障现场
     * 孤儿字段的实际代价只有"重放时多几帧旧数据"，订阅方本地没有这个 pair 时直接丢弃，可以接受。
     *
     * @return array<string,string> field => json
     */
    public static function readAll($redis, int $type): array
    {
        try {
            $raw = $redis->hGetAll(self::snapKey($type));
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $field => $json) {
            if ($json === false || $json === null || $json === '') continue;
            $out[(string)$field] = (string)$json;
        }
        return $out;
    }

    /** 供监控页与 stat 使用：快照字段数、脏字段数 */
    public static function stats($redis, int $type): array
    {
        try {
            return [
                'snap_fields' => (int)$redis->hLen(self::snapKey($type)),
                'dirty_size'  => (int)$redis->sCard(self::dirtyKey($type)),
            ];
        } catch (\Throwable $e) {
            return ['snap_fields' => -1, 'dirty_size' => -1];
        }
    }
}
