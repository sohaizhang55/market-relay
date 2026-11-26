# ThinkAdmin 全局运维审计技能 (AuditOps)

本技能定义了如何利用 ThinkPHP 中间件机制，实现对 ThinkAdmin 业务后台所有 POST 请求的非侵入式自动审计。

## 技术实现

1. **全局中间件**：在 `business` 模块注册 `OperationLog` 中间件，仅过滤并拦截 `POST` 请求。
2. **智能标题提取**：利用 PHP 反射机制（`ReflectionMethod`）提取控制器方法的首行文档注释（DocComment）作为操作标题。
3. **分层存储**：调用框架自带的 `sysoplog()` 函数进行持久化，配合数据库扩容确保日志完整。

## 关键代码位置

- **中间件定义**: [root/app/business/middleware/OperationLog.php](file:///root/app/business/middleware/OperationLog.php)
- **中间件注册**: [root/app/business/middleware.php](file:///root/app/business/middleware.php)
- **数据库迁移**: [root/database/migrations/20260410062523_update_system_oplog_content_to_text.php](file:///root/database/migrations/20260410062523_update_system_oplog_content_to_text.php)

## 最佳实践与注意事项

- **性能优化**: 审计仅针对 `POST` 请求，避免过大开销。
- **数据完整性**: 必须通过迁移将 `system_oplog.content` 字段从 `VARCHAR(1024)` 提升至 `TEXT` (或 `LONGTEXT`)。
- **安全性**: 若涉及敏感参数（如密码），应在中间件记录前增加脱敏逻辑。
