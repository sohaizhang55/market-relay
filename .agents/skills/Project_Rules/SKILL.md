# [PROTOCOL] 项目架构与核心资产协议 (v4.0)
# 维护者: market-relay Agent

本协议定义了项目的环境载体、全量文件拓扑（集成核心文件标注）及核心资产清单。

market-relay 是独立的**行情中台**：只负责行情采集、落库与分发（Server A），
以及下游订阅方共用的行情服务。会员、资金、交易等业务在下游业务平台，不在本仓库。

---

## 1. ENVIRONMENT_PROTOCOL (环境协议)
- **本地开发环境**: Windows (PHPStudy Pro) | PHP 8.5 | MySQL 5.7 | Redis（db 1）
- **线上服务器环境**: Debian (GNU/Linux) | PHP 8.5 | Nginx
- **行情分发端口**: `market:dispatch` 现货 `-t 1` WebSocket 8594、合约 `-t 2` WebSocket 8595（各一个进程）；inner_api 走 Nginx 32343（见 `deploy/nginx/nginx.site.sample`）
- **即时通讯网关**: GatewayWorker，只有管理端 22365（后台通知铃铛），见 `Workerman/config/config.sample.php`；没有用户端推送
- **行情流程**: 只有一条，不分机器角色：采集 → 更新 pair → 分发；常驻进程清单见 `app\lib\MarketWorkers`
- **路径准则**: 严禁绝对路径，统一使用 `market-relay\root\...` 语义。

---

## 2. FS_TOPOLOGY (全量文件系统拓扑)

```text
← market-relay/ (Git 仓库根目录)
├── root/                     ← ThinkPHP 8 + ThinkAdmin 6 主体
│   ├── app/
│   │   ├── admin/            ← 系统管理模块 (内置：登录、权限、菜单、用户、队列)
│   │   │   └── controller/Login.php ← 管理端登录（含 Google 2FA）
│   │   ├── business/         ← 业务后台（行情配置相关页面）
│   │   │   ├── controller/   ← Market(原始行情/K线/深度) · MarketMonitor(链路监控)
│   │   │   │                   · MarketPipeline(落库诊断) · MarketCalendar(交易日历) · CoinCategory
│   │   │   │                   · Domain(域名与 SSL，见 ThinkAdmin_SSLOps)
│   │   │   ├── service/
│   │   │   │   ├── MarketService.php   ← 行情心脏：源层落库、pair 层推导、热层/脏集合
│   │   │   │   └── WsService.php / AdminNoticeService.php ← 后台推送与通知
│   │   │   └── view/         ← Layui 模板
│   │   ├── inner_api/        ← 机器间内部接口（订阅方回补；IP 白名单 + X-Inner-Token 鉴权）
│   │   ├── command/          ← 常驻进程与运维命令（market:* / alert:* / xdata:db / xadmin:task）
│   │   ├── lib/              ← 核心库：各交易所 REST、MarketWireProtocol(分发协议)、MarketPublisher、
│   │   │                       MarketSnapshotStore、心跳、MarketMetrics、MarketWorkers(应常驻清单)、SecurityService(加解密)
│   │   ├── service/MarketSessionService.php ← 开休市判定
│   │   ├── enum/             ← 业务枚举 (PHP 8.1 Backed Enums)
│   │   ├── model/            ← SystemUser · AppUsers
│   │   └── common.php        ← 全局函数（encrypt_key / redis_client / business_log / sys_conf）
│   ├── config/               ← 配置；app/database/log/key/admin/market 为 *.sample.php 复制而来，均 gitignore
│   ├── database/
│   │   ├── migrations/       ← Phinx 迁移
│   │   ├── seeds/            ← InitMasterMenu · AddAdminUser · InitCoinCategory · InitMarketData · InitExchangePair
│   │   ├── market_data.sql   ← 行情源
│   │   ├── exchange_pair.sql ← 交易对 → 行情源映射
│   │   ├── system_menu.sql   ← 后台菜单
│   │   ├── market_calendar_holiday.json ← 休市日历数据（market:import_calendar 导入）
│   │   └── db_structure.sql  ← 数据库结构快照 (由 xdata:db 生成)
│   ├── extend/GatewayClient/ ← Socket 通信客户端
│   └── think                 ← ThinkPHP CLI 总入口（需要 php.ini 配置 bolt.key）
├── Workerman/                ← GatewayWorker 依赖 (composer) 与配置 (config/)
├── deploy/                   ← Linux 部署与运维
│   ├── install.sh            ← 项目级安装（可重复执行；不做整机初始化，crontab 合并不覆盖）
│   ├── init.sh / update.sh   ← 数据初始化（install.sh 调用）/ 常规部署
│   ├── backup/mysql.sh       ← 数据库备份（账号取自 root/.env，失败不覆盖上一份好备份）
│   ├── restart_market.sh     ← 下发全部行情常驻进程
│   ├── stop_all_sockets.sh · clear.sh · market_health.sh · verify_guard.sh · catch_stall.sh
│   ├── lib/run_daemon.sh     ← 常驻进程下发函数
│   ├── cron/                 ← crontab：deploy（队列、备份）· root（校时、SSL 申请）
│   ├── nginx/                ← 站点配置模板（含 SSL 模板）
│   └── extensions/           ← bolt、redis 等 PHP 扩展
├── tools/                    ← Python 运维脚本：reset_nginx_ssl.py（域名 SSL 自动申请）· utils.py├── ssl/                      ← 证书脚本模板 (*.sample)；生成的私钥与证书被 gitignore
└── win_deploy/restart_market.bat ← Windows 本地：每个常驻进程开一个窗口
```

---

## 3. CORE_TOOLS (核心维护工具)

> **重要**：所有 `php think` 指令必须在 `root/` 目录下执行，因为 ThinkPHP CLI 入口文件 `think` 位于该目录。
> 执行前须先切换目录：`cd root/` (Linux/Mac) 或 `Set-Location root` (PowerShell)。

- **结构快照**: `php think xdata:db`（扫描库并同步到 `database/db_structure.sql`）
- **菜单同步**: `market-relay\root\database\system_menu.sql` + `php think seed:run -s InitMasterMenu`
- **定时任务注册**: `php think xadmin:task`，执行依赖 `php think xadmin:queue start`
- **巡检**: `market:config_check` · `market:data_check` · `market:schema_check` · `market:inner_check --url=<站点地址>` · `command_heartbeat:check_expire --verify`

---

## 4. 相关规范引用 (References)
- **逻辑与 UI 标准**: `.agents/skills/Coding_Standards/SKILL.md`
- **注释规范**: `.agents/skills/Comment_Standards/SKILL.md`
