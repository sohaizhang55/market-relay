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

/**
 * WebSocket 配置文件桥接 (ThinkPHP 侧)
 * 
 * 此文件通过包含 Workerman 目录下的主配置文件来实现配置共享。
 * 这样做可以保持配置的一致性，且依然支持 config('gateway.admin') 调用。
 */

include_once __DIR__ . '/../../Workerman/config/config.php';

return [
    'timezone' => WORKERMAN_DEFAULT_TIMEZONE,
    'admin' => WORKERMAN_ADMIN_CONFIG,
];
