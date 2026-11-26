<?php

declare(strict_types=1);

namespace app\lib;

use think\cache\driver\Redis;

/**
 * Redis 缓存驱动：把 prefix 从门面拼接挪到连接的 OPT_PREFIX，门面与 redis_client() 原生调用一起生效。
 *
 * 框架原生的 prefix 只在 getCacheKey() 里拼接，原生句柄不受它管；这里构造时把它取走、清空门面拼接，
 * 建连时改设 OPT_PREFIX（用 PrefixedRedis，keys()/scan() 返回逻辑名），门面最终 key 与原来一致。
 * redis_reconnect() 的 forgetDriver 重建同样走这里。prefix 为空时完全交给父类，与框架原生驱动逐字等价。
 *
 * 依赖  配置 cache.stores.redis.prefix · env CACHE_PREFIX
 * 设计依据：.agents/plan/redis_key_prefix.md
 */
class RedisCacheDriver extends Redis
{
    /** 连接级 key 前缀（取自 options.prefix） */
    private string $keyPrefix = '';

    public function __construct(array $options = [])
    {
        parent::__construct($options);

        // 必须在构造时挪走：门面每次都是先 getCacheKey() 再 handler()，留到 handler() 里清空会让首次读写叠两层前缀。
        // Predis 分支父类自己处理 prefix，不动
        if (extension_loaded('redis')) {
            $this->keyPrefix = (string)$this->options['prefix'];
            $this->options['prefix'] = '';
        }
    }

    public function handler()
    {
        if ($this->handler) return $this->handler;
        if ($this->keyPrefix === '') return parent::handler();

        // 建连流程照抄父类 phpredis 分支，只把 new \Redis 换成 PrefixedRedis
        $o = $this->options;
        $h = new PrefixedRedis();
        if ($o['persistent']) {
            $h->pconnect($o['host'], (int)$o['port'], (int)$o['timeout'], 'persistent_id_' . $o['select']);
        } else {
            $h->connect($o['host'], (int)$o['port'], (int)$o['timeout']);
        }
        if ('' != $o['password']) $h->auth($o['password']);
        if (0 != $o['select']) $h->select((int)$o['select']);

        $h->setOption(\Redis::OPT_PREFIX, $this->keyPrefix);
        // SCAN_PREFIX（phpredis ≥ 5.3）让 scan() 的 pattern 也自动带前缀；缺失时只记日志，不让所有 Redis 访问致命
        if (defined('\Redis::SCAN_PREFIX')) {
            $h->setOption(\Redis::OPT_SCAN, \Redis::SCAN_PREFIX);
        } else {
            \think\facade\Log::error('[RedisCacheDriver] phpredis 不支持 SCAN_PREFIX，开启 prefix 后 scan() 将扫不到任何 key');
        }

        return $this->handler = $h;
    }
}
