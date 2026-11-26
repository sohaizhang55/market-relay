<?php

declare(strict_types=1);

/**
 * 【行情链路唯一模板】
 *
 * 用法：
 *     cp config/market.sample.php config/market.php
 *
 * 只有一条流程：直连交易所采集 → 更新 pair 层 → 按 pair 分发给订阅方（分发协议 v3）
 *     说明   源层(market_data)按 exchange_pair.source_key 映射成 pair 层，只分发 pair：
 *            帧与 /inner_api/pair/* 都按 {symbol_code}_{type} 定位，不带交易所与 source_key
 *     进程   market:huobi / market:binance / market:bingx / market:dispatch(-t 1 / -t 2) /
 *            market:db_flush / market:price_fallback / market:kline_backfill / market:funding /
 *            market:source_select（market.source_select.enable 打开时）
 *     必改   inner_api.token —— 与订阅方同一个
 *
 * 配完之后**不要靠读这个文件来核对** —— 用命令：
 *     php think market:config_check          一屏看生效值与逐项判定
 *     php think market:config_check --env    直接给出该写进 .env 的片段
 *
 * 每项的格式：这个配置是什么 / 有哪些选项 / 默认与推荐。
 */

return [

    // ───────────────────────────────────────────────────────────────────────
    // 分发
    // ───────────────────────────────────────────────────────────────────────
    'dispatch' => [

        // ── 监听 ──

        // host —— 监听地址
        //   0.0.0.0  对外开放（绝大多数情况填这个）
        //   127.0.0.1 只本机可连
        //   默认 0.0.0.0。⚠️ 不要填公网 IP —— 云主机的公网 IP 不在本机网卡上，bind 会失败
        'host' => env('MARKET_DISPATCH_HOST', '0.0.0.0'),

        // ports —— 各类 pair 分发进程的 WebSocket 监听端口
        //   现货、合约各一个独立进程（market:dispatch -t 1 / -t 2），一个卡住或重启不影响另一类行情
        //   必填，没有默认值：没配时为 0，market:dispatch 拒绝启动、market:config_check 判 bad。
        //   装机时 deploy/install.sh 第 13 段从 deploy/ports.env 写进 .env；两个端口都要在防火墙 / allow_ips 侧放行
        'ports' => [
            \app\enum\MarketDataTypeEnum::SPOT->value => (int)env('MARKET_DISPATCH_SPOT_PORT', 0),
            \app\enum\MarketDataTypeEnum::SWAP->value => (int)env('MARKET_DISPATCH_CONTRACT_PORT', 0),
        ],

        // max_send_buffer —— 每个订阅方连接的发送缓冲上限（字节），不是全局共用一池
        //   默认 4MB
        //   ⚠️ 不要往大调 —— 缓冲满是订阅方事件循环饱和的症状，调大只会把"丢包"换成"送达即过期"
        'max_send_buffer' => (int)env('MARKET_DISPATCH_MAX_SEND_BUFFER', 4 * 1024 * 1024),

        // ── 分发行为 ──

        // trade_window —— 成交滚动窗口保留笔数
        //   默认 30。活跃交易对超出是常态，不是故障
        'trade_window' => (int)env('MARKET_DISPATCH_TRADE_WINDOW', 30),

        // tick_ms —— 分发节拍（毫秒），唯一的发送节奏
        //   默认 100。发送量 = tick 频率 × 脏字段数，与订阅方快慢无关。
        //   也是 depth 最快档：订阅方选 depth@100ms 时，tick 比 100 慢就兑现不了
        'tick_ms' => (int)env('MARKET_DISPATCH_TICK_MS', 100),

        // lag_close_sec —— 订阅方持续积压多久就断开（秒）
        //   默认 5，无条件生效。断开在覆盖语义下是廉价止损：重连由快照重放补全量
        'lag_close_sec' => (int)env('MARKET_DISPATCH_LAG_CLOSE_SEC', 5),

        // shadow_dirty —— 采集侧多写一份影子脏集合，给并行试跑的 Go 分发机（market-relay/go）用
        //   默认 false。只在 Go 分发并行比对期间打开；改了要重启采集进程才生效
        //   打开后 PHP 分发照常工作，两边各取各的脏集合，互不影响
        'shadow_dirty' => (bool)env('MARKET_DISPATCH_SHADOW_DIRTY', false),

    ],

    // ───────────────────────────────────────────────────────────────────────
    // 内部接口鉴权（开放给订阅方，禁止公网直接访问）
    // ───────────────────────────────────────────────────────────────────────
    'inner_api' => [

        // token —— 共享密钥，订阅方请求 /inner_api/* 时带在 X-Inner-Token
        //   ⚠️ 必改。与订阅方必须填同一个；留空或用仓库默认值 /inner_api/* 一律 403
        'token' => env('MARKET_INNER_API_TOKEN', ''),

        // allow_ips —— 允许访问的来源 IP，逗号分隔
        //   留空 = 不限制来源 IP。同时作用于 /inner_api/* 与 PHP 的两个分发端口（现货 8594、合约 8595）；
        //   Go 分发机不读它，读 go/.env 的 DISPATCH_ALLOW_IPS。订阅方 IP 常变时留空，靠「订阅方管理」的 token 鉴权
        'allow_ips' => env('MARKET_INNER_API_ALLOW_IPS', ''),
    ],

    // ───────────────────────────────────────────────────────────────────────
    // 价格 REST 兜底（由 market:price_fallback 执行）
    // ───────────────────────────────────────────────────────────────────────
    'rest_fallback' => [

        // enable —— WS 静默时用 REST 补一次当前价
        //   默认 true。关掉则 WS 断流期间价格完全停更
        'enable' => (bool)env('MARKET_REST_FALLBACK_ENABLE', true),

        // lead_seconds —— 兜底相对 stale_seconds 的**提前量**（秒），不是绝对阈值
        //   真实公式：兜底触发阈值 = max(1, stale_seconds - lead_seconds)
        //   默认 0，即兜底与告警**同一个阈值**（stale_seconds，300 秒）。
        //   全链路的陈旧判据只有 stale_seconds 这一个数，是刻意的：判据一分叉，
        //   「告警说过期了」和「兜底认为该修了」就会各说各话，排查时先得搞清楚在看哪个阈值。
        //   代价是兜底不再抢跑，从过期到修复要多等一个扫描周期（10 秒一轮）。
        //   >0 = 让兜底提前动手（例如 285 即静默 15 秒就打 REST）。⚠️ 只有在确认交易所
        //      限流配额扛得住的前提下才调，并且要同步看 stale_seconds：两者之差 ≤5 秒会让
        //      兜底每轮都认为所有源都过期、持续打交易所 REST。
        //      market:config_check 会把算出来的阈值直接打出来。
        'lead_seconds' => (int)env('MARKET_REST_FALLBACK_LEAD_SECONDS', 0),

        // fail_cooldown —— REST 取数失败后对该 host 的冷却（秒）
        //   默认 60。防止交易所限流时越试越糟
        'fail_cooldown' => (int)env('MARKET_REST_FAIL_COOLDOWN', 60),

        // max_fixes_per_tick —— market:price_fallback 每轮最多补几个过期价格
        //   默认 4（每个交易所进程各自 4 个）
        'max_fixes_per_tick' => (int)env('MARKET_REST_FALLBACK_MAX_FIXES_PER_TICK', 4),

        // tick_budget_sec —— 单轮墙钟预算（秒），超预算就停手、下一轮继续
        //   默认 3。没配时沿用 kline_backfill.tick_budget_sec（拆分前两者共用这一个数）
        'tick_budget_sec' => (int)env('MARKET_REST_FALLBACK_TICK_BUDGET_SEC', 3),

        // exchanges —— 按交易所覆盖上面的 enable / lead_seconds / max_fixes_per_tick / tick_budget_sec（fail_cooldown 不在此列）
        //   键是 MarketExchangeEnum 的值，只写想改的项，没写的沿用上面的全局值。默认 [] 即各家一致
        //   例：'bingx' => ['max_fixes_per_tick' => 2],
        'exchanges' => [],
    ],

    // ───────────────────────────────────────────────────────────────────────
    // K 线补数（由 market:kline_backfill 执行）：保证每个周期最近 kline_retention 根不缺，见下面的 kline_retention
    // ───────────────────────────────────────────────────────────────────────
    'kline_backfill' => [

        // enable —— 总开关
        //   默认 true。关掉则尾部、窗口里的缺口、pair 层重放都没人补
        'enable' => (bool)env('MARKET_KLINE_BACKFILL_ENABLE', true),

        // scan_interval —— 扫描周期（秒）
        //   默认 30
        'scan_interval' => (int)env('MARKET_KLINE_BACKFILL_SCAN_INTERVAL', 30),

        // gap_periods —— 落后几个周期算缺口
        //   默认 2。太小会把正常抖动当缺口
        'gap_periods' => (int)env('MARKET_KLINE_BACKFILL_GAP_PERIODS', 2),

        // max_fixes_per_tick —— 每轮最多修几个缺口
        //   默认 20。取数是同步阻塞的，配额与下面的墙钟预算共同兜底。
        //   这个数要跟着源数走：轮完一圈 = source_key 数 x 周期数 ÷ 本值 x scan_interval。
        //   2026-08 扩到 151 个 source_key x 6 个周期 = 906 条序列，配 5 要 1.5 小时才轮一圈，
        //   真实缺口最坏得等这么久；配 20 名义上回到 23 分钟 —— 但实际由 tick_budget_sec 卡着，见下
        'max_fixes_per_tick' => (int)env('MARKET_KLINE_BACKFILL_MAX_FIXES_PER_TICK', 20),

        // tick_budget_sec —— 单轮墙钟预算（秒）
        //   默认 3。超预算就停手，下一轮继续 —— 保证不会长时间占住事件循环。
        //
        //   与上面几个配额是「取小者生效」：单次缺口修复要打一次同步 REST，3 秒能修几个取决于
        //   交易所当时有多快，配额给到 20 也未必摸得到。**不要靠算式猜**，看日志：
        //   撞预算收工时 market_kline_backfill 会记一条 warning（限流 10 分钟一条），
        //   里面有本轮实际修了几个 —— 拿那个数去反推真实轮转周期，再决定要不要调本值。
        //
        //   market:kline_backfill 是独立进程，调大只会让本进程单轮跑更久，不会推后价格兜底
        //   （那是 market:price_fallback，读 rest_fallback.tick_budget_sec）。market:funding 也不读本项
        'tick_budget_sec' => (int)env('MARKET_KLINE_BACKFILL_TICK_BUDGET_SEC', 3),

        // exchanges —— 按交易所覆盖上面任意一项（market:kline_backfill 每家一个进程，各读各的）
        //   键是 MarketExchangeEnum 的值，只写想改的项，没写的沿用上面的全局值。默认 [] 即各家一致
        //   上面「轮完一圈」的算式此时按单家的 source_key 数算
        //   例：'bingx' => ['max_fixes_per_tick' => 5, 'scan_interval' => 60],
        'exchanges' => [],
    ],

    // ───────────────────────────────────────────────────────────────────────
    // 行情新鲜度判定
    // ───────────────────────────────────────────────────────────────────────

    // stale_seconds —— 多久没更新算陈旧（秒）
    //   默认 300。**几处共用**这一个阈值，不只是监控页：
    //     · 监控页 / 首页 / market_pipeline / market:data_check 的陈旧判定
    //     · 价格过期告警（market:price_check_expire）与休市巡检（market:session_check）
    //     · REST 兜底的触发阈值（rest_fallback.lead_seconds 默认 0，两者相等）
    //   放宽到 300 是业务取舍：有些源推送偏慢但数据正确，只要还在推就认它有效。
    //   ⚠️ 这是全链路唯一的陈旧判据，改它等于同时改上面这几处的行为。
    //      只有把 rest_fallback.lead_seconds 调成非 0 时，兜底才会用一个更早的阈值
    'stale_seconds' => (int)env('MARKET_STALE_SECONDS', 300),

    // ───────────────────────────────────────────────────────────────────────
    // 主备切换（market:source_select）
    //   断流判据是每个源自己的 market_data.ticker_stale_sec / depth_stale_sec（后台「原始行情」页可改），
    //   不在这里；绑定与优先级在后台「交易对 → 数据源」。规则见 app\lib\SourceSelector
    // ───────────────────────────────────────────────────────────────────────
    'source_select' => [

        // enable —— 是否启用选源（自动切换生效源）。合法值 true | false，默认 false
        //   false  market:source_select 拒绝启动，交易对一直用当前源（后台仍可手动切换）
        //   true   断流就切走、主源稳定后切回
        //   ⚠️ 打开前先在后台「原始行情」页调好各源的断流秒数：默认 ticker 30 / 深度 15 秒对火币偏紧
        //      （火币价格帧约每 60 秒才推一次、冷门币深度帧可能几十秒一次），没调好会频繁误切
        'enable' => (bool)env('MARKET_SOURCE_SELECT_ENABLE', false),

        // recover_sec —— 优先级更高的源恢复后，要连续健康多少秒才切回
        //   默认 300。切走是立即的，切回要等：主源时好时坏时不至于来回切，每切一次价格都会按两家报价差跳一下
        'recover_sec' => (int)env('MARKET_SOURCE_SELECT_RECOVER_SEC', 300),

        // flap_window_sec / flap_max —— 窗口内切走达到 flap_max 次就锁定在当前源、不再自动切换，等人工解锁
        //   默认 3600 秒内 3 次。解锁在后台「交易对」页
        'flap_window_sec' => (int)env('MARKET_SOURCE_SELECT_FLAP_WINDOW_SEC', 3600),
        'flap_max'        => (int)env('MARKET_SOURCE_SELECT_FLAP_MAX', 3),

        // notify —— 切换、全部不健康、锁定时是否发站内通知与 Telegram。合法值 true | false，默认 true
        //   false 时只写业务日志 market_source_select
        'notify' => (bool)env('MARKET_SOURCE_SELECT_NOTIFY', true),
    ],

    // ───────────────────────────────────────────────────────────────────────
    // 调试：只处理这些 source_key（逗号分隔，留空 = 全部）
    // ───────────────────────────────────────────────────────────────────────
    'debug_source_keys' => array_filter(array_map(
        fn($s) => strtolower(trim($s)),
        explode(',', (string)env('MARKET_DEBUG_SOURCE_KEYS', ''))
    )),

    // ───────────────────────────────────────────────────────────────────────
    // 交易所地址
    // ───────────────────────────────────────────────────────────────────────
    'endpoints' => [
        'binance' => [
            'ws' => [
                'spot'        => env('MARKET_BINANCE_WS_SPOT', 'ws://stream.binance.com:9443/stream'),
                'swap_public' => env('MARKET_BINANCE_WS_SWAP_PUBLIC', 'ws://fstream.binance.com/public/ws'),
                'swap_market' => env('MARKET_BINANCE_WS_SWAP_MARKET', 'ws://fstream.binance.com/market/ws'),
            ],
            'rest' => [
                'spot' => env('MARKET_BINANCE_REST_SPOT', 'https://api.binance.com'),
                'swap' => env('MARKET_BINANCE_REST_SWAP', 'https://fapi.binance.com'),
            ],
        ],
        'huobi' => [
            'ws' => [
                'spot'       => env('MARKET_HUOBI_WS_SPOT', 'ws://api.huobi.pro/ws'),
                'swap'       => env('MARKET_HUOBI_WS_SWAP', 'ws://api.hbdm.com/linear-swap-ws'),
                'mark_price' => env('MARKET_HUOBI_WS_MARK_PRICE', 'ws://api.hbdm.com/ws_index'),
            ],
            'rest' => [
                'spot' => env('MARKET_HUOBI_REST_SPOT', 'https://api.huobi.pro'),
                'swap' => env('MARKET_HUOBI_REST_SWAP', 'https://api.hbdm.com'),
            ],
        ],
        'bingx' => [
            // 只用 USDT 永续（外汇、燃料油等 NC 前缀 TradFi 合约）。WS 下行帧 gzip，心跳为纯文本 Ping / Pong
            'ws'   => env('MARKET_BINGX_WS', 'ws://open-api-swap.bingx.com:443/swap-market'),
            'rest' => env('MARKET_BINGX_REST', 'https://open-api.bingx.com'),
        ],
    ],

    // ───────────────────────────────────────────────────────────────────────
    // K 线保留量：每个周期保留最近多少根，两层所有周期都一样（超出由 market:kline_clean 每日清理）
    //
    // 同时也是 market:kline_backfill 补数的窗口：最近这么多根里缺的都会去补，更早的不补。
    // 值的语义是"保留最近 N 个周期的时长"，不是精确根数 —— 清理按时间切
    // (cleanKlineByCutoff 删掉早于 now - period × N 的行)，中间有空洞时实际根数会少于 N。
    // 2000 根的实际覆盖：1min 约 33 小时 · 5min 约 7 天 · 15min 约 21 天 · 1h 约 83 天 · 4h 约 333 天 · 1day 约 5.5 年
    //
    // 写一个整数就是所有周期统一；也认 [周期 => 根数] 的数组，见 MarketHelper::klineKeep()。
    //
    // ⚠️ 火币日线最多约 333 根：火币日线由 4 小时线汇总（火币日线按北京时间切，不能直接用，
    //   见 MarketHelper::klinePlan()），而 4 小时线同样只留 2000 根，2000 × 4 小时 ≈ 333 天。
    //   现货、合约都一样，是有意的取舍，不为它多留 4 小时线。
    // 本项没有对应的 env：config/market.php 本身就是每台机器手工维护的文件（在 .gitignore 里），
    // 再套一层环境变量只是把同一件事拆到两个地方
    // ───────────────────────────────────────────────────────────────────────
    'kline_retention' => [
        'source_kline_keep' => 2000,
        'pair_kline_keep'   => 2000,
    ],
];
