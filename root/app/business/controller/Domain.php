<?php

namespace app\business\controller;

use think\facade\Request;

/**
 * 域名与 SSL 配置管理
 *
 * 只写信号，不执行任何系统命令：保存后把待申请的域名写进 Redis 的 {项目目录名}_ssl_config，
 * 由 cron 每分钟跑的 tools/reset_nginx_ssl.py 读取，去申请证书、改 nginx 配置并重载。
 * 两边的 Redis 连接都取自 root/.env 的 CACHE_REDIS_*，库号不一致脚本就收不到信号。
 * @class Domain
 * @package app\business\controller
 */
class Domain extends Base
{
    /**
     * 域名及 SSL 参数设置
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '域名参数设置';

        // 动态生成 Redis 键名：{项目根目录名}_ssl_config，与 reset_nginx_ssl.py 的 project_prefix 一致
        $ssl_conf_cache_key = basename(dirname(dirname(dirname(dirname(dirname(__FILE__)))))) . "_ssl_config";
        $redis = redis_client();

        // 1. 获取当前 Redis 存储的 SSL 配置
        $ssl_config_raw = $redis->get($ssl_conf_cache_key);
        if (!$ssl_config_raw) {
            $ssl_config = [
                'change_ssl_domain' => 0,
                'ssl_domain'        => '',
                'suc_ssl_domain'    => ''
            ];
            $redis->set($ssl_conf_cache_key, json_encode($ssl_config));
        } else {
            $ssl_config = json_decode($ssl_config_raw, true);
        }

        $old_ssl_domain = $ssl_config['ssl_domain'] ?? '';

        if ($this->request->isPost()) {
            foreach (Request::post() as $key => $value) {
                // 保存到系统配置表 (system_config)
                sysconf($key, $value);

                // 特殊处理 HTTPS 域名逻辑
                if ($key == "ssl_domain") {
                    $new_ssl_domain = trim($value);
                    if ($new_ssl_domain && $old_ssl_domain != $new_ssl_domain) {
                        if (isset($ssl_config['change_ssl_domain']) && $ssl_config['change_ssl_domain'] == 1) {
                            $this->error('HTTPS 的配置，系统正在执行之前的配置，请稍后再更改');
                        }
                        // 标记为需要重新申请 SSL
                        $ssl_config['change_ssl_domain'] = 1;
                    }
                    $ssl_config['ssl_domain'] = $new_ssl_domain;
                    // 同步写回 Redis
                    $redis->set($ssl_conf_cache_key, json_encode($ssl_config));
                }
            }

            return $this->success('操作成功!');
        }

        $this->assign('ssl_config', $ssl_config);
        return $this->fetch();
    }
}
