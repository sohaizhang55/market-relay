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

// admin_users —— 子管理员账号，由 seeder AddAdminUser 同步进 system_user；不能用来改 admin 账号
//   role  跑 seed 时生效（seed 把 superadmin 名单写进 config/app.php super_user，本文件随后可删）
//         superadmin = 超级管理员，看到全部菜单
//         不填或其他值 = 普通子管理员：需用 admin 登录后台建权限组，并在「系统用户」给该账号授权，否则登录后无菜单
return [
    "admin_users" => [
        [
            "username" => "default_admin_username",
            "password" => "default_admin_password",
            "google_auth_secret" => "",
            "role" => "superadmin",
        ],
        [
            "username" => "daemon_admin_username",
            "password" => "daemon_admin_password",
            "google_auth_secret" => "daemon_admin_google_secret",
            "role" => "superadmin",
        ],
    ]
];
