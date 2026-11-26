# market-relay

行情中台：从 **火币 (Huobi)**、**币安 (Binance)**、**BingX** 直连采集现货 / 合约 / 传统金融（外汇、贵金属、能源）行情，落库到 MySQL，并通过 WebSocket 分发给下游业务服务器。

基于 ThinkPHP 8 + ThinkAdmin 6，常驻进程使用 Workerman / GatewayWorker。

## 功能概览

- **行情采集**：各交易所 WebSocket 直连，采集价格、K 线、盘口深度、逐笔成交、资金费率
- **落库分离**：采集进程只写 Redis 热层，由 Flusher 批量落库，避免高频行情压垮 MySQL
- **一对多分发**：通过 WebSocket 把 pair 行情推给多台订阅方
- **补数与兜底**：WS 静默时 REST 兜底价格 / 深度，K 线自动保持每个周期最近 2000 根不缺（交易所确认没有的段记下不再问）
- **休市日历**：按交易所日历判断传统金融标的的交易时段与节假日
- **监控告警**：进程心跳、数据陈旧、告警通道自检，告警推送到后台通知 + Telegram
- **管理后台**：币种分类、行情源、交易日历、行情监控、链路状态

## 流程

只有一条流程，不分机器角色：采集 → 更新 pair → 分发。

```
            交易所 (Huobi / Binance / BingX)
                         │ WS / REST
                         ▼
   ┌───────────────────────── market-relay ──────────────────────────┐
   │  采集器 ──► 源层 market_data ──► pair 层 exchange_pair         │
   │             (按 source_key)      (按 source_key 映射，         │
   │                                   原样透传)                    │
   │                                       │                       │
   │                                       ├──► market:dispatch 现货 ws:8594 / 合约 ws:8595
   │                                       └──► inner_api/pair (http)
   └───────────────────────────────────────┬───────────────────────┘
                                           │ 只有 pair：{symbol_code}_{type}
                             ┌─────────────┴─────────────┐
                             ▼                           ▼
                          订阅方                       订阅方
```

各进程与必改的配置项见 `root/config/market.sample.php` 头部。

## 分发协议 v3（订阅方对接）

订阅方只认 **pair**，标识为 `{symbol_code}_{type}`（如 `btcusdt_2`，type：1 现货、2 合约），与本机 `exchange_pair` 按 `symbol_code + type` 对号入座。帧和接口里都**不带**交易所、`source_key`、`pair_id`（各机自增，跨机对不上）。价格是 pair 层的值，即交易所真实价。

### WebSocket

现货与合约由**两个独立的分发进程**分发，各一个端口（对标 Binance 现货与合约分开部署）。一个进程卡住、崩溃或重启，另一类行情照常分发：

| 地址 | 推送范围 | 进程 |
|------|------|------|
| `ws://<A>:8594` | 只有现货（`*_1`） | `market:dispatch -t 1` |
| `ws://<A>:8595` | 只有合约（`*_2`） | `market:dispatch -t 2` |

端口可用 `.env` 的 `MARKET_DISPATCH_SPOT_PORT` / `MARKET_DISPATCH_CONTRACT_PORT` 修改。想对外统一成 `wss://<域名>/spot`、`/contract` 这类地址，在 nginx 里按路径反代到这两个端口即可。

0. **握手请求头带 `X-Inner-Token: <本订阅方 token>`**。token 在后台「行情分发 → 订阅方管理」发放，一个订阅方一个，与 `/inner_api/*` 用同一个。后台「分发鉴权开关」开启后，不带或带错握手直接回 HTTP 403；未开启时可不带，但带上才会被认出、记下最后连接 IP。

1. **连上后先发 hello**（文本帧），不发则一帧都不推。hello 只有 `cmd`、`node`、`streams` 三个字段：

   ```json
   {"cmd":"hello","node":"server-b","streams":["btcusdt@ticker","btcusdt@depth@500ms","ethusdt@kline_1m"]}
   ```

   | 字段 | 含义 |
   |------|------|
   | `streams` | 要哪些 stream，`{symbol}@{名字}`：`ticker`、`markPrice`（仅合约）、`depth`（可带 `@100ms`/`@500ms`/`@1000ms`，默认 100ms）、`trade`、`kline_1m/5m/15m/1h/4h/1d`。symbol 是裸 symbol，端口已决定现货/合约。**不传 = 本端口全部** |
   | `node` | 本机标识，用于 A 侧监控 |

   非法 hello（写了别的字段、stream 写错、现货订 markPrice 等）会收到 `{"_sys":"error","msg":"原因"}`。连上后先按订阅补发一次当前快照，之后某个 stream 一变化就推一条。

2. **行情是二进制帧，一条消息 = 一帧**：`gzdeflate(帧 JSON)`（`MarketWireProtocol::decodeMessage()`）。每帧都带 `stream`、`pair`、`symbol`、`type`、`_channel`：

   | stream | `_channel` | 数据字段 |
   |------|------|------|
   | `@ticker` | `market:raw:price` | `tick{last_price, open, high, low, close, amount, vol, change}`、`ts` |
   | `@markPrice` | `market:raw:mark_price` | `mark_price`、`ts` |
   | `@depth` | `market:raw:depth` | `depth{bids, asks, ts}`（各 20 档） |
   | `@trade` | `market:raw:trade` | `data[{id, price, quantity, direction, ts}]`，最近 N 笔、新到旧，**整窗覆盖**，按 `id` 去重 |
   | `@kline_*` | `market:raw:kline` | `period`、`kline{ts, open, high, low, close, vol, amount}`，当前这一根的最新状态 |

   所有 `ts` 都是毫秒。覆盖语义：同一个 stream 只留最新值，重复投递是幂等的。
   订阅方读得慢、发送缓冲过软水位（上限的 1/4）且还在涨或涨满时，只把这条连接的 depth 降频（100 → 500 → 1000 → 2000 → 5000ms，后两档只用于降级），并发 `{"_sys":"throttle","depth_ms":500}` 通知；30 秒没再降且缓冲回落后升回一档。完整说明见后台「行情分发 → 分发对接说明」，每条连接的现场见「分发监控」。

3. **心跳**：约每 15 秒收到文本帧 `{"_sys":"ping","ts":<毫秒>}`，须回 `{"_sys":"pong","ts":<原样>}`（紧贴 `{"_sys":` 开头，不要带空格），45 秒内没回会被断开。

### HTTP 补数接口（`/inner_api/pair/*`）

请求头带 `X-Inner-Token`（本订阅方在「订阅方管理」里的 token；A 的 `MARKET_INNER_API_TOKEN` 仍然有效，留给本机巡检与还没换 token 的老订阅方），返回 `{"code":1,"data":...}`，失败 `code=0`。

| 接口 | 参数 | 返回 |
|------|------|------|
| `pairs` | — | pair 元数据：精度、状态、`calendar_code`（交易日历，空串 = 24/7）。订阅方不再持有 `market_data`，开休市靠这一列 |
| `prices` | — | 全部 pair 的最新价与标记价 |
| `fundingRates` | — | 全部合约 pair 的资金费率（费率**不走 WS**，这是唯一来源） |
| `depth` | `pair` | 单个 pair 的盘口 |
| `klines` | `pair`、`period`、`since_ts`（开区间）、`to_ts`（闭区间，可选）、`limit` | K 线，升序，`ts` 毫秒 |

## 目录结构

```
market-relay/
├── root/                 ThinkPHP 8 + ThinkAdmin 主体
│   ├── app/
│   │   ├── admin/        系统管理（登录、权限、菜单、用户）
│   │   ├── business/     业务后台（币种分类、行情、交易日历、监控、链路）
│   │   ├── inner_api/    机器间内部接口（订阅方回补数据，token + IP 白名单鉴权）
│   │   ├── command/      常驻进程与运维命令（market:*、alert:* 等）
│   │   ├── service/      业务服务
│   │   ├── lib/          核心库（各交易所 REST、分发协议、心跳、安全加解密等）
│   │   ├── model/        数据模型
│   │   └── enum/         枚举
│   ├── config/           配置（部分为 *.sample.php，需按机器复制）
│   ├── database/         迁移 (migrations) 与种子 (seeds)
│   └── think             CLI 入口
├── Workerman/            GatewayWorker 依赖与配置
├── deploy/               Linux 部署脚本（初始化、更新、重启、巡检、cron、nginx 模板）
└── win_deploy/           Windows 本地开发启动脚本
```

## 环境要求

- PHP 8.5（本地开发与线上一致），扩展：`redis`、`bcmath`、`pdo_mysql`、`mbstring`、**`bolt`**
- MySQL 5.7+ / MariaDB 10.4+（数据库结构快照兼容 MySQL 5.7 与 8.4）
- Redis
- Composer

> `root/think` 启动时会读取 `php.ini` 中的 `bolt.key`，未配置时直接退出并提示 `[ERROR] config not found`。

## 本地安装

### 1. 准备配置

```bash
cd root

cp .env.example .env                       # 数据库、Redis、inner_api 令牌等
cp config/app.sample.php      config/app.php
cp config/database.sample.php config/database.php
cp config/log.sample.php      config/log.php
cp config/key.sample.php      config/key.php
cp config/admin.sample.php    config/admin.php    # 初始管理员账号
cp config/market.sample.php   config/market.php   # 行情端点、token、分发参数

cp ../Workerman/config/config.sample.php ../Workerman/config/config.php
```

以上文件均已加入 `.gitignore`，每台机器各自维护。仓库中 sample 的默认值更新后，可用 `php think market:config_check` 检查本机配置是否落后。

### 2. 安装依赖

```bash
cd root && composer install
cd ../Workerman && composer install
```

### 3. 初始化数据库

先建好库并给 `.env` 中的账号授权，然后在 `root/` 下执行：

```bash
php think migrate:run
php think seed:run -s InitMasterMenu      # 后台菜单
php think seed:run -s AddAdminUser        # 管理员（读取 config/admin.php）
php think seed:run -s InitCoinCategory    # 币种分类
php think seed:run -s InitMarketData      # 行情源
php think market:import_calendar          # 节假日休市日历（幂等，可重复执行）
```

### 4. 启动行情进程

**Windows**（本地开发）：每个进程开一个独立窗口

```bat
win_deploy\restart_market.bat
```

**Linux**（服务器）：代码放到 `/home/deploy/market-relay` 后，以 root 执行项目级安装：

```bash
sudo bash /home/deploy/market-relay/deploy/install.sh
```

它会复制配置、生成 `.env` 中的 inner_api 令牌与 `key.php`、建库授权、接入 nginx、合并 crontab、跑 `init.sh` 并启动行情进程。可重复执行，已有的配置不会被覆盖。它**不做整机初始化**（lnmp、php.ini、防火墙等），前提是机器已具备这些环境，详见脚本头部注释。

之后重启行情进程用 `bash deploy/restart_market.sh`：脚本会下发全部进程（选源进程未启用时会报 `[SKIP]`），最后调用心跳检查核对该跑的进程是否都已启动。

## 常用命令

所有 `php think` 命令都必须在 `root/` 目录下执行。

### 常驻进程

| 命令 | 作用 |
|------|------|
| `market:huobi` / `market:binance` / `market:bingx` | 交易所直连采集（`-t 1` 现货，`-t 2` 合约；BingX 只有 `-t 2`） |
| `market:db_flush` | Redis 热层批量落库，**必须与采集器同时运行** |
| `market:dispatch -t 1` / `-t 2` | 行情分发服务：现货 WS 8594、合约 WS 8595，各一个进程 |
| `market:price_fallback -e <交易所>` | 价格兜底：WS 静默时用 REST 续上成交价 / 标记价 |
| `market:kline_backfill -e <交易所>` | K 线补数：保证每个周期最近 2000 根（`kline_retention`）不缺 |
| `market:funding -e <交易所>` | 资金费率供数（唯一通道） |
| `market:source_select` | 主备切换，默认不启用（`MARKET_SOURCE_SELECT_ENABLE=1` 打开） |

常驻进程统一支持 `start | stop | restart | status`，加 `-d` 后台运行。例如：

```bash
php think market:huobi start -t 1 -d
php think market:huobi status -t 1
```

### 巡检与运维

| 命令 | 作用 |
|------|------|
| `market:data_check [--bad]` | 逐个行情源检查 tick / kline / depth / trade 是否在持续更新 |
| `market:config_check` | 对比本机 `config/market.php` 与仓库 sample |
| `market:inner_check --url=<站点地址>` | 用本机 token 检查指定站点 inner_api 的连通性、鉴权与费率新鲜度（`--url` 必填；`--local` 只看本机配置） |
| `market:schema_check` | 检查数据库结构 |
| `market:kline_reconcile` | 对账源层与受控层两层 K 线 |
| `command_heartbeat:check_expire --verify` | 核对常驻进程心跳 |
| `xdata:db` | 导出数据库结构快照 |

各命令的详细参数与设计说明见 `root/app/command/` 下对应类的头部注释。

## 故障排查

- **命令只输出一行 `proc_open(): Command conversion failed`**：这是框架在 Windows 控制台渲染异常时获取终端宽度失败，真实错误被盖住了。先执行 `set ANSICON=200x1000 (200x50)` 再运行命令，即可看到原始报错。
- **MySQL 价格不更新但 Redis 有数据**：检查 `market:db_flush` 是否在运行。
- **订阅方回补全部 403**：运行 `php think market:inner_check --url=http://127.0.0.1:<SITE_PORT_3>`（端口见 `deploy/ports.env`），它会区分 IP 不在白名单、token 未配置、token 不匹配几种情况。
- **进程日志**：`root/runtime/log/`，业务日志按通道和日期分文件。

## 开发规范

项目编码、注释、架构约定见 `.agents/`：

- `.agents/skills/Project_Rules/SKILL.md` 架构与目录
- `.agents/skills/Coding_Standards/SKILL.md` 编码规范（状态值、bcmath 精度、Layui、数据库、安全）
- `.agents/skills/Comment_Standards/SKILL.md` 注释规范
- `.agents/roles/` 后端开发 / 代码审查职责

## 版权声明

Copyright © 2026 jianxian. All Rights Reserved.

本仓库仅作个人作品展示，未授予任何开源许可。未经作者书面许可，不得复制、修改、分发或用于商业用途。
