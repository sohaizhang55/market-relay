# 成交合并写入 与 币安 aggTrade

## 1. 问题

采集进程每收到一帧成交就调一次 `MarketService::updateTrade()`，每次都要：

- 原始层：Redis GET → JSON 解码 → 合并 → 编码 → SET（窗口 30 笔）
- 对映射到该 source_key 的**每个** pair：GET 整个成交窗口 → 去重 → SET → 写一次 pair 快照

开销 = 成交帧数 × pair 数 × 整窗读写，全部压在 Workerman 单线程事件循环上。
币安 `@trade` 是逐笔推送，BTC 合约高峰每秒数百到上千帧，事件循环会被占满，
连带同一进程里的 ticker / depth 一起延迟（「高峰挤爆」）。

而分发机每个 `dispatch.tick_ms` 只取一次 pair 快照：同一个 tick 内写的多次快照，
下游只拿得到最后一次，其余全是白写。

成交流唯一的消费方是「最近成交」列表（`getTradesById()`），不做 VWAP、不进结算、不参与定价
（见 `root/app/command/MarketCollectorAbstract.php` 中 trade_window_overflow 处的说明）。

## 2. 方案

### 2.1 合并写入（三家交易所都生效）

- `handleTrade()` 收帧时只做 `touchCollect` + 追加进内存缓冲 `tradeBuffer[source_key]`
- 每 `MarketCollectorAbstract::TRADE_FLUSH_MS`（100）毫秒由 `flushTrades()` 把每个 source_key 攒下的成交
  **一次**交给 `updateTrade()`（列表形态，三家的分支都认），之后 `touchLand`
- 写入次数上限 = source_key 数 × (1000 / TRADE_FLUSH_MS)，与行情剧烈程度无关

关键约束：

- **按到达顺序追加、整批交给 updateTrade**：updateTrade 对列表逐笔 `array_unshift`，
  拼接多帧与逐帧顺序处理的结果相同（成交 id 递增、只有紧邻重放时，窗口与新增笔数完全一致）。
  唯一差异：已被挤出窗口的旧成交又被重放时，逐帧会把它当新成交插回窗口顶部，合并一批则识别为重复 ——
  合并的结果才是对的
- **不预先截到 trade_window**：`MarketPublisher::countTradeOverflow()` 靠本次到达笔数统计真实丢包，
  截掉会让 trade_window_overflow 失真。只设一个远大于常态的上限 `TRADE_BUFFER_MAX` 防内存失控，
  超出时丢最旧的 —— 这种情况下溢出指标本身已经远大于 0
- `touchLand` 推迟到 updateTrade 之后，满足 `MarketMetrics::touchGroup()` 不变量 ②
- 刷新定时器用 `timedHandler('trade_flush', ...)` 包：计入事件循环占用分桶、带任务存活、兜异常
- 2026-11-12 起写死为 `TRADE_FLUSH_MS = 100`，原配置 `market.collect.trade_flush_ms` 与「0 = 逐帧写入」的回滚路径已删除；
  要回滚只能改代码

刻意不做：停机时补写缓冲里的成交。最多丢一个刷新间隔（默认 100ms）的成交，只影响「最近成交」列表；
`onWorkerStop` 已被 `CommandHeartBeatService` 占用，为这点收益包装它不值得。

### 2.2 币安改订 aggTrade

- 聚合成交：同一吃单、同价、同时刻的多笔成交合并成一条，高峰期帧数明显少于 `@trade`
- 字段 `{a, p, q, f, l, T, m}`，在采集入口归一成 `@trade` 的形状 `{t, p, q, T, m}`（t 取聚合成交 id `a`），
  下游 `updateTrade()` 只认一种形状
- 期货端点：官方文档 aggTrade 属于 `/market`。`@trade` 曾出现「文档写 /market、实际只在 /public 推送」，
  aggTrade 尚未在线上实测，所以：
  - 走 `swap_market` 上**单独一条连接**（Swap-Trade），不与驱动定价的 ticker/markPrice 连接合用，
    出问题不会牵连价格
  - 2026-11-12 起固定订阅 aggTrade：原配置 `market.collect.binance_trade_stream` 与订阅 `@trade` 的代码路径已删除，
    切回逐笔成交只能改代码（`@trade` 必须挂 `swap_public`，见 MarketBinance 类注释）
- 切换时 Redis 成交窗口里短暂混有两种 id（t 与 a），新成交几秒内就会把旧的挤出窗口，不需要清理

## 3. 上线验证

1. 先在服务器上确认 aggTrade 在 `/market` 有推送（本地网络无法访问币安，未能实测）
2. 发布后看 `runtime/log/{日期}_market_collect_stat.log`：
   - `busy=` 中 `msg:*` 分桶下降，新增 `trade_flush` 分桶
   - `trade_overflow` 不应异常增大
3. 后台「原始行情」成交列持续更新；`market:data_check` 的 trade 列不陈旧
4. 若 aggTrade 无推送：已没有配置开关可切，要改 MarketBinance 把期货成交改订 `@trade` 并挂到 `swap_public`
