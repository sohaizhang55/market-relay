<?php

use app\model\SystemUser;
use think\migration\Seeder;

/**
 * 自动同步管理员账户 —— 把 config/admin.php 的子管理员写进 system_user，并按 role 改写 config/app.php 的 super_user
 *
 * 触发  deploy/init.sh · 手工 php think seed:run -s AddAdminUser
 * 依赖  config/admin.php（install.sh 跑完 init.sh 即删除，所以 role 必须固化进 app.php，运行时不能再读 admin.php）
 */
class AddAdminUser extends Seeder
{
    /**
     * Run Method.
     */
    public function run(): void
    {
        // 获取管理员配置
        $adminUsers = config('admin.admin_users');
        if (!is_array($adminUsers)) {
            echo "No admin users found in config/admin.php\n";
            return;
        }

        $superUsers = $this->syncSuperUser($adminUsers);

        foreach ($adminUsers as $user) {
            $username = $user['username'] ?? '';
            if (empty($username)) continue;

            // 1. 使用模型查找用户 (如果没找到会返回一个空模型对象)
            $userModel = SystemUser::where('username', $username)->findOrEmpty();

            // 2. 准备数据 (模型对应的修改器会自动处理密码 MD5 和 2FA 加密)
            $data = [
                'username' => $username,
                'nickname' => '系统管理员-' . $username,
                'password' => $user['password'] ?? $username,
                'status'   => 1,
                'is_deleted' => 0,
                'google_auth_secret' => $user['google_auth_secret'] ?? '',
            ];

            // 3. 执行保存 (模型会自动判断是执行 Insert 还是 Update)
            $userModel->save($data);

            if ($userModel->wasRecentCreated) {
                echo "Added admin user: {$username}\n";
            } else {
                echo "Updated admin user: {$username}\n";
            }

            // 非超管靠后台授权取菜单，authorize 为空即登录后无菜单、首页卡 loading。
            // 只警告不跳过；$data 不含 authorize，后台分配的权限组重跑 seed 不会被覆盖
            if (!in_array($username, $superUsers, true) && empty($userModel->getData()['authorize'] ?? '')) {
                echo "WARNING: {$username} 不是 superadmin 且未授权，请用 admin 登录后台在「系统用户」给该账号分配权限组，否则登录后无菜单\n";
            }
        }
    }

    /**
     * 按 role = superadmin 整段改写 config/app.php 的 super_user，返回写入后的名单（固定含 admin）。
     *
     * 匹配不到、写坏或回读不一致即还原原文件并抛异常：静默跳过的后果是子管理员登录后首页卡 loading
     */
    private function syncSuperUser(array $adminUsers): array
    {
        $file = app()->getConfigPath() . 'app.php';
        $superUsers = array_values(array_unique(array_merge(['admin'], array_column(
            array_filter($adminUsers, fn($user) => ($user['role'] ?? '') === 'superadmin'),
            'username'
        ))));

        $origin = file_get_contents($file);
        $pattern = '/([\'"]super_user[\'"]\s*=>\s*)\[[^\]]*\]/';
        if (preg_match_all($pattern, $origin) !== 1) {
            throw new RuntimeException("{$file} 中找不到唯一的 'super_user' => [...] 单层数组字面量，无法写入超管名单");
        }

        $literal = '[' . implode(', ', array_map(fn($name) => var_export($name, true), $superUsers)) . ']';
        $content = preg_replace_callback($pattern, fn($m) => $m[1] . $literal, $origin);
        if ($content === $origin) {
            echo "super_user unchanged: " . implode(', ', $superUsers) . "\n";
            return $superUsers;
        }

        file_put_contents($file, $content, LOCK_EX);
        try {
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($file, true);
            }
            $written = (include $file)['super_user'] ?? null;
        } catch (Throwable $e) {
            $written = $e;
        }
        if ($written !== $superUsers) {
            file_put_contents($file, $origin, LOCK_EX);
            $reason = $written instanceof Throwable ? $written->getMessage() : var_export($written, true);
            throw new RuntimeException("super_user 写入后回读不一致，已还原 {$file}：{$reason}");
        }

        echo "super_user updated: " . implode(', ', $superUsers) . "\n";
        echo "NOTE: 若 opcache.validate_timestamps=0，需 reload php-fpm 才生效\n";
        return $superUsers;
    }
}
