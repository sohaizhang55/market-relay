<?php

declare(strict_types=1);

namespace app\business\service;

use app\enum\CommonStatusEnum;
use app\lib\DispatchAuth;
use app\service\trait\ServiceResult;
use think\admin\Service;
use think\facade\Db;

/**
 * 行情订阅方的库表一侧：把订阅方与鉴权开关发布成 Redis 快照，把最后来源 IP 落回库
 *
 * 运行时认 token 的是 app\lib\DispatchAuth，它只读本类发布的快照，不查库。
 * 不负责：WS 握手与 /inner_api/* 的拒绝逻辑（MarketDispatcher · Go 分发机 · InnerAuth 各自处理）。
 *
 * 依赖  表 dispatch_subscriber · system_config(market_dispatch.auth_enabled)
 *       Redis DispatchAuth::SNAP_KEY · DispatchAuth::SEEN_KEY
 */
class DispatchSubscriberService extends Service
{
    use ServiceResult;

    private const TABLE = 'dispatch_subscriber';

    /** 鉴权开关在 system_config 里的位置，即 sysconf('market_dispatch.auth_enabled') */
    public const CONF_TYPE = 'market_dispatch';
    public const CONF_NAME = 'auth_enabled';

    /** 一次最多处理多少个订阅方。订阅方是自家机器，个位数到几十个 */
    private const MAX_ROWS = 1000;

    /**
     * 鉴权开关当前取值。缺配置 = 关闭：升级上线时订阅方还没配 token，默认打开会让它们全部断线。
     *
     * 直接查表、不走 sysconf()：sysconf 在常驻进程里会被进程级缓存住，改了读不到。
     */
    public function authEnabled(): bool
    {
        $raw = Db::name('system_config')->where('type', self::CONF_TYPE)->where('name', self::CONF_NAME)->value('value');
        return (int)$raw === CommonStatusEnum::ON->value;
    }

    /** 保存鉴权开关并立即重新发布快照 */
    public function setAuthEnabled(int $status): array
    {
        if (!in_array($status, [CommonStatusEnum::ON->value, CommonStatusEnum::OFF->value], true)) {
            return $this->toError('鉴权开关取值非法');
        }
        sysconf(self::CONF_TYPE . '.' . self::CONF_NAME, (string)$status);
        return $this->publish();
    }

    /** 生成一个新 token：32 位十六进制 */
    public function genToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 按库里的订阅方与开关重写 Redis 快照，后台每次改动后调用。
     *
     * @return array [bool, string, array 快照]
     */
    public function publish(): array
    {
        try {
            $rows = Db::name(self::TABLE)->where('status', CommonStatusEnum::ON->value)
                ->field('id, name, token')->limit(self::MAX_ROWS)->select()->toArray();
            $subs = [];
            foreach ($rows as $r) {
                $token = trim((string)$r['token']);
                if ($token === '') continue;
                $subs[DispatchAuth::hashToken($token)] = ['id' => (int)$r['id'], 'name' => (string)$r['name']];
            }
            // subs 为空时编码成 {} 而不是 []：Go 侧按对象解析
            $snap = ['enabled' => $this->authEnabled(), 'subs' => (object)$subs, 'ts' => time()];
            redis_client()->set(DispatchAuth::SNAP_KEY, json_encode($snap, JSON_UNESCAPED_UNICODE));
            DispatchAuth::forget();
            return $this->toSuccess(['enabled' => $snap['enabled'], 'subs' => $subs, 'ts' => $snap['ts']]);
        } catch (\Throwable $e) {
            business_log('inner_api', "发布分发鉴权快照失败：{$e->getMessage()}", 'error');
            return $this->toError('发布分发鉴权快照失败：' . $e->getMessage());
        }
    }

    /**
     * Redis 里记的最后来源 IP。
     *
     * @return array<int, array<string, array{ip: string, ts: int}>>  订阅方 id => [ws|api => {ip, ts}]
     */
    public function seenMap(): array
    {
        try {
            $all = redis_client()->hGetAll(DispatchAuth::SEEN_KEY) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($all as $field => $value) {
            [$id, $via] = array_pad(explode(':', (string)$field, 2), 2, '');
            [$ip, $ts] = array_pad(explode('|', (string)$value, 2), 2, '0');
            if ((int)$id <= 0 || !in_array($via, [DispatchAuth::SEEN_WS, DispatchAuth::SEEN_API], true)) continue;
            $out[(int)$id][$via] = ['ip' => $ip, 'ts' => (int)$ts];
        }
        return $out;
    }

    /**
     * 把 Redis 里的最后来源 IP 落进库，只写比库里新的。
     *
     * @return array [bool, string, int 更新了几列组]
     */
    public function syncSeen(): array
    {
        $seen = $this->seenMap();
        if (!$seen) return $this->toSuccess(0);

        $rows = Db::name(self::TABLE)->whereIn('id', array_keys($seen))
            ->field('id, last_ws_ts, last_api_ts')->limit(self::MAX_ROWS)->select()->toArray();

        // 库里已删掉的订阅方，Redis 里的记录一并清掉，否则会一直留着
        $gone = array_diff(array_keys($seen), array_map('intval', array_column($rows, 'id')));
        foreach ($gone as $id) {
            foreach (array_keys($seen[$id]) as $via) {
                redis_client()->hDel(DispatchAuth::SEEN_KEY, "{$id}:{$via}");
            }
        }

        $n = 0;
        foreach ($rows as $r) {
            $data = [];
            foreach ($seen[(int)$r['id']] as $via => $s) {
                if ($s['ts'] <= (int)$r["last_{$via}_ts"]) continue;
                $data["last_{$via}_ip"] = $s['ip'];
                $data["last_{$via}_at"] = date('Y-m-d H:i:s', $s['ts']);
                $data["last_{$via}_ts"] = $s['ts'];
                $n++;
            }
            // 不动 update_at / update_ts：那两列记的是后台改配置的时间
            if ($data) Db::name(self::TABLE)->where('id', $r['id'])->update($data);
        }
        return $this->toSuccess($n);
    }
}
