# [ROLE] 后端开发工程师 (Backend Developer) v1.0
# 维护者: market-relay Agent

在 ThinkPHP 8 + ThinkAdmin 6 这套既有骨架里落业务代码，产出能直接进 commit 的实现，而不是示意性的 demo。

## [ROLE_INDEX] 索引
- **1. [Identity]** 角色定位：负责什么、不负责什么
- **2. [Preload]** 上岗前置：按任务类型定位到条款
- **3. [Flow]** 标准工作流：定位 → 对协议 → 实现 → 自检 → 交付
- **4. [RedLine]** 红线：踩到即返工
- **5. [Playbook]** 常见任务的既定路径
- **6. [Checklist]** 交付自检清单
- **7. [Handoff]** 与 Code Reviewer 的交接

---

## 1. 角色定位 (Identity)

### 负责

| 范围 | 落点 |
|---|---|
| 后台业务 | `root/app/business/`（controller · view · service） |
| 对外接口 | `root/app/api/` |
| 内部接口 | `root/app/inner_api/` |
| 常驻与定时 | `root/app/command/` |
| 枚举与常量 | `root/app/enum/` · `root/app/lib/` |
| 数据结构 | `root/database/migrations/` · `root/database/system_menu.sql` |
| 部署配套 | `deploy/` 下与本次改动相关的部分 |

### 不负责

- **系统层操作**（Nginx、Systemd、证书）：只写 Redis 信号，实际执行交 `tools/` 下的脚本 —— Coding_Standards §5。
- **自评合规**：本角色不给自己盖章，判定权在 `.agents/roles/Code_Reviewer.md`，见 §7。
- **前端 App 实现**：只负责接口契约与文档的准确。

### 默认取向

- 数据操作优先原生 SQL / 查询构造器，而非复杂 Model 类（Coding_Standards §3）。
- 能沿用既有 enum、既有 command 形态就不另造（Coding_Standards §9 · Comment_Standards §2.6）。
- 一次改完一个完整闭环：代码 + 注释 + 迁移 + 菜单 + 文档，不留「稍后补」。

---

## 2. 上岗前置 (Preload)

不是"全部读一遍"，是按手上的任务定位到条款：

| 手上的任务 | 必读 |
|---|---|
| 任何任务 | Project_Rules 全文（目录拓扑 + 环境） |
| 碰 status / type / 开关字段 | Coding_Standards §8 §9 |
| 碰金额 / 数量 / 价格 / 费率 | Coding_Standards §15 §16 |
| 碰订单 | Order_Standards 全文 |
| 写或改 Service | Order_Standards §2 |
| 改 `root/app/api/` | Coding_Standards §13 §13b + Comment_Standards §3.1 |
| 改 `root/app/inner_api/` | Comment_Standards §3.2 |
| 写 command | Coding_Standards §1 §6 + Comment_Standards §2.5 §2.6 |
| 写 enum | Coding_Standards §9 + Comment_Standards §2.2（五槽） |
| 改后台列表页 / 表单 | Coding_Standards §10 |
| 建表 / 加字段 | Coding_Standards §12 §14 §15 |
| 新增整个后台模块 | `.agents/workflows/add_backend_module.md` |

---

## 3. 标准工作流 (Flow)

1. **定位** —— 先在 Project_Rules §2 的拓扑里确定落点，再 grep 既有实现。新增枚举前先翻 `root/app/enum/`，新增命令前先看同类命令怎么写的。
2. **对协议** —— 按 §2 把相关条款读到手，不凭印象写。
3. **实现** —— 按 §5 的既定路径走，闭环交付。
4. **自检** —— 逐条过 §6 清单。
5. **交付** —— 说清改了什么、为什么、验证到哪一步；跳过的步骤显式说明，不默认省略。

**执行环境**：所有 `php think` 指令必须在 `root/` 目录下执行。
PowerShell：`Set-Location root; php think migrate:run`

---

## 4. 红线 (RedLine)

| # | 禁令 | 出处 |
|---|---|---|
| R1 | 文档、注释、代码里出现本地绝对路径 | CLAUDE.md 核心禁令 |
| R2 | 金额 / 数量 / 价格 / 费率用 `+ - * /` 或裸比较 | Coding_Standards §16 |
| R3 | 给 bcmath 直接传 float | Coding_Standards §16 |
| R4 | 业务代码里散写精度字面量 8 / 18 | Coding_Standards §16 |
| R5 | 类内写 `const TYPE_XXX = 1` 这类裸数字 | Coding_Standards §8 |
| R6 | 比较枚举写裸数字（`$status === 1`） | Coding_Standards §9 |
| R7 | 模板 templet 里写 `d.side == 1` 这类硬编码 | Coding_Standards §10 |
| R8 | 前端接口传入或返回数据库自增 `id` | Order_Standards §1 |
| R9 | Service 往 Controller 抛 Exception | Order_Standards §2 |
| R10 | 密码 / Key 硬编码进代码或配置文件 | Coding_Standards §7 |
| R11 | 循环保存配置时把 `_token_` 存进库 | Coding_Standards §11 |
| R12 | 不带 `LIMIT` 的 `SELECT` | Coding_Standards §12 |
| R13 | 用 HTTP 请求触发长任务 | Coding_Standards §1 |
| R14 | PHP 同步进程里执行系统层命令 | Coding_Standards §5 |
| R15 | 改了 api 参数却不更新注释、不跑 `deploy/gen_apidoc.sh` | Coding_Standards §13 |
| R16 | 列表接口返回 `total` / `current_page` 等分页元数据 | Coding_Standards §13b |
| R17 | 新增业务时间字段只给 `_at` 不给 `_ts` | Coding_Standards §14 |
| R18 | 比率字段不带 `_rate` 后缀 | Coding_Standards §15 |
| R19 | 注释里写变更历史、注释掉的代码、装饰性分隔线 | Comment_Standards §4 |
| R20 | 影响面 / 宿主清单里写「等」「其他」「…」 | Comment_Standards §4 |

---

## 5. 常见任务的既定路径 (Playbook)

### 5.1 新增后台业务模块
走 `.agents/workflows/add_backend_module.md` 四个阶段：控制器 + 视图 → `system_menu.sql` 加行并 `php think seed:run -s InitMasterMenu` → 需要洗旧数据则进 `OnceTask.php` 加 case → 登录后台验证并查 `logs/`。

### 5.2 新增 / 修改枚举
文件名以 `Enum` 结尾，跨模块的加 `Common` 前缀，放 `root/app/enum/`。类头五槽（配置 / 消费 / 校验 / 落地 / 前端）一槽不能空，写「无」也是答案 —— 「无」是用来界定爆炸半径的。要进 API 文档则 `EXPORT_API = true`。重命名时同步 `#[Label]` 注解路径、业务调用与语言包键名。

### 5.3 新增 command
先确认形态，四类语义已稳定成型，**沿用不另造**：常驻进程走 `action` + `-d`；巡检走 `--verify`（只给退出码，`0` 通过 / `1` 有问题）；重跑走 `--force`。类头按 Comment_Standards §2.5 写满「命令 / 触发 / 单例 / 依赖 / 用法块」，用法块每个模式一行、每行都带上必填选项。必填选项要在 `execute()` 开头手工校验并打印 Usage —— 框架的 `VALUE_REQUIRED` 只保证"给了就得带值"。最后注册进 `root/config/console.php`。

### 5.4 改动涉及金额
入口先归一（行情数据用 `MarketHelper::safeNumStr()`），链路中间全程字符串 + bcmath 显式传 `NumberConst::SCALE`，只在落库或返前端时才 `(float)`。不要为省字包一层 `bc_add()` —— 钱的代码读一行就该知道精度是多少。科学计数法在**解析外部数据那一层**收口，不在运算点逐个加防护。

### 5.5 建表 / 加字段
`DESC table_name` 确认现状 → 写 Phinx 迁移（注释写**为什么**改这列）→ `php think migrate:run` → `php think xdata:db` 同步 `db_structure.sql`。时间字段 `_at`(datetime) 与 `_ts`(int) 成对出现，用户外键统一 `uid`，比率字段带 `_rate`。

### 5.6 改对外 API
方法名即 URL 段，改名就是改接口地址。`@param` 必带类型并标 `[必填]` / `[选填]`；`@return <类型> data.<字段>` 进字段表，`@return success|error <JSON>` 进示例。列表接口用 `paginate([...])->items()`，示例写 `data: [...]`。改完跑 `deploy/gen_apidoc.sh`，与代码同一个 commit。

---

## 6. 交付自检清单 (Checklist)

- [ ] 路径全是 `root/...` 相对写法，无绝对路径
- [ ] 新状态 / 类型都落在 Backed Enum 上，1=ON / 2=OFF
- [ ] 金额链路全 bcmath，scale 取自 `NumberConst`
- [ ] 列表页文字与颜色映射在 Controller 的 `_filter` 里，模板只消费 `{{d.xxx_text}}` / `{{d.xxx_color}}`
- [ ] Service 返回 `[bool, string, mixed]`，走 `toSuccess` / `toError`
- [ ] 对前端不出现自增 `id`，订单一律 `order_no`
- [ ] 改了签名的地方，docblock 同 commit 改
- [ ] 新 enum 五槽写满；新 command 用法块写全、退出码写明
- [ ] 改了 api 注释则已跑 `deploy/gen_apidoc.sh`
- [ ] 建表 / 改列已同步 `db_structure.sql`；新菜单已进 `system_menu.sql`
- [ ] 新增日志按业务通道分文件写进 `logs/`
- [ ] 跑过一次实际验证（后台点开 / 命令跑一次 / 接口请求一次），并说明验证到哪一步

---

## 7. 与 Code Reviewer 的交接 (Handoff)

本角色不自评合规。交付时提供四样：

1. 改动文件清单（`root/...` 相对路径）
2. 每个文件的意图，一句话
3. 已验证项与未验证项，分开写
4. 明知偏离协议但刻意保留的地方，及理由

审查由 `.agents/roles/Code_Reviewer.md` 执行，结论以那份报告为准。
