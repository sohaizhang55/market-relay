# ThinkAdmin 基础设施运维技能 (InfraOps)

本技能定义了项目中后台进程的管理规范，确保所有常驻脚本和定时任务的可观测性与稳定性。

## 1. Systemd 服务定义模板

所有独立运行的脚本（如 Python 监听器）应创建于 `/etc/systemd/system/market_relay-{name}.service`。

### 标准模板：
```ini
[Unit]
Description=market-relay %NAME% Service
After=network.target redis.service

[Service]
Type=simple
User=root
# 必须先定位到项目目录
WorkingDirectory=/path/to/market-relay
# 指定 Python 环境与脚本
ExecStart=/usr/bin/python3 tools/%SCRIPT_NAME%.py
Restart=always
# 强制日志输出到项目日志目录
StandardOutput=append:/path/to/market-relay/tools/%LOG_NAME%.log
StandardError=inherit

[Install]
WantedBy=multi-user.target
```

## 2. Crontab 编写规范

定时任务应遵循“先切换目录，后执行，显式输出”的原则。

### 推荐模式：
```bash
# 每小时清理一次缓存，并记录日志
0 * * * * cd /path/to/market-relay/root && php think xxxx >> ../logs/cron_clean.log 2>&1
```

## 3. 运维路径规范

- **日志位置**: 脚本相关的日志应统一放在 `tools/` 下或项目根目录的 `logs/` 下。
- **权限管理**: 确保运行服务或 Cron 的用户拥有对日志文件的写入权限。
- **路径引用**: 在部署新服务前，务必确认 `WorkingDirectory` 的准确性。
