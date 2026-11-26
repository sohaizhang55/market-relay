## [PROTOCOL_INDEX] 规范索引目录
- **01. [Cron]** 命令行任务并发与逻辑控制
- **02. [Log]** 业务审计与分层日志规范
- **03. [Core]** ThinkAdmin 框架层开发约定
- **04. [Path]** 语义化图标与全路径禁令；其余注释条款见 Comment_Standards
- **05. [Ops]** Redis 信号量驱动的异步运维
- **06. [Service]** Systemd 与 xadmin:task 双轨任务
- **07. [Env]** .env 敏感配置与凭据隔离
- **08. [Status]** 统一状态枚举化 (1:ON / 2:OFF)；**静态类型/状态必须写 Backed Enum，禁止类内裸数字常量**
- **09. [Enum]** PHP 8.1 业务枚举与注解规范；比较用 `EnumClass::CASE->value`
- **10. [UI]** Layui 结构嵌套与表单渲染标准
- **11. [Biz]** 模块化配置、CSRF 清洗与 SSL 安全
- **12. [DB]** 环境自检与数据库安全查询 (Limit)
- **13. [API]** 接口注释同步与 ApiDoc 构建规范；注释语法见 Comment_Standards §3
- **13b. [API_List]** 列表接口分页响应规范：只返回 `data: [...]`，用 `.items()` 取数据行
- **14. [DB_Field]** 数据库字段命名与时间记录规范
- **15. [Rate_Naming]** 费率与比例字段命名要求

---

## 1. 长时间运行的脚本与命令行工具 (Background Tasks)
- **核心准则**：所有涉及 WebSocket 采集、数据同步、定时清算等需要长时间运行的任务，必须通过 ThinkPHP 的 `command` 命令行工具实现。
- **操作要求**：严禁通过控制器的 HTTP 请求触发此类任务。
- **并发控制**：多个相同的命令行任务同时运行时，必须在代码中使用 Redis 锁定或文件锁定，防止数据竞争。

## 2. 操作审计与日志记录 (Logging & Auditing)
- **日志分类**：不同的业务维度必须使用不同的日志通道。例如：行情采集日志记录到 `logs/market.log`，资产变动日志记录到 `logs/account.log`。
- **内容要求**：所有非幂等的写操作（如余额调整、后台登录、配置修改）必须记录详细的操作上下文，包括操作人 UID、原始数据、变更后数据及操作时间。

## 3. 后端开发与框架约定 (ThinkAdmin Standard)
- **路由约定**：后台业务代码必须严格遵循 ThinkAdmin 的多应用架构，存放于 `app/business/`（业务逻辑）或 `app/admin/`（管理逻辑）中。
- **开发偏好**：开发者目前优先使用原生 SQL/查询构造器进行数据操作，而非复杂的 Model 模型类。

## 4. 语义化与规范化 (Semantics & Standards)
- **图标选择**：优先选用 Layui 内置的语义化图标。例如：客服业务选用 `layui-icon-headset`；链接业务选用 `layui-icon-link`。
- **路径规范**：在所有文档、注释、技能说明中，严禁引用开发者本地的绝对路径（如 `E:\phpstudy...`），必须使用相对于项目主目录的路径（如 `root/app/...`）。
- **注释其余条款**：见 `.agents/skills/Comment_Standards/SKILL.md`（涵盖范围、各构造的注释格式、特殊注释）。

## 5. 异步运维设计 (Async Ops)
- **操作要求**：涉及系统层管理的操作（如 SSL 申请、Nginx 重载），严禁在 PHP 同步进程中直接执行。
- **核心方案**：利用 Redis 信号量作为桥梁，由 PHP 写入配置，独立的后台脚本（如 Python）负责解析并最终执行。

## 6. 服务与定时任务规范 (Service & Cron)
项目中的定时与常驻任务分为以下两种标准模式，必须根据业务场景选择：

- **模式 A：Systemd 系统级服务 (Systemd Services)**
  - **适用场景**：WebSocket 监听、核心行情采集、Redis 消息订阅等需要长时运行且具备自愈能力的底层脚本。
  - **规范**：必须封装为 `systemd` 服务，命名前缀为 `market_relay-xxx.service`。
  - **日志**：必须显式将标准输出重定向到项目 `logs/` 目录下对应的日志文件中。

- **模式 B：ThinkAdmin 业务级队列任务 (xadmin:task)**
  - **适用场景**：一般性周期业务处理（如定时清理过期数据、自动结算、订单状态同步等）。
  - **核心逻辑**：通过执行 `php think xadmin:task` 将任务注册进 `system_queue` 表，利用框架自带的任务循环机制执行。
  - **前提要求**：必须确保后台 `php think xadmin:queue start` 基础进程正在运行（该进程通常由 Systemd 模式守护）。
  - **注册规范**：参考 `root/app/command/Task.php`，使用 `QueueService::register` 配合循环时间 (`loops`) 进行任务注册。

- **通用规范**：定时任务严禁直接使用相对路径，必须使用绝对路径或在脚本内先 `cd` 到项目根目录。所有 Cron 输出必须通过 `>> market-relay/logs/cron.log 2>&1` 记录。

## 7. 敏感信息与配置管理 (.env)
- **操作习惯**：严禁将敏感信息（如数据库密码、Redis 密钥、第三方 API Key）直接硬编码在代码或配置文件中。
- **操作要求**：所有敏感配置必须存放于根目录的 `.env` 文件中，并通过 `env()` 函数进行读取。

## 8. 统一状态枚举化 (Unified Status Management)
- **核心准则**：严禁在控制器或模板中硬编码状态映射数组。所有涉及”开关”、”类型”、”状态”的映射必须由枚举类统一定义。
- **状态码约定**：项目标准遵循 **1 为启用/开启 (ON)，2 为禁用/关闭 (OFF)**。
- **强制 enum**：所有静态类型与状态值（`status`、`type`、`side`、`direction`、`action` 等）**必须**写成 PHP 8.1 Backed Enum，**禁止**在类内以 `const TYPE_XXX = 1` 的形式直接硬编码整数。类内常量只允许作为 enum value 的透传桥接（`const TYPE_LIMIT = ContractOrderTypeEnum::LIMIT->value`），不得直接赋数字。

## 9. 枚举与注解规范 (Enum & Attribute Conventions)
- **文件名规范**：所有存放于 `app/enum/` 目录下的业务枚举文件，必须以 **`Enum`** 结尾（例如 `CommonStatusEnum.php`）。
- **通用前缀**：凡是跨模块使用的、具有通用性质的枚举类，必须以 **`Common`** 作为名称前缀。
- **关联同步**：重命名枚举时必须同步更新内部引用的 `Label` 注解类路径、业务逻辑调用及语言包键名。
- **影响面注释**：新增或重命名枚举时，必须同步维护类头的五槽影响面（配置 / 消费 / 校验 / 落地 / 前端），格式见 `.agents/skills/Comment_Standards/SKILL.md` §2.2。
- **比较方式**：代码中比较枚举值必须使用 `EnumClass::CASE->value`，禁止直接写裸数字（如 `$status === 1`）。
- **已有 enum 目录**：`app/enum/` 下已存在 `ContractOrderTypeEnum`、`ContractOrderSideEnum`、`ContractStopOrderTypeEnum`、`ContractStopOrderStatusEnum`、`SpotOrderTypeEnum`、`SpotOrderDirectionEnum` 等，新功能先查找再创建，避免重复。

## 10. UI 交互与 Layui 规范 (UI & Layui Standards)
- **版本基准**：本项目锁定使用 **Layui v2.13.3**。
- **选项卡结构**：`.layui-tab-title` 与 `.layui-tab-content` 必须是**同级直接兄弟节点**。
- **表单包裹**：严禁将 `<form>` 标签插入在 title 与 content 之间。必须将整个 `.layui-tab` 容器包裹在 `<form>` 内部，或将 `<form>` 放入具体的 `.layui-tab-item` 中。
- **动态渲染**：在 PJAX 加载或动态插入 HTML 后，必须显式调用 `layui.form.render()` 或 `layui.element.render()`。
- **[强制] 静态映射必须在 Controller 完成**：后台列表页中所有静态数据的文字转换（状态、方向、类型、颜色 class 等），**必须**在对应的 `_xxx_page_filter(array &$data)` 方法中统一处理，写成 `$vo['xxx_text']`、`$vo['xxx_color']` 等字段。**严禁**在 `.html` 模板的 `templet` JS 函数中出现魔法数字或硬编码字符串比较（如 `d.side == 1`、`d.status === 2`）。View 层只允许消费 Controller 已映射好的字段：`{{d.xxx_text}}`、`{{d.xxx_color}}`。
  ```php
  // ✅ 正确：映射在 Controller _filter 方法
  $sideColorMap = [ContractOrderSideEnum::LONG->value => 'color-green', ContractOrderSideEnum::SHORT->value => 'color-red'];
  $vo['side_color'] = $sideColorMap[$vo['side']] ?? '';
  $vo['side_text']  = $sides[$vo['side']] ?? $vo['side'];

  // ❌ 禁止：在 HTML templet 中硬编码判断
  // templet: function(d){ return d.side == 1 ? '<span class="color-green">做多</span>' : '...'; }

  // ✅ 正确：View 只消费
  // templet: '<div><span class="{{d.side_color}}">{{d.side_text}}</span></div>'
  ```
- **ThinkPHP 模板条件语法**：`{if}` 条件块内**禁止**使用 `|default=''` 等模板修饰器，应改用原生 PHP 空值合并运算符 `??`（如 `{if ($get['key']??'') == $val}`）。

## 11. 消息通知与业务配置规范 (Notification & Config Standards)
- **配置隔离**：模块化配置项在数据库中必须带有功能前缀（例如 `sms.xxx`）。
- **令牌清洗**：在循环保存配置时，**必须显式跳过 `_token_` 字段**，严禁将 CSRF 令牌存入数据库。
- **网络安全**：所有涉及第三方 API 请求的模块必须提供 SSL 验证开关，标准逻辑为 **1 开启，2 关闭**。

## 12. 环境诊断与数据库操作规范 (Environment & DB Ops)
- **自检意识**：Agent 在处理环境报错前应主动执行 `php -v` 或 `php -m`。
- **安全查询**：执行 `SELECT` 必须强制携带 `LIMIT`。在执行数据变动操作前，应执行 `DESC table_name` 确认表结构。
- **凭据读取**：Agent 必须通过读取 `root/.env` 获取数据库连接信息，严禁在对话中明文记录密码。

## 13. API 文档规范 (API Documentation)
- **同步要求**：当修改 `root/app/api/controller/` 中的接口参数时，**必须同步更新**方法头部的 API 注释（Annotations）。
- **指令执行**：每次修改注释后，必须执行 `deploy/gen_apidoc.sh`（内部调 `php think make:apidoc`）重新生成接口文档，与代码同一个 commit。
- **注释语法**：`@param` / `@return` 的写法受 `MakeApiDoc.php` 解析器约束，写错即文档错。完整规则见 `.agents/skills/Comment_Standards/SKILL.md` §3.1；`app/inner_api/` 不在生成范围内，另见 §3.2。

## 13b. API 列表接口分页响应规范 (List Pagination Response)
- **核心准则**：API 列表接口**只返回数据行数组**，严禁将 `total`、`current_page`、`per_page` 等分页元数据一并返回给前端。
- **标准写法**：接收 `page` + `limit` 两个参数，使用 `paginate()` 处理偏移，通过 `.items()` 仅取数据行：
  ```php
  $limit = (int)$this->request->param('limit', 20);
  $page  = (int)$this->request->param('page', 1);

  $list = Db::name('table')->where(...)
      ->paginate(['list_rows' => $limit, 'page' => $page])
      ->items();

  return $this->suc_json($list);
  ```
- **禁止写法**：`->paginate($limit)->toArray()` 会把元数据一起返回，不符合本项目接口规范。
- **注释同步**：`@return success` 示例必须写 `data: [...]`（纯数组），不得写 `data: { data: [...], total: ... }`。

## 14. 数据库字段命名与时间记录规范 (DB Naming & Time)
- **双重记录要求**：项目中新增的业务记录，除了保留常规的 `at`（datetime）记录外，**必须同时记录** `_ts`（int 时间戳）。
- **后缀强制约定**:
  - **`_at` 结尾**: 必须对应 `datetime` 格式（示例：`update_at`）。
  - **`_ts` 结尾**: 必须对应 `int(10)` 整型格式（示例：`update_ts`）。
- **用户关联标识 (UID)**: 
  - 在所有业务表中，关联用户（会员）的主键外键必须统一命名为 **`uid`**。

## 15. 费率与比例字段命名要求 (Rate Naming)
- **核心准则**：所有涉及费率、手续费比例、收益百分比、保证金率等表示”比例/倍率”含义的数据库字段，其名称必须包含 **`_rate`** 后缀。
- **目的**：增强可读性，在数据库结构层面清晰区分”绝对金额”字段与”计算倍率”字段。
- **强制示例**：
  - 手续费率：`buy_fee` 必须命名为 `buy_fee_rate`。
  - 保证金率：`maintenance_margin` 必须命名为 `maintenance_margin_rate`。
  - 收益率：`profit` 必须命名为 `profit_rate`。

## 16. 数值精度运算规范 (bcmath)
- **核心准则**：所有涉及金额、数量、价格、费率的加减乘除与比较，**必须使用 bcmath 函数**，严禁使用 PHP 原生运算符 `+ - * /`。
- **函数对照**：

  | 操作 | 函数 |
  |------|------|
  | 加法 | `bcadd($a, $b, $scale)` |
  | 减法 | `bcsub($a, $b, $scale)` |
  | 乘法 | `bcmul($a, $b, $scale)` |
  | 除法 | `bcdiv($a, $b, $scale)` |
  | 比较 | `bccomp($a, $b, $scale)` |

- **参数类型**：传入 bcmath 前统一用 `(string)` 转换，**严禁直接传 float**（float 转 string 可能产生科学计数法如 `1.5E-10`，导致 bcmath 报错）。
- **返回值原则**：bcmath 中间计算全程保持字符串；仅在最终写入数据库或前端展示时才转 `(float)`。
- **错误示例**：
  ```php
  $total = $price * $amount;           // 禁止
  bcadd($a, (float)$b, 8);            // 禁止
  ```
- **正确示例**：
  ```php
  use app\lib\NumberConst;

  $total = bcmul((string)$price, (string)$amount, NumberConst::SCALE);  // 正确
  bcadd($a, (string)$b, NumberConst::SCALE);                            // 正确
  ```
- **scale 常量**：`root/app/lib/NumberConst.php` 是精度的唯一权威，**禁止在业务代码里散写字面量 8 / 18**。

  | 常量 | 值 | 用在哪 |
  |------|----|--------|
  | `NumberConst::SCALE` | 8 | 落库与对外精度，对齐 `decimal(20,8)` 列；拿不准用哪个就用它 |
  | `NumberConst::SCALE_CALC` | 18 | 只用于不落库的中间量（按 U 折算币数、保证金 / 手续费中间值），结果仍要按 `SCALE` 收敛后再落库或返给前端 |

  要整站调精度只改这一个文件。全仓 931 处调用已于 2026-09-09 完成迁移，无字面量残留。

- **一律显式传 scale，不做函数封装**：`bcadd($a, $b, NumberConst::SCALE)` 这样写，
  不要为省字而包一层 `bc_add()`。钱的代码读一行就该知道精度是多少，不用跳去看封装实现。

- **科学计数法在入口收口，不在运算点防御**：JSON number 落到 PHP 是 float，绝对值小于
  `1e-4` 转字符串会变成 `1.0E-6` 这种形态，bcmath 收到直接抛 ValueError（火币深度档位踩过，
  做市机撤了不挂、委托单不成交）。

  正确做法是在**解析外部数据那一层**（交易所 REST/WS 报文、任何 float 来源）归一一次，
  之后整条链路都是规整定点字符串，运算点直接用原生 bcmath 即可。反过来给每次运算套一层
  防护罩，是把一个入口的病当成 900 多个运算的病治。

  行情侧现成的归一工具是 `MarketHelper::safeNumStr()`（`sprintf('%.10F', (float)$v)`）：

  ```php
  // 入口：解析交易所深度
  $price = MarketHelper::safeNumStr($level[0]);   // "1.0E-6" -> "0.0000010000"
  $qty   = MarketHelper::safeNumStr($level[1]);

  // 之后全链路显式运算
  $notional = bcmul($price, $qty, NumberConst::SCALE);
  ```

  注意它会过一次 `(float)` 并固定到 10 位小数，**只适合行情数值**；已经是高精度十进制字符串的
  金额（DB 的 decimal 列取出来的）不要过它，那是白白引入一次 float 往返。

  **未收口的已知缺口**：`MarketService::updateDepth()` 把交易所深度原样透出，
  `$level[0]`（价格）与 `$level[1]`（挂单量）都没归一。
  下游消费端目前是各打各的补丁，哪天动深度这块顺手把归一挪到 `updateDepth()` 入口。
