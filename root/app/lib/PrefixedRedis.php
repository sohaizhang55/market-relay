<?php

declare(strict_types=1);

namespace app\lib;

/**
 * 开启实例级 key 前缀（OPT_PREFIX）时使用的 phpredis 客户端：keys() / scan() 返回去掉前缀的逻辑名。
 *
 * phpredis 只给传入的 key 加前缀，不从返回的 key 名里去掉，于是 `del($redis->keys('a:*'))`
 * 会被二次加前缀、静默删 0 个。这里把返回值还原成逻辑名，调用方写法与不开前缀时完全一致。
 *
 * 不覆盖：rawCommand() 不经 OPT_PREFIX，参数里的 key 要自己 `$redis->_prefix($key)`；
 *         multi()/pipeline() 里调 keys()/scan() 的结果从 exec() 拿，不经这里，仍是全名（项目内无此用法）。
 *
 * 由 RedisCacheDriver 在 prefix 非空时实例化；未开启前缀时用的仍是原生 \Redis。
 * 设计依据：.agents/plan/redis_key_prefix.md
 */
class PrefixedRedis extends \Redis
{
    // 签名兼容：参数一律不写类型（子类放宽参数类型总是合法）；返回类型照抄 phpredis 6 的声明
    // （keys 无、scan 为 array|false），5.x 父类没有返回类型，子类收窄同样合法

    #[\ReturnTypeWillChange]
    public function keys($pattern)
    {
        return $this->unprefix(parent::keys($pattern));
    }

    public function scan(&$iterator, $pattern = null, $count = 0, $type = null): array|false
    {
        $keys = $type === null
            ? parent::scan($iterator, $pattern, $count)
            : parent::scan($iterator, $pattern, $count, $type);

        return $this->unprefix($keys);
    }

    /** 非数组（false / multi 中的 $this）原样返回 */
    private function unprefix($keys)
    {
        if (!is_array($keys)) return $keys;

        $prefix = (string)$this->getOption(\Redis::OPT_PREFIX);
        if ($prefix === '') return $keys;

        $len = strlen($prefix);
        return array_map(
            fn($k) => str_starts_with((string)$k, $prefix) ? substr((string)$k, $len) : $k,
            $keys
        );
    }
}
