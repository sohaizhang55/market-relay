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
 * WebSocket 核心配置文件 (分层优化版)
 * 
 * 此文件定义了网关集群的端口和通讯协议。market-relay 只有后台网关（管理员通知铃铛），
 * 没有用户端网关；行情分发端口不在这里，见 root/config/market.php 的 dispatch.ports。
 * 端口号被提取为变量以便于维护和外部引用。
 */

$adminPort = 22365;

$isWin = (DIRECTORY_SEPARATOR === '\\') ? true : false;
// 如果是 Windows 环境，进程数强制设为 1，否则设为 4
$gatewayCount = $isWin ? 1 : 4;

define('WORKERMAN_DEFAULT_TIMEZONE', 'America/New_York');

define('WORKERMAN_ADMIN_CONFIG', [
    'port'            => $adminPort, // 独立端口号，方便引用，例如 config('gateway.admin.port')
    // 反向代理路径 (对应 deploy/nginx/nginx.site.common.sample 里的 location ~* /adminwss)
    // 域名部署且裸端口从外部不可达时，改成 '/adminwss'，前端会改走 wss://<host>/adminwss 不带端口；
    // 留空 (默认) 保持现状，前端直连 wss://<host>:{$adminPort}
    'proxy_path'      => '/adminwss',
    'gateway_listen'  => "websocket://0.0.0.0:{$adminPort}",
    'gateway_name'    => 'market_relay_AdminGateway',
    'gateway_count'   => $gatewayCount,
    'start_port'      => 22960,
    'register_host'   => '127.0.0.1',
    'register_port'   => 21258,
    'ping_interval'   => 30,
    'ping_data'       => '{"type":"ping"}',
]);
