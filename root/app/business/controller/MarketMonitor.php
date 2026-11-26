<?php

declare(strict_types=1);

namespace app\business\controller;

use app\lib\MarketMetrics;
use app\lib\MarketSnapshotStore;
use app\lib\CommandHeartBeatService;
use app\enum\MarketChannelEnum;
use app\lib\MarketWorkers;
use app\lib\WorkerHealth;
use app\enum\MarketDataTypeEnum;
use think\admin\service\AdminService;

/**
 * 行情链路监控
 *
 * 回答三类问题：
 *   1. 某个交易对的某类数据是不是断了 —— 数据组新鲜度表
 *      三个 scope 并排看是关键：collect(采集器从交易所收到) / dispatch(分发机发出) /
 *      land(订阅方落库)。三者对不上就能直接指出断点：
 *      collect 有 dispatch 无 = 采集器没发布；dispatch 有 land 无 = 分发链路丢了。
 *   2. 采集、落库等行情进程忙不忙、活没活着 —— worker 卡片（事件循环占用 / 任务判活）
 *   3. 落库有没有写失败 —— 告警条与事件流水
 *
 * 分发进程不在本页：PHP 分发机归「分发监控」（DispatchMonitor，含健康判定），
 * Go 分发机归「Go 分发监控」（GoDispatchMonitor）。两边进程模型不同，
 * 放在这里共用一套判据会误判（Go 的 dispatch_event 是有事件才跑的任务，被当成定时器判成「已停」）。
 *
 * ⚠️ 本页**只读 Redis，不碰 MySQL**：监控页自己不能变成负载源。
 */
class MarketMonitor extends Base
{
    /** 前端自动刷新间隔(秒) */
    private const REFRESH_SECONDS = 3;

    /** 允许被「重置」操作清理的 key 前缀白名单。故意不含 heartbeat:* 与业务数据 */
    private const RESETTABLE = [
        // 现货 / 合约两个分发进程各一套快照键，见 MarketSnapshotStore
        'snapshot' => ['label' => '分发快照与脏集合', 'keys' => [
            MarketSnapshotStore::SNAP_KEY_PREFIX . MarketDataTypeEnum::SPOT->value,
            MarketSnapshotStore::DIRTY_KEY_PREFIX . MarketDataTypeEnum::SPOT->value,
            MarketSnapshotStore::SNAP_KEY_PREFIX . MarketDataTypeEnum::SWAP->value,
            MarketSnapshotStore::DIRTY_KEY_PREFIX . MarketDataTypeEnum::SWAP->value,
        ]],
        'metrics'  => ['label' => '监控指标(仅观测数据，清了不影响行情)', 'keys' => [
            MarketMetrics::GROUP_PREFIX . MarketMetrics::SCOPE_COLLECT,
            MarketMetrics::GROUP_PREFIX . MarketMetrics::SCOPE_DISPATCH,
            MarketMetrics::GROUP_PREFIX . MarketMetrics::SCOPE_LAND,
            MarketMetrics::EVENT_KEY,
            MarketMetrics::DISPATCH_EVENT_KEY,
            MarketMetrics::DISPATCH_EVENT_GO_KEY,
            MarketMetrics::WORKER_REGISTRY,
            // 每个进程的 worker 哈希 (WORKER_PREFIX . name) 不在这里列举 —— 名字是运行期才知道的，
            // 由 reset() 用 SCAN 补上，见那里的说明
        ]],
    ];

    /**
     * 行情链路监控面板
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '行情链路监控';
        $this->refresh = self::REFRESH_SECONDS;
        $this->staleSeconds = (int)\think\facade\Config::get('market.stale_seconds', 300);
        $this->resettable = self::RESETTABLE;
        return $this->fetch();
    }

    /**
     * 面板数据（前端定时拉取）
     * @auth true
     */
    public function data()
    {
        $redis = redis_client();
        if (!$redis) {
            $this->error('Redis 不可用，无法读取监控指标');
        }

        $this->success('ok', [
            'groups'  => $this->buildGroups(),
            'workers' => $this->buildWorkers($redis),
            'redis'   => $this->buildRedisStats($redis),
            'events'  => MarketMetrics::readEvents(60),
            'alerts'  => [
                // 落库写失败意味着**库里的值和热层不一致**，且此前完全没有出口。
                // 缓冲满 / 积压断开这类订阅方连接级告警在「分发监控」页的告警条里
                'db_flush_fail_300s' => MarketMetrics::countRecentEvents('db_flush_fail', 300),
            ],
            'now_ms'  => (int)(microtime(true) * 1000),
        ]);
    }

    /**
     * 数据组新鲜度：把三个 scope 拼成一行，便于横向比对哪一跳断了
     */
    private function buildGroups(): array
    {
        $scopes = [
            MarketMetrics::SCOPE_COLLECT,
            MarketMetrics::SCOPE_DISPATCH,
            MarketMetrics::SCOPE_LAND,
        ];
        $byScope = [];
        foreach ($scopes as $sc) {
            $byScope[$sc] = MarketMetrics::readGroups($sc);
        }

        // 三个 scope 的 field 取并集：某一跳完全没有记录本身就是最重要的信号
        $fields = [];
        foreach ($byScope as $rows) {
            foreach (array_keys($rows) as $f) $fields[$f] = true;
        }

        $now = (int)(microtime(true) * 1000);
        $out = [];
        foreach (array_keys($fields) as $field) {
            $parsed = MarketSnapshotStore::parseField((string)$field);
            if ($parsed === null) continue;

            // 数据组键：collect / land 两跳按 source_key 记，dispatch 一跳按 pair 记（分发协议 v3），
            // 所以同一个品种会出现两行。字段名沿用 source_key 只是为了不改前端，parseField 对两种键一视同仁
            $row = [
                'field'      => $field,
                'channel'    => $this->shortChannel($parsed['channel']),
                'source_key' => $parsed['pair'],
                'suffix'     => $parsed['suffix'],
            ];
            foreach ($scopes as $sc) {
                $m = $byScope[$sc][$field] ?? null;
                $row[$sc] = $m === null ? null : [
                    'last_ts'  => (int)$m['last_ts'],
                    // 绝对时刻在**服务端**格式化，不交给浏览器 new Date() ——
                    // 排查时要拿它跟服务器上的日志时间对，浏览器时区一旦不同就对不上了
                    'last_at'  => $m['last_ts'] > 0 ? date('H:i:s', intdiv((int)$m['last_ts'], 1000)) : null,
                    'age_sec'  => $m['last_ts'] > 0 ? round(($now - $m['last_ts']) / 1000, 1) : null,
                    'cnt'      => (int)$m['cnt'],
                    'win'      => (int)$m['win'],
                    // 窗口内速率比绝对次数更好比对：不同 scope 的统计窗口可能不同步。
                    // win 用 max(1,..) 兜底 —— 窗口刚重置那一瞬间 win=0，
                    // 直接除会得到 0，反而让人误以为没数据
                    'rate'     => round($m['cnt'] / max(1, (int)$m['win']), 2),
                ];
            }
            $out[] = $row;
        }

        usort($out, fn($a, $b) => [$a['source_key'], $a['channel']] <=> [$b['source_key'], $b['channel']]);
        return $out;
    }

    /**
     * Worker 现场。存活判定直接复用已有的心跳注册表，不另造一套。
     */
    private function buildWorkers($redis): array
    {
        $names = [];
        try {
            $names = $redis->sMembers(CommandHeartBeatService::REGISTRY_KEY) ?: [];
        } catch (\Throwable $e) {
        }

        // 指标里出现过但心跳表里没有的，也要列出来（可能刚崩、心跳已过期）。
        // 读注册表而不是 KEYS：本接口被前端每 3 秒轮一次，KEYS 会阻塞全站共用的
        // 单线程 Redis，见 MarketMetrics::WORKER_REGISTRY
        $names = array_merge($names, MarketMetrics::readWorkerNames());
        $names = array_values(array_unique(array_filter($names)));

        $now = (int)(microtime(true) * 1000);
        $out = [];
        foreach ($names as $name) {
            $metrics = MarketMetrics::readWorker($name);
            $alive = false;
            try {
                $alive = (bool)$redis->exists(CommandHeartBeatService::KEY_PREFIX . $name);
            } catch (\Throwable $e) {
            }
            // 只关心行情相关进程，避免把全站所有常驻命令都堆进来
            if (stripos($name, 'market') === false && stripos($name, 'crawler') === false) continue;
            if (self::isDispatcher($name)) continue;

            $updateTs = (int)($metrics['update_ts'] ?? 0);
            $metricAge = $updateTs > 0 ? round(($now - $updateTs) / 1000, 1) : null;
            $uptime = (int)($metrics['uptime_sec'] ?? 0);
            $bootTs = (int)($metrics['boot_ts'] ?? 0);
            $row = [
                'name'        => $name,
                'alive'       => $alive,
                'metric_age'  => $metricAge,
                'metrics'     => $metrics,
                // 进程现场：出问题时第一个要分清的是"一直如此"还是"刚重启后才如此"
                'pid'         => (int)($metrics['pid'] ?? 0),
                'boot_at'     => $bootTs > 0 ? date('m-d H:i:s', (int)($bootTs / 1000)) : '-',
                'uptime_sec'  => $uptime,
                'uptime_text' => $uptime > 0 ? WorkerHealth::duration($uptime) : '-',
                'mem'         => (int)($metrics['mem'] ?? 0),
                'mem_peak'    => (int)($metrics['mem_peak'] ?? 0),
                // stat_error 是否早于本次启动 —— 早于就说明是上一条命的残留，不是当前故障
                'stat_error_stale' => WorkerHealth::statErrorIsStale($metrics),
                // 与下面补进来的"该跑却没跑起来"合成行区分：那些行没有任何真实指标
                'missing'          => false,
            ];
            // 成交窗口溢出的**说明文案**在这里生成，不在模板里拼（Coding_Standards §10）
            $row['trade_overflow_note'] = $this->tradeOverflowNote($metrics);
            // 阈值→颜色的映射在 Controller 完成，View 只消费（Coding_Standards §10）。
            // 常量来自 MarketMetrics，与采集器 stat 日志的 warning 线是同一组值
            $busyPct = isset($metrics['busy_pct']) ? (float)$metrics['busy_pct'] : null;
            $row['busy_level']   = MarketMetrics::levelOf($busyPct, MarketMetrics::BUSY_CRIT_PCT, MarketMetrics::BUSY_WARN_PCT);
            $row['busy_color']   = MarketMetrics::levelColor($row['busy_level']);
            $row['health'] = $this->judgeHealth($metrics, $alive, $metricAge);
            $out[] = $row;
        }
        // ── 该跑却没跑起来 ──────────────────────────────────────────────
        //
        // 上面那个循环只能列出**上报过指标的**进程 —— 一个从来没启动过的进程
        // 在注册表里没有名字、在心跳里没有键，于是在这一页上**根本不存在**。
        // 「没有这一行」和「一切正常」长得一模一样，这正是费率断供 8 小时
        // 才在结算时暴露的原因。
        //
        // 判据必须来自「应当有谁」这份清单，而不是来自运行时注册表 ——
        // 注册表是"谁来过"，回答不了"谁该来但没来"。
        $seen = array_column($out, 'name');
        foreach (MarketWorkers::expected() as $want => $why) {
            // 分发机没起来由「分发监控」页的进程卡报「未运行」
            if (in_array($want, $seen, true) || self::isDispatcher($want)) continue;
            $out[] = [
                'name'       => $want,
                'alive'      => false,
                'metric_age' => null,
                'metrics'    => [],
                'pid'        => 0,
                'boot_at'    => '-',
                'uptime_sec' => 0,
                'uptime_text' => '-',
                'mem'        => 0,
                'mem_peak'   => 0,
                'stat_error_stale' => false,
                'trade_overflow_note' => '',
                'busy_level'   => 'ok',
                'busy_color'   => MarketMetrics::levelColor('ok'),
                'missing'      => true,
                'health'       => [
                    'level'  => 'crit',
                    'text'   => '未启动',
                    'reason' => sprintf(
                        '这个进程应当在跑，但它既没有心跳、也从未上报过指标 —— '
                        . '不是"卡住了"，是**根本没起来**。%s。'
                        . '用 deploy/restart_market.sh 重新下发，或 php think command:heartbeat:check_expire --verify 核对',
                        $why
                    ),
                ],
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * 成交滚动窗口溢出的展示文案。
     *
     * ⚠️ 这一项**不是告警**，此前它是，而且给的建议是反的。口径已由
     * MarketCollectorAbstract 的注释与 commit 5abd2ee4 确立：
     *   · 窗口容量 = trade_window ÷ tick_ms（默认 30 笔 ÷ 0.2s = 150 笔/秒/source_key）。
     *     币安现货 BTC 一个就常年超过 —— 活跃交易对上溢出是**结构性常态**，不是故障。
     *   · "调大 trade_window"只会白花几倍成交流量：下游 MarketService::updateTrade()
     *     同样只保留 trade_window 笔，多发过去的照样被切掉。
     *   · 成交流的唯一消费方是前端「最近成交」列表：不做 VWAP、不累加、不进结算、不参与定价。
     * 所以它只回答"合并掉了多少"，真正要盯的是 trade 那一组的 land 时间戳有没有停走。
     *
     * @return string 空串 = 没有溢出，不必显示这一行
     */
    private function tradeOverflowNote(array $metrics): string
    {
        $overflow = (int)($metrics['trade_overflow'] ?? 0);
        if ($overflow <= 0) return '';

        $window = max(1, (int)\think\facade\Config::get('market.dispatch.trade_window', 30));
        $tickMs = max(1, (int)\think\facade\Config::get('market.dispatch.tick_ms', 100));
        return sprintf(
            '结构性合并丢弃 %d 笔（窗口 %d 笔 · tick %dms ⇒ 容量约 %d 笔/秒）；'
            . '下游按成交 id 去重后同样只保留最近 %d 笔，调大 trade_window 不会改变这一点。'
            . '仅当同时出现 trade 组 land 时间戳停走时才需要处理。',
            $overflow, $window, $tickMs, (int)round($window * 1000 / $tickMs), $window
        );
    }

    /**
     * 是否分发进程（PHP 分发机与 Go 试跑的 *_Go 都算），本页不展示，见类注释。
     * 用名字片段而不是 MarketWorkers 的清单：注册表里还会有已下线的旧名字，它们同样不该出现在这里
     */
    private static function isDispatcher(string $name): bool
    {
        return str_contains($name, 'MarketDispatchServer');
    }

    /**
     * 给单个 worker 一句话的健康判定。
     *
     * 存在的理由：卡片上那十来个数字，每个单独看都不足以下结论，把判据固化下来比凭记忆读原始数字可靠。
     * 通用的三条（存活 / 上报 / 任务判活）在 WorkerHealth::common()，这里只补采集类进程自己的结论。
     *
     * @return array{level:string, text:string, reason:string}
     */
    private function judgeHealth(array $metrics, bool $alive, ?float $metricAge): array
    {
        $common = WorkerHealth::common($metrics, $alive, $metricAge);
        if ($common !== null) return $common;

        $symbols = (int)($metrics['symbol_count'] ?? 0);
        return WorkerHealth::ok($symbols > 0 ? "采集 {$symbols} 个交易对" : '运行中');
    }

    private function buildRedisStats($redis): array
    {
        $out = [];
        $probes = [
            ['key' => MarketSnapshotStore::snapKey(MarketDataTypeEnum::SPOT->value),  'type' => 'hash', 'label' => '分发快照(现货)'],
            ['key' => MarketSnapshotStore::dirtyKey(MarketDataTypeEnum::SPOT->value), 'type' => 'set',  'label' => '待发脏字段(现货)'],
            ['key' => MarketSnapshotStore::snapKey(MarketDataTypeEnum::SWAP->value),  'type' => 'hash', 'label' => '分发快照(合约)'],
            ['key' => MarketSnapshotStore::dirtyKey(MarketDataTypeEnum::SWAP->value), 'type' => 'set',  'label' => '待发脏字段(合约)'],
            ['key' => MarketMetrics::EVENT_KEY,           'type' => 'list', 'label' => '事件流水'],
            ['key' => MarketMetrics::DISPATCH_EVENT_KEY,  'type' => 'list', 'label' => '分发事件流水'],
            ['key' => MarketMetrics::DISPATCH_EVENT_GO_KEY, 'type' => 'list', 'label' => '分发事件流水(Go 试跑)'],
        ];
        foreach ($probes as $p) {
            $size = -1;
            try {
                $size = match ($p['type']) {
                    'hash' => (int)$redis->hLen($p['key']),
                    'set'  => (int)$redis->sCard($p['key']),
                    'list' => (int)$redis->lLen($p['key']),
                };
            } catch (\Throwable $e) {
            }
            $mem = null;
            try {
                // rawCommand 不经 OPT_PREFIX，key 要自己补实例前缀
                $mem = $redis->rawCommand('MEMORY', 'USAGE', $redis->_prefix($p['key']));
            } catch (\Throwable $e) {
            }
            $out[] = $p + ['size' => $size, 'memory' => is_numeric($mem) ? (int)$mem : null];
        }
        return $out;
    }

    /**
     * 重置指定分组的 Redis 键。
     *
     * 危险操作，因此：① 白名单限定可清理的 key，**绝不使用 FLUSHDB**；
     * ② 记录操作人 UID 与变更前后的规模（Coding_Standards §2 要求非幂等写操作留审计上下文）。
     * @auth true
     */
    public function reset()
    {
        $group = (string)$this->request->post('group', '');
        if (!isset(self::RESETTABLE[$group])) {
            $this->error('未知的重置分组');
        }

        $redis = redis_client();
        if (!$redis) $this->error('Redis 不可用');

        $before = [];
        foreach (self::RESETTABLE[$group]['keys'] as $key) {
            try {
                $before[$key] = (int)$redis->exists($key);
            } catch (\Throwable $e) {
                $before[$key] = -1;
            }
        }

        // 除了白名单里的固定 key，metrics 分组还要连每个进程的 worker 哈希一起清 ——
        // RESETTABLE 里只有注册表 WORKER_REGISTRY，删掉它之后
        // market:metrics:worker:{name} 这些哈希就再也没人引用得到、也再没人会删，
        // 而 putWorker() 是 hMSet 不清场：进程下次上报时旧字段仍留在里面。
        // 用 SCAN 不用 KEYS：这个 Redis 是整个交易系统共用的单线程实例。
        $keys = self::RESETTABLE[$group]['keys'];
        if ($group === 'metrics') {
            $keys = array_merge($keys, $this->scanKeys($redis, MarketMetrics::WORKER_PREFIX . '*'));
        }

        $deleted = 0;
        try {
            $deleted = (int)$redis->del($keys);
        } catch (\Throwable $e) {
            $this->error('清理失败：' . $e->getMessage());
        }

        $uid = AdminService::getUserId();
        business_log('market_monitor', sprintf(
            'reset group=%s admin_uid=%s keys=%s existed_before=%s deleted=%d',
            $group, $uid,
            implode(',', $keys),
            json_encode($before),
            $deleted
        ), 'warning');

        $this->success("已清理 {$deleted} 个键（{$group}）");
    }

    /**
     * 按模式取 key 列表。用 SCAN 游标而不是 KEYS：本 Redis 是全站共用的单线程实例，
     * KEYS 会把它阻塞住。带轮次上限，防止 SCAN 在极端情况下转不完。
     *
     * @return array<int,string>
     */
    private function scanKeys($redis, string $pattern): array
    {
        $out = [];
        try {
            $it = null;
            $rounds = 0;
            while ($rounds++ < 1000) {
                $batch = $redis->scan($it, $pattern, 200);
                if ($batch === false) break;          // 游标走完，phpredis 返回 false
                foreach ($batch as $k) $out[] = (string)$k;
                if ((int)$it === 0) break;
            }
        } catch (\Throwable $e) {
            // 扫不到就只清白名单里的固定 key，不因为清理不彻底而让整个操作失败
        }
        return array_values(array_unique($out));
    }

    private function shortChannel(string $channel): string
    {
        return MarketChannelEnum::shorten($channel);
    }
}
