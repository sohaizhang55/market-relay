<?php

declare(strict_types=1);

namespace app\lib;


/**
 * 行情链路观测指标（Redis 层）
 *
 * 三类指标，分别回答三个不同的问题：
 *   group  —— 每个数据组 (channel + source_key) 最后一次更新是什么时候、这一分钟更新了几次/多少字节。
 *             回答「某个交易对的某类数据是不是断了」。覆盖式 HASH。
 *             ⚠️ 这里原来写的是「字段数天然有界(约165)，不会涨」—— **这个前提是错的**。
 *             有界的是「**当前**在跑的数据组数」，而 HASH 里累积的是「**历史上曾经**出现过的
 *             数据组数」：交易对下架、source_key 改名、配置调整之后，旧 field 没有任何人会删，
 *             带着一个几天前的 last_ts 永远留在里面。现网就是这么来的一条
 *             `BINANCE_trxusdt_1` 显示「339622 秒未更新」（约 3.9 天）—— 它不是坏了，是早就不在跑了。
 *             修法见 pruneStale()。
 *   worker —— 每个常驻进程的吞吐与背压现场：分发速度、丢包数、各订阅方 sendBuffer 字节数。
 *             回答「分发机现在忙不忙、有没有人跟不上」。每个进程一个 HASH，覆盖写。
 *   event  —— 离散事件流水（缓冲满/断连/重连），带毫秒时间戳，LTRIM 定长。
 *             回答「最近30秒有没有报过 send buffer full」这类带时间窗口的问题。
 *             订阅方连接级事件（降级 / 缓冲满 / 断开）另存一份分发事件流水，见 DISPATCH_EVENT_KEY。
 *
 * ⚠️ 为什么 group 指标要先在进程内累加、再由定时器批量刷：
 * 采集/分发是每秒上百帧的热路径，逐帧写一次 Redis 等于把观测本身变成瓶颈
 * (这正是本次要修的 business_log 逐帧同步落盘的同类错误)。
 * touchGroup() 只做内存里的数组自增，flushGroups() 由各进程已有的 stat Timer 调用，
 * 一次 hMSet 批量写完。
 *
 * 本类只负责写入与读取，不负责展示；后台监控页 (Phase 7) 直接读这几个 key。
 */
final class MarketMetrics
{
    /**
     * 数据组新鲜度与速率：HASH，key = 本前缀 + scope，field = "{channel}|{source_key}"
     *
     * scope 把同一个数据组在链路上三个位置的速率分开记，这是定位「数据在哪一跳停了」的关键：
     *   collect  采集器从交易所收到的原始频率（未经发布节流）
     *   dispatch 分发机实际发出去的频率（节流后，正常比 collect 低一个数量级）
     *   land     订阅方收到并落库的频率
     * 三者对不上就能直接指出断点：collect 有 dispatch 无 = 采集器没发布；
     * dispatch 有 land 无 = 分发链路丢了。
     */
    public const GROUP_PREFIX = 'market:metrics:group:';

    public const SCOPE_COLLECT  = 'collect';
    public const SCOPE_DISPATCH = 'dispatch';
    public const SCOPE_LAND     = 'land';

    /**
     * REST 兜底刷新。**必须与 collect 分开记**。
     *
     * 为什么单独开一个 scope：`market_data.update_ts` 既是"这一路是否陈旧"的判据，
     * 又是 REST 兜底修复要刷新的**目标** —— 判据与修复动作共用同一个字段，
     * 于是"WS 已经断了、全靠 REST 每 15 秒续一次命"这个状态在监控页上
     * 与"一切正常"长得一模一样。而它恰恰是最该被看见的：兜底只有当前价，
     * 没有 OHLC、没有深度、没有成交，频率也低一到两个数量级。
     *
     * 分开记之后，判据就变成「谁在刷新它」而不是「它新不新鲜」。
     */
    public const SCOPE_FALLBACK = 'fallback';

    /**
     * 全部 scope 的唯一清单。
     *
     * pruneStale() 原本把三个 scope 手抄了一遍，于是新增一个 scope 就会永远不被清理、
     * 无声地长下去 —— 「加了东西没接上配套」正是本轮要防的那一类。清单只留一份。
     */
    public const SCOPES = [self::SCOPE_COLLECT, self::SCOPE_DISPATCH, self::SCOPE_LAND, self::SCOPE_FALLBACK];

    /** 进程指标：HASH，key = 本前缀 + worker name */
    public const WORKER_PREFIX = 'market:metrics:worker:';

    /**
     * 上报过指标的 worker 名字集合。
     *
     * 存在的唯一理由是让监控页别用 KEYS：那个接口被前端每 3 秒轮询一次，
     * 而 KEYS 是 O(全库) 且会阻塞单线程 Redis —— 这个 Redis 是整个交易系统共用的。
     * 一个开着的后台页面就是每分钟 20 次全库扫描。写侧多一次 SADD 就能免掉。
     */
    public const WORKER_REGISTRY = 'market:metrics:workers';

    /** 事件流水：LIST，新事件 lPush 到头部 */
    public const EVENT_KEY = 'market:metrics:event';

    /** 事件流水最多保留条数。500 条在当前事件频率下约覆盖数小时，够回溯一次故障 */
    private const EVENT_KEEP = 500;

    /**
     * 分发事件流水：LIST，订阅方连接级事件（连上 / 握手 / 降级 / 恢复 / 缓冲满 / 断开），新事件 lPush 到头部。
     * 与 EVENT_KEY 分开存：持续读得慢的订阅方约每 30 秒升降一次档，混在一起会把其他事件挤出窗口。
     * 写入方 MarketDispatcher::connEvent()，读取方后台「分发监控」页
     */
    public const DISPATCH_EVENT_KEY = 'market:metrics:dispatch_event';

    /** 分发事件流水最多保留条数 */
    public const DISPATCH_EVENT_KEEP = 2000;

    /**
     * Go 分发机（仓库 go/ 目录，并行试跑中）的分发事件流水，结构与 DISPATCH_EVENT_KEY 相同。
     * 单独存：「行情链路监控」的健康判定会统计 DISPATCH_EVENT_KEY 里的积压断开，试跑中的 Go 不该影响它。
     * 写入方 go/internal/dispatch/report.go，读取方后台「Go 分发监控」页（GoDispatchMonitor）
     */
    public const DISPATCH_EVENT_GO_KEY = 'market:metrics:dispatch_event_go';

    /** 统计窗口(秒)。cnt/bytes 是「最近这么长时间内」的量，由 flushGroups() 按窗口滚动清零 */
    public const WINDOW_SECONDS = 60;

    /**
     * 展示与告警共用的阈值。**只在这里定义一次。**
     *
     * 此前同一个指标在四处各写了一遍阈值，而且值还不一样：
     * 事件循环占用在 judgeHealth() 是 80/50、在监控页与首页卡的 JS 里是 60/30、
     * 在两个常驻进程的 stat 日志里是 60 —— 于是「卡片是黄的但健康判定说正常」
     * 这种自相矛盾是必然的，谁都没写错，只是没有单一出处。
     *
     * 取 80/50 这一组：判据在 judgeHealth() 的注释里（接近 100% 就是没有空隙读 socket），
     * 是唯一给出了理由的那一组。日志的 warning 线一并对齐到 WARN，
     * 这样「日志开始报 warning」与「卡片变黄」是同一时刻，不再需要在脑子里换算。
     */
    public const BUSY_CRIT_PCT   = 80.0;
    public const BUSY_WARN_PCT   = 50.0;
    public const SNDBUF_CRIT_PCT = 60.0;
    public const SNDBUF_WARN_PCT = 20.0;

    /**
     * 百分比 -> 展示等级。Controller 用它产出 *_color 字段，
     * View 只消费颜色不做比较（Coding_Standards §10）。
     *
     * @return string 'crit' | 'warn' | 'ok'
     */
    public static function levelOf(?float $pct, float $crit, float $warn): string
    {
        if ($pct === null) return 'ok';
        if ($pct > $crit) return 'crit';
        if ($pct > $warn) return 'warn';
        return 'ok';
    }

    /** 展示等级 -> 文字颜色 class。两个页面共用同一套色板 */
    public static function levelColor(string $level): string
    {
        return ['crit' => 'color-red', 'warn' => 'color-orange', 'ok' => 'color-green'][$level] ?? 'color-desc';
    }

    /** 展示等级 -> 进度条/徽章背景色 class */
    public static function levelBadge(string $level): string
    {
        return ['crit' => 'layui-bg-red', 'warn' => 'layui-bg-orange', 'ok' => 'layui-bg-green'][$level] ?? 'layui-bg-gray';
    }

    /**
     * 进程内累加区：scope => field => ['last_ts' => 毫秒, 'cnt' => 次数, 'bytes' => 字节]
     * @var array<string, array<string, array{last_ts:int, cnt:int, bytes:int}>>
     */
    private static array $pending = [];

    /** 本轮统计窗口的起点(microtime)，flushGroups() 跨过 WINDOW_SECONDS 时重置 */
    private static float $windowStart = 0.0;

    /**
     * 组装数据组 field。与 MarketSnapshotStore 的 field 口径保持一致，
     * 这样监控页能把「快照里有没有这个字段」和「它多久没更新了」对上号。
     */
    public static function groupField(string $channel, string $sourceKey, string $suffix = ''): string
    {
        $field = $channel . '|' . $sourceKey;
        return $suffix === '' ? $field : $field . '|' . $suffix;
    }

    /**
     * 记一帧。热路径调用，只动内存，不碰 Redis。
     *
     * 埋点约定表 —— 全站唯一出处，新增埋点先在这里登记。scope 标的是「链路上的哪一跳」，
     * 不是「哪个进程」：一个进程可能打多跳，一个 scope 也可能被两端各打一次
     * （各写各机器的 Redis，不冲突）。全部调用点如下，没有第六处：
     *
     *   collect   交易所→本机    MarketCollectorAbstract::touchCollect()
     *   dispatch  分发出去      MarketDispatcher::execute() 内的广播 tick
     *   land      落库成功之后   MarketCollectorAbstract::touchLand()
     *   fallback  REST 兜底刷新  MarketPriceFallback::restFallbackSyncLastPrice()
     *                           MarketPriceFallback::restFallbackSyncMarkPrice()
     *
     * 三条不变量，破了监控页显示的是「错」而不是「空」，比没有埋点更糟：
     *   ① kline 的 $suffix 必须传 period，三跳都要传。MarketMonitor::buildGroups() 按 field
     *      名取并集对齐成行，后缀对不上会裂成两行、kline 的 land 列永远空白。
     *      注意三跳里只有 dispatch 是自动的（后缀由 MarketSnapshotStore::parseField() 继承），
     *      collect 与 land 都是手写传参 —— 漏传不会报错，只会让那一列静默变空
     *   ② land 只能打在 updateXxx() 成功之后。提前打会把「落库一直在失败」显示成一路新鲜
     *   ③ fallback 不能并进 collect。判据(update_ts 新不新鲜)与修复动作(REST 去刷它)共用
     *      同一个字段，合记之后「WS 断了全靠 REST 续命」与「一切正常」长得一模一样
     *
     * 新增 scope 必须同时进 SCOPES 常量，否则 pruneStale() 不会清它。
     *
     * @param string $channel MarketChannelEnum::XXX->value
     * @param int $bytes 该帧序列化后的字节数，传 0 表示不统计字节
     */
    public static function touchGroup(string $scope, string $channel, string $sourceKey, int $bytes = 0, string $suffix = ''): void
    {
        $field = self::groupField($channel, $sourceKey, $suffix);
        if (!isset(self::$pending[$scope][$field])) {
            self::$pending[$scope][$field] = ['last_ts' => 0, 'cnt' => 0, 'bytes' => 0];
        }
        $slot = &self::$pending[$scope][$field];
        $slot['last_ts'] = (int)(microtime(true) * 1000);
        $slot['cnt']++;
        $slot['bytes'] += $bytes;
    }

    /**
     * 把进程内累加的数据组指标批量写进 Redis。由各进程的 stat Timer 调用。
     *
     * cnt/bytes 采用「窗口内累计」语义：不到 WINDOW_SECONDS 就一直累加，
     * 跨过窗口后清零重新计。所以监控页读到的是「最近最多60秒的量」，
     * 比瞬时速率抗抖动，也不需要在这里维护滑动窗口那套复杂度。
     */
    public static function flushGroups(): void
    {
        if (empty(self::$pending)) return;

        $redis = redis_client();
        if (!$redis) return;

        $now = microtime(true);
        if (self::$windowStart <= 0.0) self::$windowStart = $now;
        $rollWindow = ($now - self::$windowStart) >= self::WINDOW_SECONDS;
        $win = (int)round($now - self::$windowStart);

        // 早于这个时刻的累加项一律丢弃，见下面 for 循环里的说明
        $expireBefore = (int)(($now - self::GROUP_RETENTION_SEC) * 1000);

        foreach (self::$pending as $scope => $fields) {
            if (empty($fields)) continue;
            $payload = [];
            foreach ($fields as $field => $slot) {
                // ⚠️ 这里必须剔除过期项，否则 pruneStale() 是白做的：
                // 本方法把 $pending 里的**每一个** field 都重写一遍，不管它这一轮有没有被
                // touchGroup() 碰过。所以一个进程只要活着，它历史上见过的所有数据组就会
                // 每 10 秒被原样(带着那个很旧的 last_ts)写回 Redis —— 刚被清掉的 field
                // 10 秒内就"复活"了，清理只对"生产者已经重启过"的那部分生效。
                // 顺带这也是进程内的无界增长：$pending 只增不减，每轮还要全量 json_encode。
                if ($slot['last_ts'] > 0 && $slot['last_ts'] < $expireBefore) {
                    unset(self::$pending[$scope][$field]);
                    continue;
                }
                $payload[$field] = json_encode([
                    'last_ts' => $slot['last_ts'],
                    'cnt'     => $slot['cnt'],
                    'bytes'   => $slot['bytes'],
                    'win'     => $win,
                ]);
            }
            if (empty($payload)) continue;
            try {
                $redis->hMSet(self::GROUP_PREFIX . $scope, $payload);
            } catch (\Throwable $e) {
                // 观测失败不能影响主流程，静默吞掉
                return;
            }
        }

        if ($rollWindow) {
            // 只清计数，保留 last_ts —— 否则窗口一滚动，「最后更新时间」就丢了，
            // 而那恰恰是判断「数据组是不是断了」最关键的一列
            foreach (self::$pending as $scope => $fields) {
                foreach (array_keys($fields) as $field) {
                    self::$pending[$scope][$field]['cnt'] = 0;
                    self::$pending[$scope][$field]['bytes'] = 0;
                }
            }
            self::$windowStart = $now;
        }
    }

    /**
     * 本进程的启动时刻(microtime)。由 CommandHeartBeatService::start() 在 onWorkerStart
     * 里调一次 markBoot() 写入 —— 那是所有常驻命令都会走的统一入口，一处调用全体覆盖。
     */
    private static float $bootTs = 0.0;

    /** 记录进程启动时刻。重复调用只有第一次生效（worker 崩溃重启后是新进程，静态自然重置） */
    public static function markBoot(): void
    {
        if (self::$bootTs <= 0.0) self::$bootTs = microtime(true);
    }

    /**
     * 写进程指标（覆盖式）。$fields 里放什么由各进程自己决定，
     * 监控页按 key 名展示，不做强 schema —— 各类 worker 关心的指标本来就不一样。
     *
     * 几个**所有进程都该有**的字段在这里统一补上，免得每个 command 各写一遍还写得不一样：
     *   update_ts / pid / boot_ts / uptime_sec / mem / mem_peak
     * uptime 尤其有用：出问题时第一个要分清的就是"它是一直这样，还是刚重启之后才这样" ——
     * 一个刚起来 30 秒的进程报的错，和一个跑了三天的进程报的错，排查方向完全不同。
     */
    public static function putWorker(string $name, array $fields): void
    {
        $redis = redis_client();
        if (!$redis) return;
        $now = microtime(true);
        // 兜底：没走 markBoot() 的进程，退化成"首次上报时刻"。
        // 会比真实启动晚最多一个 stat 周期，不精确但不会是错的量级
        if (self::$bootTs <= 0.0) self::$bootTs = $now;

        $fields['update_ts']  = (int)($now * 1000);
        $fields['pid']        = getmypid();
        $fields['boot_ts']    = (int)(self::$bootTs * 1000);
        $fields['uptime_sec'] = (int)($now - self::$bootTs);
        // 常驻进程最该盯的一条：内存单调上涨 = 有泄漏。true 取的是真实向系统申请的字节
        $fields['mem']        = memory_get_usage(true);
        $fields['mem_peak']   = memory_get_peak_usage(true);
        try {
            $redis->multi(\Redis::PIPELINE)
                ->hMSet(self::WORKER_PREFIX . $name, array_map(
                    fn($v) => is_scalar($v) ? (string)$v : json_encode($v),
                    $fields
                ))
                ->sAdd(self::WORKER_REGISTRY, $name)
                ->exec();
        } catch (\Throwable $e) {
        }
    }

    /**
     * 记一条离散事件。频率低（缓冲满/断连级别），可以直接写 Redis。
     * ⚠️ 不要在每帧路径上调用它。
     */
    public static function event(string $type, string $msg, array $extra = []): void
    {
        $redis = redis_client();
        if (!$redis) return;
        try {
            $redis->lPush(self::EVENT_KEY, json_encode([
                'ts'    => (int)(microtime(true) * 1000),
                'type'  => $type,
                'msg'   => $msg,
                'pid'   => getmypid(),
            ] + $extra));
            $redis->lTrim(self::EVENT_KEY, 0, self::EVENT_KEEP - 1);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 记一条分发事件（订阅方连接级）。字段结构化存，文案由后台页面生成。
     * ⚠️ 不要在每帧路径上调用它。
     *
     * @param int    $marketType 分发进程的 pair 类型（MarketDataTypeEnum 的值）
     * @param string $type       事件名，全集见 DispatchMonitor::EVENT_TYPES
     * @param array  $fields     conn / node / ip 与该事件自己的字段
     */
    public static function dispatchEvent(int $marketType, string $type, array $fields): void
    {
        $redis = redis_client();
        if (!$redis) return;
        try {
            $redis->multi(\Redis::PIPELINE)
                ->lPush(self::DISPATCH_EVENT_KEY, json_encode([
                    'ts'          => (int)(microtime(true) * 1000),
                    'type'        => $type,
                    'market_type' => $marketType,
                ] + $fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))
                ->lTrim(self::DISPATCH_EVENT_KEY, 0, self::DISPATCH_EVENT_KEEP - 1)
                ->exec();
        } catch (\Throwable $e) {
        }
    }

    /**
     * 包住一个 stat 定时器的整个回调体，保证它**失败得看得见**。
     *
     * ── 为什么必须有这个 ──────────────────────────────────────────────────
     * Workerman 会**吞掉**定时器回调里的异常并继续调度 —— 两条路径都是这样：
     *   Timer::tick()          catch (Throwable) { Worker::safeEcho((string)$e); }
     *   Events\Select::safeCall() catch (Throwable) { echo $e; }
     * 也就是说进程不会崩、不会重启、下一轮照样触发，行情也照常在流。
     * 唯一的后果是：如果异常发生在 putWorker() **之前**，指标就此永久冻结，
     * 而 echo 出去的那点堆栈对 daemon 化的进程等于扔进了 /dev/null。
     *
     * 于是现象就变成「监控页说进程卡死，实际价格一直在更新」—— 卡住的是观测，不是进程。
     * （注：早先在 MarketDbFlusher 里写过"定时器抛异常会让 worker 崩掉重启"，
     *   那句话对这个版本的 Workerman 是错的，已一并更正。）
     *
     * 所以这里自己兜住，并且**一定要留下痕迹**：往指标里写 stat_error，
     * 监控页就能直接显示真实原因，而不是靠 metric_age 去猜"是不是卡死了"。
     *
     * @param string   $worker worker 名，出错时用它把错误挂回对应的卡片
     */
    /** 上一轮是否报过错，用来决定恢复后要不要清掉 stat_error（见 guardStat 末尾） */
    private static array $statFailed = [];

    /** 本进程是否已经为某个 worker 清过一次历史 stat_error。见 guardStat 里"上一条命"的说明 */
    private static array $statCleared = [];

    public static function guardStat(string $worker, callable $fn): void
    {
        try {
            $fn();
            // 恢复了就得把错误擦掉 —— putWorker 是**按字段合并**的，
            // 不擦的话 stat_error 会一直挂在 hash 上，问题早就修好了卡片还在报红。
            //
            // ⚠️ 这里必须包含"进程内第一次成功"这种情况，不能只看 $statFailed：
            // 那是个**进程内**静态，重启后是空的。而 Redis 里的 stat_error 是
            // **上一条命**留下的 —— 于是"修好代码 + 重启"之后，新进程明明一切正常，
            // 卡片却还挂着重启前的旧错误，让人以为没修好。（现网就这么误导了一次。）
            // 代价是每个进程生命周期内多一次 hDel，可以忽略。
            $firstSuccess = !isset(self::$statCleared[$worker]);
            if ($firstSuccess || !empty(self::$statFailed[$worker])) {
                self::$statCleared[$worker] = true;
                self::$statFailed[$worker] = false;
                try {
                    if ($redis = redis_client()) {
                        $redis->hDel(self::WORKER_PREFIX . $worker, 'stat_error', 'stat_error_at');
                    }
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable $e) {
            self::$statFailed[$worker] = true;
            // 错误路径上每一步都不能再抛，否则就真的什么都留不下了
            $msg = sprintf('%s: %s @%s:%d', get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
            try {
                // 这次 putWorker 会把 update_ts 一起刷新 —— 刻意如此：
                // 卡片从"很久没更新，疑似卡死"变成"刚刚更新过，但上报出错了+原因"，
                // 后者才是真相
                self::putWorker($worker, [
                    'stat_error'    => mb_substr($msg, 0, 300),
                    'stat_error_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable) {
            }
            try {
                business_log('market_stat_guard', "[{$worker}] stat 定时器异常，指标可能已停更：{$msg}", 'error');
            } catch (\Throwable) {
            }
        }
    }

    // -------------------------------------------------------------------------
    // 清理：唯一会**删**指标的地方
    // -------------------------------------------------------------------------

    /**
     * 数据组 field 的保留时长(秒)。超过这个时间没被 touchGroup() 过就删掉。
     *
     * 24 小时的依据：last_ts 用的是**本机时钟**（touchGroup 里的 microtime），
     * 是货真价实的「我们处理过这一帧」，不是帧上带的交易所时间戳。
     * 而任何一个还活着的数据组，更新间隔都远小于一天 —— 最慢的资金费率也是 8 小时一轮，
     * 24 小时留了 3 倍余量。超过一天没动过，只可能是下架 / 改名 / 配置里已经删掉了。
     *
     * 删早了也不会丢东西：下一帧到达时 flushGroups() 会把 field 重新建出来，
     * 是自愈的。真正需要小心的是反过来 —— 见下面 pruneStale() 里关于「别把故障删没了」的说明。
     */
    private const GROUP_RETENTION_SEC = 86400;

    /**
     * 进程指标的保留时长(秒)。刻意比数据组长得多，因为**语义完全不同**：
     * 一个 worker 的卡片变红、显示「指标更新于 3600s 前」，那**本身就是告警**，不是垃圾。
     * 所以这里不承担「及时清理」的职责，只负责收掉改名/下线留下的孤儿名字。
     * 7 天足够长到不会误删任何还有告警价值的进程。
     *
     * 另外 worker 名字是个位数的固定集合，不随业务数据增长 ——
     * 无界增长的问题只出在数据组那一侧，这里加清理纯粹是顺手。
     */
    private const WORKER_RETENTION_SEC = 604800;

    /** pruneStale() 的最小间隔(秒)。它会 hGetAll 整个指标 hash，不该跟着 10 秒的 stat 定时器跑 */
    private const PRUNE_MIN_INTERVAL = 3600;

    /** 上次真正执行清理的时刻(microtime)。0 = 本进程还没跑过 */
    private static float $lastPrune = 0.0;

    /**
     * 删掉过期的指标条目。
     *
     * ── 为什么可以由任意进程来做、也可以多个进程同时做 ─────────────────────────
     * 判据只有「last_ts 有多旧」，跟谁写的无关：一个 field 超过保留期还没被碰过，
     * 就说明**没有任何一个活着的生产者**在写它。所以不需要在 collect/dispatch/land
     * 三个 scope 的写方之间做任何协调，hDel 一个已经不在的 field 也是 no-op。
     *
     * ── 为什么这样删不会把真正的故障一起删掉 ──────────────────────────────────
     * 「数据组新鲜度」那张表是**按指标 hash 枚举行**的，删了就不显示了。
     * 但判断「某一路该不该有数据」的权威清单从来不是指标 hash，而是 market_data /
     * 配置里的 source_key 列表 —— 落库链路诊断页正是按 market_data 枚举行的
     * (见 MarketPipeline::data())，指标缺失在那边会正确地判成「① 没收到帧」。
     * 也就是说：**该报的故障有专门的页面在报，这里删掉的只是无人认领的历史残留。**
     *
     * @param bool $force 忽略 PRUNE_MIN_INTERVAL 立刻执行（命令行手动清理用）
     * @return array{group:int,worker:int} 各删了几条
     */
    public static function pruneStale(bool $force = false): array
    {
        $now = microtime(true);
        if (!$force && self::$lastPrune > 0.0 && ($now - self::$lastPrune) < self::PRUNE_MIN_INTERVAL) {
            return ['group' => 0, 'worker' => 0];
        }
        self::$lastPrune = $now;

        $redis = redis_client();
        if (!$redis) return ['group' => 0, 'worker' => 0];

        $nowMs = (int)($now * 1000);
        $group = 0;
        $worker = 0;

        try {
            foreach (self::SCOPES as $scope) {
                $key  = self::GROUP_PREFIX . $scope;
                $dead = [];
                foreach ($redis->hGetAll($key) ?: [] as $field => $json) {
                    $row = json_decode((string)$json, true);
                    // last_ts 缺失/不可解析的条目一律当死的：正常写入路径必定带 last_ts，
                    // 没有就是早期格式或写坏了，留着也读不出意义
                    $lastTs = is_array($row) ? (int)($row['last_ts'] ?? 0) : 0;
                    if ($lastTs <= 0 || ($nowMs - $lastTs) > self::GROUP_RETENTION_SEC * 1000) {
                        $dead[] = $field;
                    }
                }
                if ($dead) {
                    // 分批 hDel：单个 source_key 批量下架时 $dead 可能上千，
                    // 一次传完会做出一条超长命令阻塞这个共用 Redis
                    foreach (array_chunk($dead, 200) as $chunk) {
                        $redis->hDel($key, ...$chunk);
                    }
                    $group += count($dead);
                }
            }

            foreach (self::readWorkerNames() as $name) {
                $hash = $redis->hGetAll(self::WORKER_PREFIX . $name) ?: [];
                $upd  = (int)($hash['update_ts'] ?? 0);
                if ($hash && $upd > 0 && ($nowMs - $upd) <= self::WORKER_RETENTION_SEC * 1000) continue;

                // hash 整个不在了(被手动删/过期)也走这里：注册表里留着一个查不到内容的
                // 名字，监控页只会渲染出一张空卡片
                $redis->del(self::WORKER_PREFIX . $name);
                $redis->sRem(self::WORKER_REGISTRY, $name);
                $worker++;
            }
        } catch (\Throwable $e) {
            // 清理失败不能影响主流程；下一轮还会再来
            return ['group' => $group, 'worker' => $worker];
        }

        return ['group' => $group, 'worker' => $worker];
    }

    // -------------------------------------------------------------------------
    // 读取侧：供后台监控页使用
    // -------------------------------------------------------------------------

    /** @return array<string, array{last_ts:int,cnt:int,bytes:int,win:int}> */
    public static function readGroups(string $scope): array
    {
        $redis = redis_client();
        if (!$redis) return [];
        $raw = $redis->hGetAll(self::GROUP_PREFIX . $scope) ?: [];
        $out = [];
        foreach ($raw as $field => $json) {
            $row = json_decode((string)$json, true);
            if (is_array($row)) $out[$field] = $row;
        }
        return $out;
    }

    /**
     * 上报过指标的 worker 名字。取代监控页原先的 KEYS 扫描，见 WORKER_REGISTRY 注释。
     * @return array<int,string>
     */
    public static function readWorkerNames(): array
    {
        $redis = redis_client();
        if (!$redis) return [];
        try {
            return $redis->sMembers(self::WORKER_REGISTRY) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function readWorker(string $name): array
    {
        $redis = redis_client();
        if (!$redis) return [];
        return $redis->hGetAll(self::WORKER_PREFIX . $name) ?: [];
    }

    /** @return array<int, array> 最新的在前 */
    public static function readEvents(int $limit = 100, string $typeFilter = ''): array
    {
        $redis = redis_client();
        if (!$redis) return [];
        $rows = $redis->lRange(self::EVENT_KEY, 0, max(1, $limit) - 1) ?: [];
        $out = [];
        foreach ($rows as $json) {
            $row = json_decode((string)$json, true);
            if (!is_array($row)) continue;
            if ($typeFilter !== '' && ($row['type'] ?? '') !== $typeFilter) continue;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * 分发事件，最新的在前。
     *
     * @param int    $limit   最多读多少条（从头部起）
     * @param int    $sinceMs 只要这个时刻(毫秒)之后的，0 = 不限；列表按时间倒序，遇到更早的即停
     * @param string $key     DISPATCH_EVENT_KEY（PHP 分发）或 DISPATCH_EVENT_GO_KEY（Go 分发）
     * @return array<int, array>
     */
    public static function readDispatchEvents(int $limit = self::DISPATCH_EVENT_KEEP, int $sinceMs = 0, string $key = self::DISPATCH_EVENT_KEY): array
    {
        $redis = redis_client();
        if (!$redis) return [];
        try {
            $rows = $redis->lRange($key, 0, max(1, $limit) - 1) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $json) {
            $row = json_decode((string)$json, true);
            if (!is_array($row)) continue;
            if ($sinceMs > 0 && (int)($row['ts'] ?? 0) < $sinceMs) break;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * 最近 N 秒内某类事件的条数。用于「30秒内是否报过 send buffer full」这类判断。
     */
    public static function countRecentEvents(string $type, int $withinSeconds): int
    {
        $cutoff = (int)(microtime(true) * 1000) - $withinSeconds * 1000;
        $n = 0;
        foreach (self::readEvents(self::EVENT_KEEP) as $row) {
            if (($row['ts'] ?? 0) < $cutoff) break; // 列表按时间倒序，遇到过期的即可停
            if (($row['type'] ?? '') === $type) $n++;
        }
        return $n;
    }
}
