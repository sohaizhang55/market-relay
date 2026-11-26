<?php

namespace app\model;

use think\Model;

/**
 * 系统用户模型
 * Class SystemUser
 * @package app\model
 */
class SystemUser extends Model
{
    // 指定数据库表名
    protected $table = 'system_user';

    // 自动写入时间戳字段
    protected $createTime = 'create_at';
    protected $updateTime = false; // 如果有 update_at 可以设为 true

    /**
     * 密码修改器 (自动 MD5)
     * @param string $value
     * @return string
     */
    public function setPasswordAttr(string $value): string
    {
        // 如果已经是 32 位 MD5 则不再加密（防止二次加密）
        if (preg_match('/^[a-f0-9]{32}$/', $value)) {
            return $value;
        }
        return md5($value);
    }

    /**
     * Google 2FA 密钥修改器 (自动加密)
     * @param string $value
     * @return string
     */
    public function setGoogleAuthSecretAttr(string $value): string
    {
        if (empty($value)) return '';

        // 简单防止重复加密: SecurityService 加密后的长度通常较长且含有 MD5 前缀
        // 这里可以直接调用 encrypt_key，因为它内部会处理
        return encrypt_key($value);
    }

    /**
     * 获取解密后的 Google 2FA 密钥 (读取器)
     * @param string $value
     * @return string
     */
    public function getGoogleAuthSecretAttr(string $value): string
    {
        if (empty($value)) return '';
        return decrypt_key($value);
    }
}
