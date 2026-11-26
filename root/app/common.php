<?php

use app\lib\SecurityService;
use think\admin\service\SystemService;

if (!function_exists('encrypt_key')) {
    /**
     * 核心加密函数 (封装 SecurityService)
     */
    function encrypt_key(string $val, ?string $msgkey = null): string
    {
        return SecurityService::encrypt($val, $msgkey);
    }
}

if (!function_exists('decrypt_key')) {
    /**
     * 核心解密函数 (封装 SecurityService)
     */
    function decrypt_key(string $val, ?string $msgkey = null): string
    {
        return SecurityService::decrypt($val, $msgkey);
    }
}

if (!defined('PHP_BOLT_KEY')) {
    /**
     * 确保 php-bolt 密钥已定义
     */
    define('PHP_BOLT_KEY', get_cfg_var('bolt.key'));
}

if (!function_exists('business_log')) {
    /**
     * 动态记录独立业务日志
     *
     * @param string $business 业务名称，用于独立文件名 (例如: 'market', 'task')
     * @param mixed  $msg      日志内容
     * @param string $level    日志级别 (默认 'info')
     * @param array  $data     附加数据
     */
    function business_log(string $business, $msg, string $level = 'info', array $data = [])
    {
        // 动态通道名称包含日期，防止在 Workerman 常驻内存期间跨天后无法写入新文件
        $channelName = $business . '_' . date('Ymd');

        $logConfig = \think\facade\Config::get('log');
        
        // 检查是否已经动态注册过该通道
        if (!isset($logConfig['channels'][$channelName])) {
            $logConfig['channels'][$channelName] = [
                'type' => \app\common\log\driver\Json::class,
                'single' => date('Ymd') . '_' . $business, // 最终文件名: 2026xxxx_business.log
                // 双重保险：真正强制立即落盘的是下面 record() 之后那行显式 save()，
                // 这里配 true 只是防着以后谁重构时把那行 save() 顺手删掉
                'realtime_write' => true,
                // 通道配置各自独立，不会从 channels.file 继承。不显式带上就落到 File
                // 驱动的出厂默认 file_size=2MB，于是业务日志一天切好几个 -{时间戳}.log，
                // 排查时只看未切片的那个会漏掉当天绝大部分内容。
                'file_size' => $logConfig['channels']['file']['file_size'] ?? 209715200,
                'max_files' => $logConfig['channels']['file']['max_files'] ?? 100,
            ];
            // 写入系统配置
            \think\facade\Config::set($logConfig, 'log');
        }

        // 使用动态注册的日志通道
        $logger = \think\facade\Log::channel($channelName);

        // 检查 $data 是否使用了 reverse key
        $reserve_keys = ['time', 'type', 'msg'];
        $duplicates = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $reserve_keys)) {
                $duplicates[] = $key;
            }
        }
        if (!empty($duplicates)) {
            $data = array_intersect_key($data, array_flip($reserve_keys));
            $logger->record(['msg' => "Business [$business] \$data used reserved keys: " . implode(',', $duplicates)], 'error');
        }
        $logger->record([...$data, 'msg' => $msg], $level);
        $logger->save();
    }


    if (!function_exists('lang_encode')) {
        /**
         * 语言 JSON 编码
         */
        function lang_encode($data): string
        {
            return json_encode($data, JSON_UNESCAPED_UNICODE);
        }
    }

    if (!function_exists('lang_decode')) {
        /**
         * 语言 JSON 解码
         */
        function lang_decode($data): array
        {
            if (empty($data)) return [];
            if (is_array($data)) return $data;
            return json_decode($data, true) ?: [];
        }
    }

    if (!function_exists('resolve_i18n')) {
        /**
         * 从 JSON 多语言字段取对应语言文本，fallback 链：目标语言 → zh_CN → $fallback
         * @param string|null $langsJson  JSON 字符串，如 {"zh_CN":"...","en_US":"..."}
         * @param string      $lang       目标语言代码，如 zh_CN
         * @param string      $fallback   兜底文本
         */
        function resolve_i18n(?string $langsJson, string $lang, string $fallback = ''): string
        {
            if (!$langsJson) return $fallback;
            $map = json_decode($langsJson, true);
            if (!is_array($map)) return $fallback;
            return $map[$lang] ?? $map['zh_CN'] ?? $fallback;
        }
    }

    if (!function_exists('get_lang_list')) {
        /**
         * 获取已启用的语言列表，请求级缓存避免重复查库
         * 返回 [['name' => '中文', 'value' => 'zh_CN'], ...]
         */
        function get_lang_list(): array
        {
            static $cache = null;
            if ($cache === null) {
                $cache = \think\facade\Db::name('lang_conf')
                    ->where('status', 1)
                    ->order('id asc')
                    ->field('name,value')
                    ->select()->toArray();
            }
            return $cache;
        }
    }

    if (!function_exists('lang_converter')) {
        /**
         * 语言 JSON 解码
         */
        //处理前台传 en-Us -> en_US
        function lang_converter($lang)
        {
            // 这里做个转换器
            $lang = str_replace("-", "_", $lang);
            $parts = explode('_', $lang);
            if (count($parts) > 1) {
                return $parts[0] . '_' . strtoupper($parts[1]);
            }
            return $lang;
        }
    }
}

if (!function_exists('redis_reconnect')) {
    /**
     * 丢弃当前的 Redis 连接并重建一条。
     *
     * ⚠️ 为什么必须有这个：phpredis 的两种断线**结果完全不同**（本机 6.3.0 实测）：
     *
     *   连接被踢掉、服务端还在  -> 下一次调用自动重连，连 SELECT 的库都保留
     *   Redis 真的宕过一段时间  -> 进入 "server went away"，**服务恢复后也永不自愈**，
     *                              之后每一次调用都抛 RedisException
     *
     * 而后者正是生产会遇到的（重启、OOM、维护）。更要命的是 ThinkPHP 的
     * cache 驱动只在 `if (!$this->handler)` 时才建连接 —— 一旦建立就永远复用，
     * 于是 redis_client() 会一直把那个**已经死掉的**句柄发给所有人。
     *
     * 结果：Redis 重启一次，所有常驻进程（撮合、结算、落库、分发）就此全部瘫痪，
     * 直到有人手工重启它们，而进程心跳一切正常。
     *
     * forgetDriver() 丢掉驱动实例，下一次 store('redis') 会重新 new + connect。
     */
    function redis_reconnect()
    {
        try {
            \think\facade\Cache::forgetDriver('redis');
        } catch (\Throwable $e) {
            // 丢驱动本身失败没什么可做的，下面照样尝试拿一次
        }
        try {
            return \think\facade\Cache::store('redis')->handler();
        } catch (\Throwable $e) {
            // Redis 还没起来 —— 返回 null，调用方按"这一轮没有 Redis"处理，
            // 下一轮会再试一次。不抛：调用方几乎都在异常处理路径上
            return null;
        }
    }
}

if (!function_exists('redis_client')) {
    /**
     * 获取原生 Redis 客户端
     *
     * 注意：返回的句柄可能已经死了（见 redis_reconnect() 的说明）。
     * 常驻进程的 Timer 回调统一由 TaskLiveness::guard() 兜底 ——
     * 捕获 RedisException 后自动重建，下一轮就恢复。
     */
    function redis_client()
    {
        $h = \think\facade\Cache::store('redis')->handler();

        // isConnected() 是**本地 socket 状态检查，不发命令、没有往返**，
        // 所以放在这个每 tick 都会走的路径上是安全的（实测：正常 true；
        // 连接真的断掉之后立刻变 false，且此后恒为 false）。
        //
        // 有了它，自愈就不再依赖"调用方记得在 catch 里重连" ——
        // Timer 回调之外的路径（分发机/订阅方的 onMessage、Web 请求）也一并覆盖。
        // 第一次失败的那一次仍然会抛（此刻 isConnected 还是 true），
        // 那一次由 TaskLiveness::guard() 兜住，下一次调用就走到这里了。
        if ($h instanceof \Redis && method_exists($h, 'isConnected') && !$h->isConnected()) {
            // 限流：Redis 宕机期间热路径可能每秒调用上千次，
            // 每次都试着 connect() 会把故障放大 —— 尤其跨网段时 connect 可能阻塞。
            // 一秒试一次足够，恢复延迟最多一秒
            static $lastTry = 0;
            $now = time();
            if ($now !== $lastTry) {
                $lastTry = $now;
                $fresh = redis_reconnect();
                if ($fresh !== null) return $fresh;
            }
        }

        return $h;
    }
}


if (!function_exists('sys_conf')) {

    //override 框架 sysconf
    function sys_conf(string $name = '', $value = null)
    {
        // 如果 $name 为空，直接返回整个配置
        if (empty($name)) return SystemService::get();

        // 仅在没有 '.' 的情况下，自动补全 business 分组
        if (!str_contains($name, '.')) {
            $name = "business.{$name}";
        }

        if (is_null($value)) {
            return SystemService::get($name);
        }
        return SystemService::set($name, $value);
    }
}

if (!function_exists('calc_change_rate')) {
    /**
     * 计算 24h 涨跌幅 %
     * @param string|int|float $lastPrice 最新价格
     * @param string|int|float $open 24h开盘价
     * @return float
     */
    function calc_change_rate($lastPrice, $open): float
    {
        if (bccomp((string)$open, '0', 10) > 0) {
            $change = bcsub((string)$lastPrice, (string)$open, 10);
            return (float)bcdiv($change, (string)$open, 4) * 100;
        }
        return 0.0;
    }
}

