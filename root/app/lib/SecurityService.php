<?php

namespace app\lib;

/**
 * 安全基础服务
 * Class SecurityService
 * @package app\lib
 */
class SecurityService
{
    /**
     * 默认加密密钥 (建议在 config/key.php 中配置 new_key)
     * @var string
     */
    private static $defaultKey = '3133d6b46fc24996aac8f32605bc0817';

    /**
     * 数据加密 (含指纹校验)
     * @param string $value 待加密字符串
     * @param string|null $key 加密密钥
     * @return string
     */
    public static function encrypt(string $value, ?string $key = null): string
    {
        $key = $key ?: config('key.new_key', self::$defaultKey);
        $enstr = base64_encode(self::rc4($value, (string)$key));
        // 拼接 MD5 指纹作为前缀，用于解密时校验数据完整性
        return md5($enstr) . $enstr;
    }

    /**
     * 数据解密 (含指纹校验)
     * @param string $value 待解密字符串 (含 32 位 MD5 前缀)
     * @param string|null $key 加密密钥
     * @return string
     */
    public static function decrypt(string $value, ?string $key = null): string
    {
        if (strlen($value) < 32) return '';

        $key = $key ?: config('key.new_key', self::$defaultKey);
        $sign = substr($value, 0, 32);
        $enstr = substr($value, 32);

        // 校验数据完整性 (指纹比对)
        if (md5($enstr) !== $sign) {
            return ''; // 校验失败，说明数据被篡改或损坏
        }

        return self::rc4(base64_decode($enstr), (string)$key);
    }

    /**
     * RC4 对称加密算法
     * @param string $data 要加密/解密的数据
     * @param string $pwd 加密使用的密钥
     * @return string
     */
    private static function rc4(string $data, string $pwd): string
    {
        $key = $box = [];
        $pwd_length = strlen($pwd);
        $data_length = strlen($data);
        $cipher = '';

        for ($i = 0; $i < 256; $i++) {
            $key[$i] = ord($pwd[$i % $pwd_length]);
            $box[$i] = $i;
        }
        for ($j = $i = 0; $i < 256; $i++) {
            $j = ($j + $box[$i] + $key[$i]) % 256;
            $tmp = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $data_length; $i++) {
            $a = ($a + 1) % 256;
            $j = ($j + $box[$a]) % 256;
            $tmp = $box[$a];
            $box[$a] = $box[$j];
            $box[$j] = $tmp;
            $k = $box[(($box[$a] + $box[$j]) % 256)];
            $cipher .= chr(ord($data[$i]) ^ $k);
        }
        return $cipher;
    }
}
