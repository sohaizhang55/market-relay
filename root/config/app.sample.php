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
    // 应用命名空间
    'app_namespace' => '',
    // 应用快速访问
    'app_express' => true,
    // 是否启用路由
    'with_route' => true,
    // super_user —— 超级用户账号，跳过全部菜单与节点鉴权。默认 ['admin']
    //   由 seeder AddAdminUser 按 config/admin.php 中 role = superadmin 整段改写（固定含 admin），
    //   手改会在下次跑 seed 时被覆盖；必须保持单层数组字面量，否则 seed 报错
    'super_user' => ['admin'],
    // 默认时区 - 推荐项目开始前定好 , 开始后不建议修改 ， 推荐UTC
    'default_timezone' => 'America/New_York',
    // 应用映射（多应用模式有效）
    'app_map' => [
        'apidoc' => 'apidoc',
    ],
    // 域名绑定（多应用模式有效）
    'domain_bind' => [],
    // 禁止访问（多应用模式有效）
    'deny_app_list' => [],
    // CORS 启用状态（默认开启跨域）
    'cors_on' => true,
    // CORS 配置跨域域名（仅需填域名，留空则自动域名）
    'cors_host' => [],
    // CORS 授权请求方法
    'cors_methods' => 'GET,PUT,POST,PATCH,DELETE',
    // CORS 跨域头部字段
    'cors_headers' => 'Api-Type,Api-Name,Api-Uuid,Jwt-Token,Api-Token,User-Form-Token,User-Token,Token,Lang',
    // X-Frame-Options 配置
    'cors_frame' => 'sameorigin',
    // RBAC 登录页面（填写登录地址）
    'rbac_login' => '',
    // RBAC 忽略应用（填写应用名称）
    'rbac_ignore' => ['index', 'api', 'apidoc'],
    // 显示错误消息内容，仅生产模式有效
    'error_message' => '页面错误！请稍后再试～',
    // 异常状态模板配置，仅生产模式有效
    'http_exception_template' => [
        404 => syspath('public/static/theme/err/404.html'),
        500 => syspath('public/static/theme/err/500.html'),
    ],
];
