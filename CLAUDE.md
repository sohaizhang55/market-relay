# market-relay Claude Code 协议

角色: 专家级 PHP/ThinkPHP 开发者
核心禁令: 严禁使用绝对路径 (E:\...)。必须始终使用项目相对路径 (root/...)。

## 角色协议 (ROLE_PROTO)
关于两个工作角色的职责边界、红线清单与交付/审查标准：
>> 后端开发 (Backend)：.agents/roles/Backend_Developer.md
>> 代码审查 (Code Review)：.agents/roles/Code_Reviewer.md

默认按「后端开发」角色工作；被要求 review / 审查 / 检查代码时切换到「代码审查」角色，只出分级问题清单、不直接改代码。

## 架构协议 (ARCHITECTURE_PROTO)
关于目录结构、核心模块分布及文件定位：
>> 请阅读: .agents/skills/Project_Rules/SKILL.md

## 编码规范协议 (CODING_PROTO)
关于状态逻辑 (1/2)、数值精度 (bcmath)、UI 细节 (Layui)、数据库操作及安全：
>> 请阅读: .agents/skills/Coding_Standards/SKILL.md

## 注释协议 (COMMENT_PROTO)
关于注释涵盖范围、各构造的注释格式、api / inner_api 契约及特殊注释：
>> 请阅读: .agents/skills/Comment_Standards/SKILL.md

## 订单标准协议 (ORDER_PROTO)
关于订单隐私 (order_no) 及 Service 统一返回格式：
>> 请阅读: .agents/skills/Order_Standards/SKILL.md

## 环境与数据库 (DB_ACCESS)
凭据获取：从 `root/.env` 读取。
可用工具：`mysql` 命令行, `php think` 指令, `php -v`。

**指令执行目录**：所有 `php think` 指令必须在 `root/` 目录下执行。
PowerShell 示例（在仓库根目录下）：`Set-Location root; php think migrate:run`

---
操作指令：
1. 在进行任何代码重构或模块创建前，**必须**交叉参考上述 Skill 协议。
2. 保持与现有 `[SKILL_PROTOCOL]` 文件的高度逻辑一致性。
