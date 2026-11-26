<?php

declare(strict_types=1);
/**
 * +----------------------------------------------------------------------
 * | ThinkAdmin Plugin for ThinkAdmin
 * +----------------------------------------------------------------------
 * | 版权所有 2014~2026 ThinkAdmin [ thinkadmin.top ]
 * +----------------------------------------------------------------------
 * | 官方网站: https://thinkadmin.top
 * +----------------------------------------------------------------------
 * | 开源协议 ( https://mit-license.org )
 * | 免责声明 ( https://thinkadmin.top/disclaimer )
 * | 会员特权 ( https://thinkadmin.top/vip-introduce )
 * +----------------------------------------------------------------------
 * | gitee 代码仓库：https://gitee.com/zoujingli/ThinkAdmin
 * | github 代码仓库：https://github.com/zoujingli/ThinkAdmin
 * +----------------------------------------------------------------------
 */
return [
    // 默认缓存驱动
    'default' => env('CACHE_TYPE', 'file'),
    // 缓存连接配置
    'stores' => [
        'file' => [
            // 驱动方式
            'type' => 'File',
            // 缓存保存目录
            'path' => '',
            // 缓存名称前缀
            'prefix' => '',
            // 缓存有效期 0 表示永久缓存
            'expire' => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制
            'serialize' => [],
        ],
        'safe' => [
            // 驱动方式
            'type' => 'File',
            // 缓存保存目录
            'path' => syspath('safefile/cache/'),
            // 缓存名称前缀
            'prefix' => '',
            // 缓存有效期 0 表示永久缓存
            'expire' => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制
            'serialize' => [],
        ],
        // token —— 前端登录态专用，与 file 分开只为换一个落盘目录：
        // 部署脚本清 runtime/cache 时不会连带删掉登录 token
        'token' => [
            // 驱动方式
            'type' => 'File',
            // 缓存保存目录
            'path' => syspath('safefile/token/'),
            // 缓存名称前缀
            'prefix' => '',
            // 缓存有效期 0 表示永久缓存
            'expire' => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制
            'serialize' => [],
        ],
        'redis' => [
            // 驱动方式 —— 自定义驱动，负责把 prefix 设到连接上
            'type' => 'app\lib\RedisCacheDriver',
            'host' => env('CACHE_REDIS_HOST', '127.0.0.1'),
            'port' => env('CACHE_REDIS_PORT', 6379),
            'select' => env('CACHE_REDIS_SELECT', 0),
            'password' => env('CACHE_REDIS_PASSWORD', ''),
            // prefix —— 设为连接的 OPT_PREFIX，Cache 门面与 redis_client() 原生调用全部生效。
            //   多套部署共用一个 Redis 时必须各配不同值；deploy 脚本 / tools 只读 .env，.env 里务必写明
            //   改值等于换一套 key 空间，必须停全部进程后切换，见 .agents/plan/redis_key_prefix.md
            'prefix' => env('CACHE_PREFIX', 'market_relay_'),
        ],
    ],
];
