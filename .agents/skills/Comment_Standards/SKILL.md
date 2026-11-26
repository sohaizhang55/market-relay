# [PROTOCOL] 注释规范协议 (v1.0)
# 维护者: market-relay Agent

注释解释**为什么**，不复述**做了什么**。复制进注释的事实必须标源，并与源一致。

## [PROTOCOL_INDEX] 索引
- **1. [Scope]** 涵盖范围：哪种构造必须写什么
- **2. [Format]** 注释格式：配置项 / enum / 函数 / trait / command
- **3. [API]** api 与 inner_api 各自的契约
- **4. [Rules]** 硬规范：标源、禁省略号、该写、不该写、标注词、同步
- **5. [Special]** 特殊注释：类头 / 流程说明 / 逻辑说明 / 函数说明

## 1. 涵盖范围 (Scope)

| 构造 | 类头必写 | 影响面 |
|---|---|---|
| enum | 用途 · 每个 case 的语义 · 兜底值及理由 | 五槽必填，见 §2.2 |
| trait | 替宿主承担什么 · 宿主必须满足的前提 | 宿主清单 |
| abstract class | 子类必须实现什么 · 可以覆盖什么 | 子类清单 |
| command | 用途 · 命令名 · 触发方式 · 单例约束 · **用法块（每种模式一行）** | 依赖的配置键与 Redis key |
| 全局函数 (`app/common.php`) | 参数契约 · 失败时返回什么 | 标「全仓可用」 |
| api/controller | 接口说明 · `@param` 带类型 · 必填标记 · `@return` | 见 §3.1 |
| inner_api/controller | 用途 · 完整 URL · 调用方 · 返回形状对齐谁 | 见 §3.2 |
| public 方法 | 首句摘要 | — |
| 配置项 | 是什么 · 合法值（标源）· 默认值 | — |
| migration | 为什么改这个列 | — |
| service / controller 类 | 这一层负责什么、不负责什么 | 不强制 |
| private 方法 | 不强制，非显然时才写 | — |

## 2. 注释格式 (Format)

### 2.1 配置项

```php
// key —— 一句话是什么。合法值 a | b（源 XxxEnum），默认 a
//   a  一句短说明
//   b  一句短说明
'key' => env('ENV_VAR', 'a'),
```

配置项写字面量，**不引 `Enum::CASE->value`**——配置要保持简单。合法值与默认值可以照抄，但必须标源，且默认值与右侧一致。

### 2.2 enum

```php
/**
 * 一句话用途
 *
 * CASE_A  语义
 * CASE_B  语义
 *
 * 兜底 CASE_A：一句话理由
 *
 * 影响面
 *   配置  <config 路径> · env <VAR>        或 无
 *   消费  <谁按它分支>                     或 无
 *   校验  <谁校验合法值>                   或 无
 *   落地  <DB 列 / Redis key>             或 无
 *   前端  EXPORT_API=true → 前端字典契约    或 无
 */
```

- **每槽必答，写「无」也是答案**——「无」界定爆炸半径。
- **写谁拥有这条边，不写谁碰过它**：超过 4 个具体文件就改写模块名或层名。

### 2.3 函数 / 方法

```php
/**
 * 首句摘要，一句话说清做什么。
 *
 * @param string $key  仅当参数有非显然约束（单位、范围、null 的含义）时才写
 * @return array       类型系统已表达的不重复
 */
```

**`@param` 要么全写、要么一个都不写**——写一半会让读者以为剩下的参数不存在。

### 2.4 trait / abstract class

```php
/**
 * 一句话：它替宿主承担什么
 *
 * 前提  宿主必须满足的条件
 * 宿主  A · B · C
 */
```

### 2.5 command

```php
/**
 * 一句话用途
 *
 * 命令  php think market:huobi
 * 触发  systemd market_relay-market-huobi-1.service   （或 xadmin:task / 手工 / 巡检）
 * 单例  Redis 锁 market_relay_market_huobi_1          （或 无）
 * 依赖  配置 market.* · Redis market:dirty:*
 *
 * 用法:
 *   php think market:huobi start -t 1 -d         # 现货采集器，后台常驻
 *   php think market:huobi start -t 2 -d         # 合约
 *   php think market:huobi status -t 1           # 看进程状态
 *   php think market:huobi stop -t 1
 */
```

**不写「支持 daemon」，写出那一行命令。** 每个模式、每个必填参数组合都要在「用法」块里占一行，行尾用 `#` 说明它做什么。

### 2.6 command 的固定约定

下面四类语义已在代码里稳定成型，新命令**沿用，不要另造**。

| 形态 | 语义 | 现有用例 |
|---|---|---|
| `action` 参数 `start\|stop\|restart\|reload\|status` | Workerman 常驻进程，默认 `start` | 全部 17 个带 `--daemon` 的命令 |
| `-d` / `--daemon` | 后台常驻。与 action 连用：`start -d` | 同上 |
| `--verify` | **只给退出码**：不打印、不发通知、不写状态。`0` = 通过，`1` = 有问题 | `market:config_check` · `market:schema_check` · `market:kline_reconcile` · `heartbeat:check_expire` |
| `--force` | 清掉去重状态，强制重新通知或重跑 | `heartbeat:check_expire` · `market:price_check_expire` |

- **必填选项要手工校验**：框架表达不了「这个选项必须给」，`Option::VALUE_REQUIRED` 只保证「给了就得带值」。要在 `execute()` 开头校验、缺失时打印 Usage 示例并退出（见 `MarketCollectorAbstract` 的 `Usage Error: --type (-t) is REQUIRED.`），并且「用法」块里**每一行都带上它**，不要留一个不带 `-t` 的示例行。
- **多模式要写为什么有多个模式**：每个模式单独一段，写是什么、什么时候用、与其他模式的关系。模式之间的关系比罗列选项重要。
- **危险开关写清有几道防护**：会真写库、发通知、打扰告警群的模式必须自带确认选项（`--confirm-write` / `--send`）。注释写**一共有几道防护、分别是什么**，而不是只写「小心」——读的人要知道哪些是代码在拦、哪些只是那行字在拦。
- **退出码写进注释**：凡被部署脚本或巡检调用的命令必须写清退出码含义。本项目统一 `0` = 通过，`1` = 有问题。

## 3. api 与 inner_api (API)

`deploy/gen_apidoc.sh` 调 `php think make:apidoc`，扫描范围只有 `app/api/controller`、`app/enum`（仅 `EXPORT_API = true`）与 `app/api/service/WsService.php`——**`app/inner_api` 不在其中**。

| | api | inner_api |
|---|---|---|
| 消费方 | 前端 App | B / C 服务器上我们自己的进程 |
| 文档 | `make:apidoc` 自动生成 | 无生成物，**注释即唯一文档** |
| 注释语法 | 受解析器约束，写错即文档错 | 自由，但 URL 与调用方必写 |
| 鉴权 | `Authorization: Bearer <Token>` | `InnerAuth` 三道闸 |

### 3.1 api/controller —— 注释就是对外契约

```php
/**
 * 一句话接口说明
 *
 * @param string $coin   币种代码 [必填]
 * @param int    $page   页码 [选填]
 * @return int    data.total          总数
 * @return string data.list[].coin    币种
 * @return success { code: 1, msg: 'ok', data: [...] }
 * @return error   { code: 0, msg: 'invalid_coin' }
 */
public function list() {}
```

- **类型必写**：`@param $coin 币种` 会让解析器把 `$coin` 当类型、把「币种」当参数名，整行字段错位。
- **必填标记写在说明里**：`[必填]` `[选填]`（方括号、圆括号、方头括号都认）。只认这两个词；不写则文档显示 `-`。
- **两种 `@return`**：`@return <类型> data.<字段> <说明>` 进「返回数据」表；其余 `@return <标签> <JSON>` 进「返回示例」，惯用标签 `success` / `error`。
- **方法名即 URL 段**：端点 = `/api/` + 小写控制器名 + `/` + 方法名。改方法名就是改接口地址。
- **排除用 `@ignore`**：写在类头或方法头，不要靠「不写注释」。
- **enum 字典**：要进文档必须 `EXPORT_API = true`，Label 列取 `#[Label]`。
- **说明会被拼成一行**：所有非 `@` 行以空格连接，别在说明里排版换行。
- **改完必须跑 `deploy/gen_apidoc.sh`**，与代码同一个 commit。
- 列表接口的 `@return success` 示例必须写 `data: [...]`，见 Coding_Standards §13b。

### 3.2 inner_api/controller —— 注释是唯一文档

```php
/**
 * 一句话用途。若是某项数据的唯一供数通道，写明。
 *
 * GET /inner_api/pair/klines?pair=btcusdt_2&period=60&since_ts=1792...&to_ts=0&limit=500
 * 调用方  订阅方的 K 线断档回补
 * 返回    每行形状与 WS kline 帧对齐（MarketPublisher::publishKline）
 */
```

- **URL 写全**，带一组真实示例参数——没有生成文档，这一行就是接口定义。
- **URL 段逐字等于方法名**：框架不做 snake_case ↔ camelCase 转换，`fundingRates` 不是 `funding_rates`。
- **写明调用方**：点名哪个 command 或方法在调，与 enum 五槽的「消费」同类。
- **返回形状对齐谁必须点名**：否则改一边不会有人知道要改另一边。
- **类头写鉴权**：指向 `app\inner_api\middleware\InnerAuth`，说明两道闸顺序 IP 白名单 → `X-Inner-Token`。
- **不写 `@param` / `@return`**：这里没有任何工具会读它们，写上去只会伪装成「有工具在管」。要说明参数就写进 URL 示例。

## 4. 硬规范 (Rules)

- **标源**：注释里复制的合法值、默认值、阈值必须注明来源，并与来源一致。目的是让「注释对不对」变成一句 grep 能回答的问题。
- **禁省略号**：影响面、宿主清单、子类清单内禁止 `…` `等` `其他`。它们让一句话既不是完整清单也不是「无」，无法证伪。写不全就写模块名或层名。
- **禁论证**：注释写「是什么 · 怎么用」，不写「为什么这样设计」。方案比选、被否决的做法、踩过的坑归 `.agents/plan/` 文档，注释用一行 `设计依据：<文档> §<节>` 指过去。判据：删掉这一段，读代码的人还知不知道怎么用它——知道就该删。不适用于 §4「该写」里的四类（契约、不变量、事故锚点、刻意不做的事），那些是维护者必须当场看到的事实。

**该写**
- 接口契约、非显然的不变量
- 隐藏的设计决策、某个抽象存在的理由
- 调用方不需知道、但维护者需知道的实现事实
- 事故锚点：带日期与现象
- 刻意不做的事——防后人顺手补上

**不该写**
- 装饰性分隔线
- 变更历史（git 记）
- 注释掉的代码
- 复述代码、类型系统已表达的
- 无工单的临时 workaround

- **标注词**：`TODO / FIXME / HACK / NOTE / WARNING / PERF / SECURITY / BUG / DEPRECATED`。`FIXME` 与 `HACK` 必须带日期或工单号，否则视为永久债务。
- **同步**：改签名必须同 commit 改 docblock。改本协议必须同时改 `CLAUDE.md` 与 `.cursorrules`——它们是仅有的两个 agent 入口。
- **已知代价**：§3.1 的 `@return success {...}` 与 `@return string data.x` 借用了 PHPDoc 的 `@return` 标签，IDE 与 PHPStan 会误读成返回类型。这是本项目的正式约定（`MakeApiDoc.php` 依赖此语法），不判违规；代价是这两处的 `@return` 不能当类型信息看。

## 5. 特殊注释 (Special)

四类需要额外篇幅，每类都有长度上限——超了不是注释的问题，是代码的问题。

### 5.1 类头

槽位（§2.2 enum / §2.4 trait / §2.5 command）该有的一个不少，**槽位之外不加段落**。

上限 20 行，含空行与「用法」块。超了先把论证搬进 `.agents/plan/`，只留一行 `设计依据：`。

```php
// ✅ 职责一句话，论证让位给文档指针
/**
 * 导入节假日休市日历 —— 把导出脚本的 JSON 灌进假日表与时段表，幂等
 *
 * 命令  php think market:import_calendar
 * 触发  deploy/update.sh · deploy/market_health.sh · 手工
 * 单例  无（幂等，重跑报 0 行改动）
 * 依赖  表 market_calendar_holiday · market_calendar_session
 *       数据 database/market_calendar_holiday.json（tools/export_exchange_calendars.py 产出）
 *
 * 用法:
 *   php think market:import_calendar                     # 假日 + 时段一起导
 *   php think market:import_calendar --dry-run           # 只报告不写库，含孤儿清理预演
 *   php think market:import_calendar --force-prune       # 放宽孤儿作废比例阈值
 *
 * 退出码  0 = 通过；1 = 体检异常 / 孤儿清理被阈值挡下 / 一行都没导进去
 * 设计依据：.agents/plan/market_calendar_module.md §3.4
 */

// ❌ 「为什么不用另一种落点」「当初怎么归错的」「四道防护分别为什么这么设」
//    ——这些是 plan 文档的内容
```

同理适用于**常量与属性**：写它是什么、取值从哪来，不写为什么不放在别处。

### 5.2 流程说明

写在入口方法或类头。**只写阶段和边界，不写每一步。**

```php
/**
 * 落库流程：收帧 → 入内存缓冲 → Timer 批量 upsert → WS 推送
 * 边界：缓冲不跨进程；失败即跳过，不放回脏集合
 */
```

上限 6 步，一行一步。超了说明该拆函数。

### 5.3 逻辑说明

写在分支或算法上方。**写为什么这样判，不写判了什么。**

```php
// 向上取整而非向下：curl 的 TIMEOUT 是整秒，向下取整会让
// 一个 3 秒预算只给得出 2 秒
return max(1, min($preferred, (int)ceil($this->remaining())));
```

上限 3 行。超了说明命名或结构有问题，先改代码。

### 5.4 函数说明

格式见 §2.3。**首句一句话，能一句说清就不写第二句。**

```php
// ✅ 一句话说清
/** 抽干脏集合，把行情热数据批量落库到 market_data */

// ❌ 复述签名
/** 这个方法接收一个数组参数并返回一个数组 */
```

需要 3 行以上才说得清 → 先改命名或拆函数。
