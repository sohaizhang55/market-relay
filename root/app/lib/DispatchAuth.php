<?php

declare(strict_types=1);

namespace app\lib;

use app\business\service\DispatchSubscriberService;

/**
 * 分发鉴权的运行时一侧：认 token、记最后来源 IP
 *
 * 订阅方在 WS 握手头和 /inner_api/* 请求头里带 X-Inner-Token，命中哪个订阅方就是谁。
 * 这里只读 Redis 快照（SNAP_KEY），不查库；快照由 DispatchSubscriberService::publish() 写，
 * 后台每次改动立即写一次，market:subscriber_sync 每 60 秒兜底重写一次。
 *
 * 快照形状（Go 分发机 go/internal/dispatch/auth.go 读同一个键，改形状两边一起改）：
 *   {"enabled":true,"subs":{"<sha256(token)>":{"id":1,"name":"server-b"}},"ts":1795000000}
 *   enabled  后台「分发鉴权」开关，true = WS 必须带有效 token
 *   subs     只含启用的订阅方；键是 token 的 sha256，Redis 里不存明文
 *
 * 快照读不到时沿用上一次读到的；进程启动后一次都没读到，就当开关关闭、没有订阅方。
 *
 * 宿主  MarketDispatcher（PHP 分发进程）· InnerAuth（/inner_api/*）
 */
final class DispatchAuth
{
    /** 订阅方带 token 的请求头，WS 握手与 /inner_api/* 同名 */
    public const HEADER = 'X-Inner-Token';

    /** 鉴权快照（STRING，JSON），格式见类注释 */
    public const SNAP_KEY = 'market:dispatch:auth';

    /** 最后来源 IP（HASH）：field = "{订阅方 id}:{SEEN_WS|SEEN_API}"，value = "{ip}|{秒级时间戳}" */
    public const SEEN_KEY = 'market:dispatch:subscriber_seen';
    public const SEEN_WS  = 'ws';
    public const SEEN_API = 'api';

    /** 进程内缓存快照的秒数：后台改完最长这么久生效 */
    private const CACHE_SEC = 5;

    /** @var array{enabled: bool, subs: array<string, array{id: int, name: string}>}|null */
    private static ?array $snap = null;
    private static int $loadedAt = 0;

    /** 后台「分发鉴权」开关是否打开（打开 = WS 不带有效 token 直接拒绝） */
    public static function enabled(): bool
    {
        return self::snapshot()['enabled'];
    }

    /**
     * 按 token 找订阅方。
     *
     * @return array{id: int, name: string}|null  token 为空、不存在或订阅方已停用都返回 null
     */
    public static function identify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') return null;
        return self::snapshot()['subs'][self::hashToken($token)] ?? null;
    }

    /** 记下订阅方最后一次从哪个 IP 来。写失败只丢这一次记录，不影响鉴权结果 */
    public static function markSeen(int $id, string $via, string $ip): void
    {
        try {
            redis_client()->hSet(self::SEEN_KEY, "{$id}:{$via}", $ip . '|' . time());
        } catch (\Throwable $e) {
            business_log('inner_api', "DispatchAuth::markSeen 写 Redis 失败：{$e->getMessage()}", 'warning');
        }
    }

    /** token 在快照里的键。Go 侧用同一算法：sha256 十六进制小写 */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 让下一次读取跳过进程内缓存，后台保存后在本进程内立即生效 */
    public static function forget(): void
    {
        self::$loadedAt = 0;
    }

    /** @return array{enabled: bool, subs: array<string, array{id: int, name: string}>} */
    private static function snapshot(): array
    {
        $now = time();
        if (self::$snap !== null && $now - self::$loadedAt < self::CACHE_SEC) {
            return self::$snap;
        }
        self::$loadedAt = $now;

        try {
            $raw = redis_client()->get(self::SNAP_KEY);
            // 键不在（Redis 被清过）：本进程能查库，直接重建一次，Go 分发机随之也读得到
            if ($raw === false || $raw === null) {
                [$ok, , $snap] = DispatchSubscriberService::instance()->publish();
                if ($ok) {
                    return self::$snap = self::normalize($snap);
                }
            } else {
                $data = json_decode((string)$raw, true);
                if (is_array($data)) {
                    return self::$snap = self::normalize($data);
                }
                business_log('inner_api', 'DispatchAuth 快照不是合法 JSON，沿用上一次的', 'error');
            }
        } catch (\Throwable $e) {
            business_log('inner_api', "DispatchAuth 读快照失败，沿用上一次的：{$e->getMessage()}", 'warning');
        }

        return self::$snap ??= ['enabled' => false, 'subs' => []];
    }

    /** @return array{enabled: bool, subs: array<string, array{id: int, name: string}>} */
    private static function normalize(array $data): array
    {
        $subs = [];
        foreach ((array)($data['subs'] ?? []) as $hash => $s) {
            $subs[(string)$hash] = ['id' => (int)($s['id'] ?? 0), 'name' => (string)($s['name'] ?? '')];
        }
        return ['enabled' => (bool)($data['enabled'] ?? false), 'subs' => $subs];
    }
}
