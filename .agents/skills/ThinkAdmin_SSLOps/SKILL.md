# ThinkAdmin SSL 自动化运维技能 (SSLOps)

本技能定义了在 ThinkAdmin 框架下，结合 Python 脚本实现域名 SSL 证书自动化申请与 Nginx 配置重载的标准流程。

## 核心架构

该方案采用**异步信号量机制**，规避了 PHP 直接执行耗时系统命令的风险。

1. **PHP 控制端**：后台「平台管理 → 域名管理」(`Domain` 控制器) 向 Redis 写入配置信号。
   - Redis Key: `{项目目录名}_ssl_config`（本项目即 `market-relay_ssl_config`，类型 String，内容为 JSON）
   - 字段：`ssl_domain`（逗号分隔的域名）、`change_ssl_domain`（1 = 待申请）、`suc_ssl_domain`（已成功的域名）
2. **Python 执行端**：`tools/reset_nginx_ssl.py` 由 root 的 crontab 每分钟执行一次（`deploy/cron/root`）。
   - 执行逻辑：读取信号 → 按 `deploy/nginx/nginx.site.sample` 生成站点配置 → 用 `ssl/gen_cert.sh.sample`（acme-tiny）
     申请证书 → 按 `deploy/nginx/nginx.ssl.site.sample` 生成 HTTPS 配置 → `nginx -s reload` → 回写 `suc_ssl_domain`。
   - 手动按当前配置重签：`redis-cli set market-relay_reload_ssl_conf 1`（库号同下）。

## 关键代码位置

- **后端控制器**: `root/app/business/controller/Domain.php`
- **配置视图**: `root/app/business/view/domain/index.html`
- **运维脚本**: `tools/reset_nginx_ssl.py` · `tools/utils.py`
- **模板**: `deploy/nginx/nginx.site.sample` · `deploy/nginx/nginx.ssl.site.sample` · `ssl/gen_cert.sh.sample`

## 最佳实践与注意事项

- **Redis 必须同库**: PHP 端 `redis_client()` 与 Python 端 `utils.redisClinet()` 都取 `root/.env` 的 `CACHE_REDIS_*`。
  库号不一致时脚本读不到信号，后台会一直显示「等待系统生成ssl证书」，且没有任何报错。
- **项目前缀**: 由项目目录名自动推导（PHP 取 `root/` 的上级目录名，Python 取 `tools/` 的上级目录名），部署目录改名后两边仍一致。
- **服务器约定**: Nginx 固定在 `/usr/local/nginx`，`nginx.conf` 里需有一行 `#include <项目>/deploy/nginx/nginx.ssl.site;`（deploy/install.sh 会写入；脚本只切换其注释状态）。
- **幂等性**: 30 天内成功过的域名不重复申请。
- **私钥不入库**: `ssl/` 下除 `*.sample` 外全部被 `.gitignore` 忽略。
- **日志监控**: `logs/tools/reset_nginx_ssl.py.log`；cron 的错误输出在 `logs/reset_nginx_ssl_err.log`。
