<?php

declare(strict_types=1);

namespace app\business\controller;

use app\lib\MarketMetrics;
use app\business\service\MarketService;
use app\enum\MarketChannelEnum;
use think\facade\Db;

/**
 * 落库链路诊断
 *
 * 回答一个很具体的问题：**同一个 source_key，A 已经更新到 4:09:00，B 却卡在 4:07:30，
 * 到底卡在哪一站？**
 *
 * 行情落库不是一步到位的，中间有四站：
 *
 *   ① onMessage 收到帧      MarketMetrics 的 collect/dispatch scope，记的是**本机收到的时刻**
 *   ② 写进 Redis 热层       market:hot:md:{source_key}
 *   ③ 标脏等待刷盘          market:dirty:md
 *   ④ MarketDbFlusher 落库  market_data
 *
 * 单看任何一站都判不出问题，必须并排比。本页就是把这四个时间戳摆在一行里，
 * 并直接给出判定 —— 尤其是下面这两种，它们现象一模一样但原因完全相反：
 *
 *   热层比库新 + **在**脏集合  → 正常等待刷盘（含在途批次，偶发几秒是常态）；
 *                                同一行持续如此才是 flusher 卡住
 *   热层比库新 + **不在**脏集合 → **刷盘丢失**。RedisDirtySet::swapOut() 用 SPOP 原子取走了
 *                                这批 key，之后 batchUpdate() 失败就再也没人管它，
 *                                要等下一次行情变动重新标脏。冷门交易对可能长期停在旧值
 *
 * ⚠️ 与 MarketMonitor 的分工：那一页**只读 Redis**、每 3 秒轮询，是常驻看板；
 * 本页**要查 MySQL**（不查就没法比对第 ④ 站），所以默认不自动刷新，按需点。
 */
class MarketPipeline extends Base
{
    /**
     * market_data 里按"数据组类型"划分的列。
     *
     * 之所以是列而不是行：ticker/标记价格/资金费率三类共用 market_data 一行，
     * 各自占一个时间戳列。热层 market:hot:md:{sk} 也是同一个 hash 的不同 field，
     * 所以"过滤数据组类型"实际就是选看哪一列。
     *
     * depth / trade **不在这张表里**（深度在 market:depth:pair_*），不适用本页的四站模型，故不列入。
     *
     * K 线曾以同样理由排除，2026-08-28 起重新纳入 —— 那次改动把 K 线缓冲从进程内内存搬到
     * Redis、落库收口到 MarketDbFlusher，于是它走的正是同一套四站。但它的库在
     * market_source_kline（独立表、一个 source_key 对应多行），塞不进"一行一列"的
     * GROUPS 模型，所以走独立的一路：见 KLINE_GROUP / buildKlineRows()。
     */
    /**
     * recv 埋点相对真实到帧时刻的固有延迟(秒)：各进程 stat 定时器的周期。
     * touchGroup() 只在内存累加，到点才由 flushGroups() 批量写 Redis。
     * 采集器与订阅方目前都是 10 秒。
     */
    private const METRIC_FLUSH_LAG_SEC = 10;

    private const GROUPS = [
        'ticker'     => ['ts' => 'update_ts',       'val' => ['close'],
                         'label' => '最新价 ticker', 'chan' => MarketChannelEnum::PRICE],
        'mark_price' => ['ts' => 'mark_ts',         'val' => ['mark_price'],
                         'label' => '标记价格',      'chan' => MarketChannelEnum::MARK_PRICE],
        // 费率不走 WS，由 market:funding 走 REST / inner_api 供数，没有收帧埋点，chan 为 null
        'funding'    => ['ts' => 'funding_rate_ts', 'val' => ['funding_rate', 'estimated_funding_rate'],
                         'label' => '资金费率',      'chan' => null],
    ];

    /** K 线组的筛选键与显示名。不在 GROUPS 里 —— 它的库不是 market_data，见 GROUPS 注释 */
    private const KLINE_GROUP = 'kline';
    private const KLINE_LABEL = 'K线 60s';

    /**
     * K 线诊断的基准周期(秒)。
     *
     * 只探这一个而不是全部 6 个：六个周期共用同一条落库通道（同一份缓冲、同一个 flusher），
     * 60s 更新最频繁、最先反映卡顿，它通了其余五个必然也通。全探则要对
     * market_source_kline 做 6 倍聚合查询，而这页本来就因为要查 MySQL 才不自动刷新。
     */
    private const KLINE_PROBE_PERIOD = 60;

    /** K 线缓冲的 tag。诊断只看 src（market_source_kline）—— 它是 pair/ctrl 两路的上游，先卡的一定是它 */
    private const KLINE_PROBE_TAG = 'src';

    /**
     * 诊断页
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '落库链路诊断';
        $this->groups = array_map(fn($g) => $g['label'], self::GROUPS)
            + [self::KLINE_GROUP => self::KLINE_LABEL];
        $this->staleSeconds = (int)\think\facade\Config::get('market.stale_seconds', 300);
        return $this->fetch();
    }

    /**
     * 表格数据（前端按需拉取）
     * @auth true
     */
    public function data()
    {
        $redis = redis_client();
        if (!$redis) $this->error('Redis 不可用');

        $keyword = trim((string)$this->request->get('keyword', ''));
        $group   = (string)$this->request->get('group', '');
        $onlyBad = (string)$this->request->get('only_bad', '') === '1';

        // K 线走独立的一路 (buildKlineRows)，先从 GROUPS 这一路摘出去。
        // ⚠️ 下面取 $cols / $rows 用的仍是 self::GROUPS 全集：market_data 是 source_key 的
        // 权威清单，筛到 K 线组时也要靠它拿到有哪些源，不能跟着 $groups 一起缩
        $wantKline = ($group === '' || $group === self::KLINE_GROUP);
        $groups = $group === self::KLINE_GROUP
            ? []
            : (isset(self::GROUPS[$group]) ? [$group => self::GROUPS[$group]] : self::GROUPS);

        // market_data 是这条链路的终点，也是 source_key 的权威清单
        $cols = array_column(self::GROUPS, 'ts');
        foreach (self::GROUPS as $g) $cols = array_merge($cols, $g['val']);
        $q = Db::name('market_data')->field('source_key,' . implode(',', array_unique($cols)));
        if ($keyword !== '') $q->whereLike('source_key', '%' . $keyword . '%');
        $rows = $q->limit(500)->select()->toArray();

        $sourceKeys = array_column($rows, 'source_key');
        $hot   = $this->readHot($redis, $sourceKeys);
        $dirty = $this->readDirty($redis);
        // 收帧看 collect（帧从交易所进来）。⚠️ 不能读 SCOPE_LAND —— land 正是本页第 ④ 站，
        // 同一个数被当成两个站来比，判定①会恒定自相矛盾
        $recv  = MarketMetrics::readGroups(MarketMetrics::SCOPE_COLLECT);
        // REST 兜底单独一路：update_ts 既是"陈旧"的判据、又是兜底要刷新的目标，
        // 共用同一个字段就让"WS 已断、全靠兜底续命"与"一切正常"长得一样。
        // 由 market:price_fallback 的 restFallbackSync* 写
        $fallback = MarketMetrics::readGroups(MarketMetrics::SCOPE_FALLBACK);

        $now   = (int)(microtime(true) * 1000);
        $stale = (int)\think\facade\Config::get('market.stale_seconds', 300) * 1000;
        // recv 埋点自带一层延迟：touchGroup() 只在内存累加，各进程的 stat 定时器
        // 每 METRIC_FLUSH_LAG_SEC 秒才由 flushGroups() 批量写进 Redis。
        // 不把它算进阈值的话，一个更新间隔本就接近 stale 的低频交易对
        // （现网 HUOBI_adausdt_1 就是）会被自己的埋点刷新周期判成"上游断了"。
        $recvStale = $stale + self::METRIC_FLUSH_LAG_SEC * 1000;

        $out = [];
        foreach ($rows as $row) {
            $sk = (string)$row['source_key'];
            foreach ($groups as $gk => $g) {
                $hotTs = isset($hot[$sk][$g['ts']]) ? (int)$hot[$sk][$g['ts']] : 0;
                $dbTs  = (int)($row[$g['ts']] ?? 0);
                if ($hotTs <= 0 && $dbTs <= 0) continue;   // 这一类从来没有过数据，不占行

                $recvTs = 0;
                $fbTs   = 0;
                if ($g['chan'] !== null) {
                    $field  = MarketMetrics::groupField($g['chan']->value, $sk);
                    $recvTs = (int)($recv[$field]['last_ts'] ?? 0);
                    $fbTs   = (int)($fallback[$field]['last_ts'] ?? 0);
                }
                $isDirty = isset($dirty[$sk]);

                $v = $this->judge($now, $stale, $recvStale, $recvTs, $hotTs, $dbTs, $isDirty, $g['chan'] !== null, $fbTs);
                if ($onlyBad && $v['level'] === 'ok') continue;

                $out[] = [
                    'source_key' => $sk,
                    'group'      => $g['label'],
                    'recv_at'    => $this->fmt($recvTs),
                    'recv_age'   => $this->age($now, $recvTs),
                    // 「这一路最后一次被 REST 兜底刷新是什么时候」——空 = 一直是 WS 在供
                    'fallback_at'  => $fbTs > 0 ? $this->fmt($fbTs) : '',
                    'fallback_age' => $fbTs > 0 ? $this->age($now, $fbTs) : '',
                    'hot_at'     => $this->fmt($hotTs),
                    'hot_age'    => $this->age($now, $hotTs),
                    'dirty'      => $isDirty,
                    'db_at'      => $this->fmt($dbTs),
                    'db_age'     => $this->age($now, $dbTs),
                    // 热层与库的差值 —— 「进了热层没入库」的直接度量
                    'lag_ms'     => ($hotTs > 0 && $dbTs > 0) ? max(0, $hotTs - $dbTs) : null,
                    // 值也要并排看：时间戳只说明"什么时候的数据"，值才说明"写没写对"。
                    // 值一致但时间戳不一致，说明只是 ts 列没跟上；值也不一致才是真没刷进去
                    'hot_val'    => $this->vals($hot[$sk] ?? [], $g['val']),
                    'db_val'     => $this->vals($row, $g['val']),
                    'val_diff'   => $this->vals($hot[$sk] ?? [], $g['val']) !== $this->vals($row, $g['val']),
                    'verdict'    => $v['text'],
                    'level'      => $v['level'],
                ];
            }
        }

        if ($wantKline) {
            foreach ($this->buildKlineRows($redis, $sourceKeys, $recv, $now, $stale, $recvStale) as $r) {
                if ($onlyBad && $r['level'] === 'ok') continue;
                $out[] = $r;
            }
        }

        // 有问题的排前面，其次按落后程度
        usort($out, function ($a, $b) {
            $rank = ['bad' => 0, 'warn' => 1, 'ok' => 2];
            return [$rank[$a['level']], -($a['lag_ms'] ?? 0)] <=> [$rank[$b['level']], -($b['lag_ms'] ?? 0)];
        });

        $this->success('ok', [
            'rows'    => $out,
            'flusher' => MarketMetrics::readWorker('market_relay_MarketDbFlusher'),
            'scope'   => MarketMetrics::SCOPE_COLLECT,
            'now'     => date('H:i:s'),
        ]);
    }

    /** 热层更新到现在不超过这么久(毫秒)就当在途批次：flusher 默认 1s 一轮、单轮最坏约 1.2s */
    private const IN_FLIGHT_GRACE_MS = 3000;

    /**
     * 判定卡在哪一站。
     *
     * 顺序**从后往前**：先确认数据到没到，再看写没写，最后才看刷没刷 ——
     * 反过来会把「压根没收到」误报成「刷盘慢」。
     */
    private function judge(int $now, int $stale, int $recvStale, int $recvTs, int $hotTs, int $dbTs, bool $isDirty, bool $hasRecv, int $fbTs = 0): array
    {
        // ⓪ WS 已断，靠 REST 兜底续命。
        //
        // 必须排在所有"新鲜度"判据**之前**：兜底刚刷过 update_ts，下面每一条看的都是
        // 被兜底刷新过的时间戳，于是整页绿灯 —— 而这恰恰是最该被看见的状态。
        // 判据是「谁在刷新它」而不是「它新不新鲜」：兜底埋点新 + 收帧埋点旧 = WS 断了。
        //
        // 后果不是"慢一点"：兜底只有当前价，open/high/low 一并置为当前价，
        // 没有深度、没有成交，频率还低一到两个数量级。K 线会长出一串平底。
        if ($fbTs > 0 && ($now - $fbTs) <= $recvStale
            && $hasRecv && $recvTs > 0 && ($now - $recvTs) > $recvStale) {
            return ['level' => 'bad', 'text' => sprintf(
                '⓪ WS 已断，正靠 REST 兜底续命 —— 时间戳是兜底刷新的，不代表行情在正常流入。'
                . '兜底只有当前价（OHLC 被置为同一个值）、没有深度与成交，频率低一到两个数量级。'
                . '最后一帧 WS 在 %s 前，查采集器与交易所连接',
                $this->age($now, $recvTs)
            )];
        }

        // ① 没收到帧。只有能拿到 recv 埋点的频道才判得了(费率已退出 WS 分发，见 GROUPS 注释)。
        // recvTs==0 不走这一支：那是"从来没有过埋点"，与"埋点旧了"是两回事 ——
        // 可能是指标被清过、也可能这一路从没启用过，不该当成"上游断了"
        if ($hasRecv && $recvTs > 0 && ($now - $recvTs) > $recvStale) {
            return ['level' => 'bad', 'text' => '① 没收到帧 —— 上游没送到，查分发链路'];
        }

        // ②③④ 热层与库不一致：数据到了热层，问题在落库这一段。
        // 已被 SPOP 取走、正在写库的那一批既不在脏集合也没进库，最长约一个刷盘周期 ——
        // 热层刚更新过就按在途算，否则页面一刷会随机撞上一批假红
        if ($hotTs > $dbTs) {
            $inFlight = ($now - $hotTs) <= self::IN_FLIGHT_GRACE_MS;
            return ($isDirty || $inFlight)
                ? ['level' => 'warn', 'text' => '③ 等待刷盘 —— 含已被取走正在写库的在途批次；现网单轮约 0.3s、最坏 1.2s、每 1.4s 一轮，所以偶发几秒是常态。**同一行持续如此**才说明 flusher 卡住，看 MarketDbFlusher 的 flush_fail/flush_ms_max']
                : ['level' => 'bad',  'text' => '④ 刷盘丢失 —— 已出脏集合却没写进库，写失败被吞了'];
        }

        // 热层与库一致（说明落库这一段没问题），但时间戳都旧了。
        //
        // ⚠️ 这里要非常小心口径：热层的 update_ts / mark_ts 存的是**交易所打在帧上的时钟**，
        // 不是"我们何时写的"。所以"热层陈旧"**不等于**"没写进热层" ——
        // 我们可能刚刚写过，只是帧上带的时间戳本来就是旧的。
        //
        // 真正能证明"我们处理过这一帧"的是 collect/land 埋点(本机时钟，在 handler 里打的)。
        // 所以：埋点新 + 帧上时间戳旧 = **收到了，但交易所给的时间戳没往前走**，
        // 这是上游的问题，不是我们没写。早先这里写成"没进热层，查 processFrame 异常"是错的，
        // 会把人引到完全错误的方向。
        if ($hotTs > 0 && ($now - $hotTs) > $stale) {
            return $hasRecv && $recvTs > 0 && ($now - $recvTs) <= $stale
                ? ['level' => 'warn', 'text' => '帧在收，但交易所时间戳没前进 —— 查采集器 age 指标与时钟偏差']
                : ['level' => 'warn', 'text' => '整条链路都没有新数据 —— 上游本来就没更新'];
        }

        return ['level' => 'ok', 'text' => '正常'];
    }

    /**
     * K 线那一路的诊断行。与 GROUPS 三组并排显示，但数据来源完全不同：
     *   ① 收帧   MarketMetrics 的 kline 埋点（field 带 period 后缀）
     *   ②③ 缓冲  market:kline:buf:src:* + 脏集合 market:kline:dirty:src
     *   ④ 库     market_source_kline 里该 source_key 在基准周期上的最新一根
     *
     * @param array $recv MarketMetrics::readGroups() 的结果，与主路复用同一份，不重复读 Redis
     */
    private function buildKlineRows($redis, array $sourceKeys, array $recv, int $now, int $stale, int $recvStale): array
    {
        if (empty($sourceKeys)) return [];

        // ④ 库：走 UNIQUE(source_key,period,ts)，GROUP BY 只扫索引不回表
        $dbTsMap = [];
        try {
            $dbTsMap = Db::name('market_source_kline')
                ->whereIn('source_key', $sourceKeys)
                ->where('period', self::KLINE_PROBE_PERIOD)
                ->group('source_key')
                ->column('MAX(ts)', 'source_key');
        } catch (\Throwable $e) {
        }

        $pending = $this->readKlinePending($redis);

        $out = [];
        foreach ($sourceKeys as $sk) {
            $dbTs  = (int)($dbTsMap[$sk] ?? 0);
            $bufTs = (int)($pending[$sk] ?? 0);
            if ($dbTs <= 0 && $bufTs <= 0) continue;   // 这个源从来没有过 K 线，不占行

            $recvTs = (int)($recv[MarketMetrics::groupField(
                MarketChannelEnum::KLINE->value, $sk, (string)self::KLINE_PROBE_PERIOD
            )]['last_ts'] ?? 0);

            $v = $this->judgeKline($now, $stale, $recvStale, $recvTs, $bufTs, $dbTs);

            $out[] = [
                'source_key' => $sk,
                'group'      => self::KLINE_LABEL,
                'recv_at'    => $this->fmt($recvTs),
                'recv_age'   => $this->age($now, $recvTs),
                // K 线没有 REST 兜底埋点：补数走 market:kline_backfill，兜底埋点只有 market:price_fallback 打（price/mark_price）
                'fallback_at'  => '',
                'fallback_age' => '',
                'hot_at'     => $this->fmt($bufTs),
                'hot_age'    => $this->age($now, $bufTs),
                'dirty'      => $bufTs > 0,
                'db_at'      => $this->fmt($dbTs),
                'db_age'     => $this->age($now, $dbTs),
                'lag_ms'     => ($bufTs > 0 && $dbTs > 0) ? max(0, $bufTs - $dbTs) : null,
                // 值对比这一列对 K 线不适用：一个 source_key 对应多根蜡烛，没有单值可比。
                // 真要比得连 open/high/low/close 一起比，那是另一个页面的事
                'hot_val'    => '-',
                'db_val'     => '-',
                'val_diff'   => false,
                'verdict'    => $v['text'],
                'level'      => $v['level'],
            ];
        }
        return $out;
    }

    /**
     * K 线缓冲里每个 source_key 待落库的最新 ts。
     *
     * 只读脏集合、不逐个探 buf hash：两者是同一次 MULTI 写进去的
     * （见 MarketService::stageKline），脏集合有就是缓冲有。
     *
     * 成员形如 "{source_key}:{period}:{ts}"，而 source_key 自身**不含冒号**
     * （MarketHelper::makeSourceKey 用下划线拼），所以从右边取两段就是 period 与 ts。
     *
     * @return array<string,int> source_key => 最新待落库 ts
     */
    private function readKlinePending($redis): array
    {
        $out = [];
        try {
            $dirtyKey = MarketService::klineDirtyKey(self::KLINE_PROBE_TAG);
            $members  = $redis->sMembers($dirtyKey) ?: [];

            foreach ($members as $m) {
                $parts = explode(':', (string)$m);
                if (count($parts) < 3) continue;
                $ts     = (int)array_pop($parts);
                $period = (int)array_pop($parts);
                if ($period !== self::KLINE_PROBE_PERIOD) continue;
                $sk = implode(':', $parts);
                if ($ts > ($out[$sk] ?? 0)) $out[$sk] = $ts;
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * K 线专用判定。**不能照抄 judge()** —— 两者的"热层"语义相反：
     *
     *   market_data 的热层是常驻快照，写进去就一直在，所以
     *     「热层比库新 + 不在脏集合」= 刷盘丢失（判定④）
     *   K 线的缓冲是**取走即删**（drainKlineBuffer 在一个 MULTI 里 HGETALL+DEL），
     *     落库成功后缓冲本来就该是空的，「空」是正常态而非异常
     *
     * 所以这里把判据换成「库里最新一根离现在多远」，缓冲只用来区分「正在等刷盘」与「真卡住」。
     */
    private function judgeKline(int $now, int $stale, int $recvStale, int $recvTs, int $bufTs, int $dbTs): array
    {
        // ① 没收到帧。同 judge()：recvTs==0 是「从来没有过埋点」，不算断流
        if ($recvTs > 0 && ($now - $recvTs) > $recvStale) {
            return ['level' => 'bad', 'text' => '① 没收到 K 线帧 —— 上游没送到，查采集器/分发链路'];
        }

        // ③ 缓冲里有比库更新的一根，正在等刷盘
        if ($bufTs > $dbTs) {
            return ['level' => 'warn', 'text' =>
                '③ 等待刷盘 —— 已进缓冲（含在途批次），等 MarketDbFlusher 写入。偶发是常态，'
                . '**同一行持续如此**才说明卡住，看 flusher 卡片的 kline_pending / kline_ms_max'];
        }

        // ④ 库没前进，且缓冲里也没有待落库的。
        // 阈值多容忍一根：ts 是**周期起点**，当前这根还没收盘，天然就落后一个周期
        $tolerance = $stale + self::KLINE_PROBE_PERIOD * 1000;
        if ($dbTs > 0 && ($now - $dbTs) > $tolerance) {
            return $recvTs > 0 && ($now - $recvTs) <= $recvStale
                ? ['level' => 'bad', 'text' =>
                    '④ 帧在收但库没前进，缓冲里也没有待落库的 —— 查 MarketDbFlusher 是否在跑'
                    . '（kline_rows_10s 恒 0 就是没跑），以及日志里的「K线缓冲写入失败/换出失败」']
                : ['level' => 'warn', 'text' => '整条链路都没有新 K 线 —— 上游本来就没更新'];
        }

        return ['level' => 'ok', 'text' => '正常'];
    }

    /** @return array<string,array> source_key => hash */
    private function readHot($redis, array $sourceKeys): array
    {
        if (empty($sourceKeys)) return [];
        $out = [];
        try {
            // 管道批量取，避免 N 次往返把这个页面自己变成负载源
            foreach (array_chunk($sourceKeys, 200) as $chunk) {
                $pipe = $redis->multi(\Redis::PIPELINE);
                foreach ($chunk as $sk) $pipe->hGetAll(MarketService::HOT_MD_PREFIX . $sk);
                $res = $pipe->exec();
                foreach ($chunk as $i => $sk) {
                    if (is_array($res[$i] ?? null)) $out[$sk] = $res[$i];
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /** @return array<string,true> 脏集合成员，用 SMEMBERS 一次取回而不是逐个 SISMEMBER */
    private function readDirty($redis): array
    {
        try {
            // 已被 SPOP 取走、正在写库的那一批此刻既不在脏集合也没进库，最长约一个刷盘周期；
            // 判定④对这个窗口的容忍见 judge() 的 IN_FLIGHT_GRACE_MS
            $members = $redis->sMembers(MarketService::DIRTY_MD_KEY) ?: [];
            return array_fill_keys($members, true);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 把一组字段拼成可比对的字符串。
     * 用字符串比而不是数值比：热层是 Redis 里的字符串、库里是 decimal，
     * 数值相等但精度表示不同(如 "1.10" vs "1.1000000000")时，我们要的恰恰是**看得见**这个差异，
     * 而不是把它抹平 —— 抹平了就没法判断到底是没写还是写了但格式不同。
     */
    private function vals(array $src, array $fields): string
    {
        $parts = [];
        foreach ($fields as $f) {
            $parts[] = ($src[$f] ?? '') === '' ? '-' : (string)$src[$f];
        }
        return implode(' / ', $parts);
    }

    /**
     * 某个 source_key 的**热层原始 hash**。
     *
     * 上面那张表是按数据组切片看的；这里给的是热层里到底存了什么、一个字段不漏。
     * 判断「没进热层」时特别有用 —— 字段压根不存在，和字段存在但值旧，是两回事。
     * @auth true
     */
    public function hot()
    {
        $sk = trim((string)$this->request->get('source_key', ''));
        if ($sk === '') $this->error('缺少 source_key');

        $redis = redis_client();
        if (!$redis) $this->error('Redis 不可用');

        try {
            $hash = $redis->hGetAll(MarketService::HOT_MD_PREFIX . $sk) ?: [];
            $dirty = (bool)$redis->sIsMember(MarketService::DIRTY_MD_KEY, $sk);
        } catch (\Throwable $e) {
            $this->error('读取失败：' . $e->getMessage());
            return;
        }

        // 时间戳字段顺手转成可读时间，省得人工换算毫秒
        $rows = [];
        foreach ($hash as $k => $v) {
            $rows[] = [
                'field' => (string)$k,
                'value' => (string)$v,
                'time'  => (str_ends_with((string)$k, '_ts') && is_numeric($v) && (int)$v > 0)
                    ? date('Y-m-d H:i:s', intdiv((int)$v, 1000)) : '',
            ];
        }
        usort($rows, fn($a, $b) => $a['field'] <=> $b['field']);

        $this->success('ok', ['source_key' => $sk, 'exists' => !empty($hash), 'dirty' => $dirty, 'rows' => $rows]);
    }

    private function fmt(int $ms): string
    {
        return $ms > 0 ? date('H:i:s', intdiv($ms, 1000)) : '-';
    }

    private function age(int $now, int $ms): ?float
    {
        return $ms > 0 ? round(($now - $ms) / 1000, 1) : null;
    }
}
