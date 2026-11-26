# ThinkAdmin UI 手术式修复技能 (UIFix)

本技能定义了在不修改框架核心 `admin.js` 文件（从而避免编辑器自动格式化导致的代码冲突）的前提下，如何利用全局钩子自定义框架 UI 行为。

## 解决的核心问题

- **编辑器冲突**：本地编辑器开启“保存时自动格式化”时，修改 `admin.js` 会导致全文件缩进/空格变更。
- **动作拦截**：需要禁用或修改框架预设的动作（如禁用图片选择器弹窗）。

## 技术实现方案

采用 **“外部 JS 补丁 + 表单生命周期钩子”** 策略：

1. **注入入口**：在布局文件（`index.html`, `full.html`）中，确保在 `admin.js` 之后加载 `extra/script.js`。
2. **事件钩子**：利用 `admin.js` 抛出的全局 `reInit` 事件。
   ```javascript
   $(document).on('reInit', function(e, $dom) {
       // 在此针对 $dom 范围内的元素进行属性修正
   });
   ```
3. **强制纠偏**：通过修改 `data-file` 等关键属性，重定向点击事件的处理流（例如从弹窗改为直接上传）。

## 关键代码位置

- **自定义脚本**: [root/public/static/extra/script.js](file:///root/public/static/extra/script.js)
- **全局布局**: [root/app/admin/view/index/index.html](file:///root/app/admin/view/index/index.html)
- **全屏布局**: [root/app/admin/view/full.html](file:///root/app/admin/view/full.html)

## 最佳实践与注意事项

- **兼容性**: 建议使用 ES5 语法（`var`, `function`）以确保在所有浏览器环境中稳定运行。
- **防止闪烁**: 在 `reInit` 注册后立即执行一次全局 `patch()`，并配合小延时（100ms）处理异步渲染。
