<?php

declare(strict_types=1);

namespace app\business\controller;

use app\command\MarketDispatcher;
use app\enum\MarketDataTypeEnum;
use app\lib\CommandHeartBeatService;
use app\lib\MarketMetrics;
use app\lib\WorkerHealth;

/**
 * 分发监控
 *
 * 看分发机（market:dispatch，现货 / 合约各一个进程）和连在上面的每一个订阅方：
 * 进程吞吐与流量构成、每条连接的发送缓冲 / depth 档位 / 降级次数、分发事件流水（降级、恢复、缓冲满、断开）。
 *
 * 数据来源：进程指标 market:metrics:worker:{workerName}（分发机每 10 秒写一次，buf_state 是每条连接的现场），
 * 分发事件 MarketMetrics::DISPATCH_EVENT_KEY。只读 Redis，不碰 MySQL。
 * 分发进程的健康判定也在本页（judgeHealth）：「行情链路监控」页已不再展示分发进程。
 * Go 分发机不在本页，归「Go 分发监控」（GoDispatchMonitor）。
 */
class DispatchMonitor extends Base
{
    /** 前端自动刷新间隔(秒) */
    private const REFRESH_SECONDS = 3;

    /** 告警条统计窗口(秒) */
    private const ALERT_WINDOW_SEC = 300;

    /** 事件表每次最多返回多少条（筛选后） */
    private const EVENT_ROWS = 200;

    /** 事件筛选里「只看异常」对应的事件 */
    private const PROBLEM_EVENTS = [
        'subscriber_degrade', 'subscriber_recover', 'buffer_full',
        'subscriber_lag_close', 'subscriber_hb_kick', 'subscriber_hello_invalid', 'subscriber_auth_reject',
    ];

    /** 分发事件 => [名称, 颜色 class]。事件全集，写入方 MarketDispatcher::connEvent() */
    private const EVENT_TYPES = [
        'subscriber_degrade'       => ['降级', 'color-orange'],
        'subscriber_recover'       => ['恢复', 'color-green'],
        'buffer_full'              => ['缓冲满', 'color-red'],
        'subscriber_lag_close'     => ['积压断开', 'color-red'],
        'subscriber_hb_kick'       => ['心跳超时', 'color-red'],
        'subscriber_hello_invalid' => ['非法 hello', 'color-orange'],
        'subscriber_auth_reject'   => ['鉴权拒绝', 'color-red'],
        'subscriber_connect'       => ['连上', ''],
        'subscriber_hello'         => ['握手', ''],
        'snapshot_replay'          => ['补快照', 'color-desc'],
        'subscriber_close'         => ['断开', 'color-desc'],
    ];

    /** 连接健康（MarketDispatcher::connHealth 的取值）=> [名称, 颜色 class, 排序：越小越靠前] */
    private const HEALTH = [
        'closing'     => ['断开中', 'color-red', 0],
        'lagging'     => ['积压中', 'color-red', 1],
        'no_hello'    => ['未握手', 'color-desc', 2],
        'handshaking' => ['补快照中', 'color-desc', 3],
        'degraded'    => ['已降级', 'color-orange', 4],
        'ok'          => ['正常', 'color-green', 5],
    ];

    /** 降级事件的触发原因（MarketDispatcher::stepDegrade 的 $reason）=> 文案 */
    private const DEGRADE_REASONS = [
        'soft'    => '缓冲过软水位且还在涨',
        'full'    => '缓冲满',
        'recover' => '缓冲回落',
    ];

    /**
     * 分发监控
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '分发监控';
        $this->refresh = self::REFRESH_SECONDS;
        $this->eventTypes = array_map(fn($t) => $t[0], self::EVENT_TYPES);
        $this->marketTypes = MarketDataTypeEnum::labels();
        return $this->fetch();
    }

    /**
     * 面板数据（前端定时拉取）
     * @auth true
     */
    public function data()
    {
        $redis = redis_client();
        if (!$redis) $this->error('Redis 不可用，无法读取分发指标');

        $procs = [];
        $conns = [];
        foreach (MarketDataTypeEnum::labels() as $type => $label) {
            $type = (int)$type;
            $metrics = MarketMetrics::readWorker(MarketDispatcher::workerName($type));
            $alive = false;
            try {
                $alive = (bool)$redis->exists(CommandHeartBeatService::KEY_PREFIX . MarketDispatcher::workerName($type));
            } catch (\Throwable $e) {
            }
            $procs[] = $this->buildProc($type, $label, $metrics, $alive);
            foreach ($this->buildConns($label, $metrics) as $c) $conns[] = $c;
        }
        usort($conns, fn($a, $b) => [$a['rank'], -$a['buf']] <=> [$b['rank'], -$b['buf']]);

        $recent = MarketMetrics::readDispatchEvents(
            MarketMetrics::DISPATCH_EVENT_KEEP, (int)(microtime(true) * 1000) - self::ALERT_WINDOW_SEC * 1000
        );
        [$events, $nodes] = $this->buildEvents(
            (string)$this->request->get('event', ''),
            (string)$this->request->get('node', ''),
            (int)$this->request->get('market_type', 0)
        );

        $this->success('ok', [
            'procs'  => $procs,
            'conns'  => $conns,
            'alerts' => $this->buildAlerts($recent, $conns),
            'events' => $events,
            'nodes'  => $nodes,
            'now_at' => date('H:i:s'),
        ]);
    }

    /** 一个分发进程的摘要：健康判定、存活、吞吐、事件循环占用、10 秒计数、流量构成 */
    private function buildProc(int $type, string $label, array $m, bool $alive): array
    {
        $updateTs = (int)($m['update_ts'] ?? 0);
        $age = $updateTs > 0 ? round((microtime(true) * 1000 - $updateTs) / 1000, 1) : null;
        // 判定要在下面剥掉过期指标之前做：过期本身就是要报的结论
        $health = $this->judgeHealth($m, $alive, $age);
        if (!$m) {
            [$stateText, $stateColor] = ['未运行（从未上报指标）', 'color-red'];
        } elseif ($age !== null && $age > 60) {
            [$stateText, $stateColor] = [$alive ? "指标 {$age}s 未更新" : '未运行（心跳已过期）', 'color-red'];
            // 过期的指标是上一次运行留下的，吞吐与计数不再代表现在，不展示
            $m = ['sndbuf_limit' => $m['sndbuf_limit'] ?? 0, 'soft_buffer' => $m['soft_buffer'] ?? 0];
        } else {
            [$stateText, $stateColor] = $alive ? ['运行中', 'color-green'] : ['心跳已过期', 'color-orange'];
        }

        $limit = (int)($m['sndbuf_limit'] ?? 0);
        $soft = (int)($m['soft_buffer'] ?? 0);
        $counter = fn(string $text, int $n, string $badColor) => ['text' => $text, 'n' => $n, 'color' => $n > 0 ? $badColor : ''];
        $drop = (int)($m['send_false_10s'] ?? 0) + (int)($m['send_fail_10s'] ?? 0);
        // 阈值常量在 MarketMetrics，与分发机 stat 日志的 warning 线是同一组值
        $busyPct = isset($m['busy_pct']) ? (float)$m['busy_pct'] : null;
        $busyLevel = MarketMetrics::levelOf($busyPct, MarketMetrics::BUSY_CRIT_PCT, MarketMetrics::BUSY_WARN_PCT);

        return [
            'type'        => $type,
            'label'       => $label,
            'port'        => MarketDispatcher::portOf($type),
            'health_text'   => $health['text'],
            'health_reason' => $health['reason'],
            'health_badge'  => MarketMetrics::levelBadge($health['level']),
            'health_color'  => MarketMetrics::levelColor($health['level']),
            'state_text'  => $stateText,
            'state_color' => $stateColor,
            // 分桶明细跟在总数后面：只给总数说不出"忙在哪"，见 EventLoopBusyTrait
            'busy_text'   => $busyPct !== null ? $busyPct . ' %' : '-',
            'busy_color'  => $busyLevel === 'ok' ? '' : MarketMetrics::levelColor($busyLevel),
            'busy_detail' => trim((string)($m['busy_detail'] ?? '')),
            // 持续偏高说明分发机没跟上采集器的写入
            'dirty_text'  => isset($m['dirty_size']) ? sprintf('%d / %d', (int)$m['dirty_size'], (int)($m['snap_fields'] ?? 0)) : '-',
            'metric_age'  => $age,
            'uptime_text' => isset($m['uptime_sec']) ? $this->durationText((int)$m['uptime_sec']) : '-',
            'subscribers' => (int)($m['subscribers'] ?? 0),
            'rate_text'   => isset($m['msgs_10s']) ? sprintf('取出 %s 帧/s · 发出 %.1f 条/s · %s/s · tick 最长 %sms',
                $m['rate_fps'] ?? 0, (int)($m['msgs_10s'] ?? 0) / 10,
                format_bytes((int)round((int)($m['bytes_10s'] ?? 0) / 10)), $m['tick_max_ms'] ?? '-') : '-',
            'buffer_text' => $limit > 0 ? sprintf('上限 %s · 软水位 %s', format_bytes($limit), $soft > 0 ? format_bytes($soft) : '-') : '-',
            'counters'    => isset($m['msgs_10s']) ? [
                $counter('降档', (int)($m['degrade_down_10s'] ?? 0), 'color-orange'),
                $counter('升档', (int)($m['degrade_up_10s'] ?? 0), ''),
                $counter('缓冲满', (int)($m['buf_full_10s'] ?? 0), 'color-red'),
                $counter('跳过', (int)($m['skipped_10s'] ?? 0), 'color-orange'),
                $counter('丢弃', $drop, 'color-red'),
                $counter('积压断开', (int)($m['lag_close_10s'] ?? 0), 'color-red'),
                $counter('心跳超时', (int)($m['hb_kick_10s'] ?? 0), 'color-red'),
            ] : [],
            'kinds'       => $this->kindShares(json_decode((string)($m['kind_bytes_10s'] ?? ''), true) ?: [], 10),
        ];
    }

    /**
     * 分发进程的一句话健康判定：先过 WorkerHealth::common() 的通用三条，再判分发机自己的。
     * 判据按严重度排序，命中即返回。
     *
     * @return array{level:string, text:string, reason:string}
     */
    private function judgeHealth(array $m, bool $alive, ?float $metricAge): array
    {
        $common = WorkerHealth::common($m, $alive, $metricAge);
        if ($common !== null) return $common;

        // ── 已经在丢包 ───────────────────────────────────────────────
        $drop = (int)($m['send_false_10s'] ?? 0) + (int)($m['send_fail_10s'] ?? 0);
        if ($drop > 0) {
            return WorkerHealth::crit("最近 10 秒丢弃 {$drop} 帧（发送缓冲已满）。先看订阅方事件循环占用；不高的话多半是出口带宽不够");
        }

        // ── 即将丢包：缓冲持续增长且从不回落 ─────────────────────────
        // 这是最有价值的一条 —— 它比 send_false 早几十秒。
        // 缓冲从 0 涨到上限通常要几十秒，这期间单看某一次采样是看不出问题的，必须看趋势
        $hist = json_decode((string)($m['sndbuf_history'] ?? '[]'), true);
        if (is_array($hist) && count($hist) >= 4 && $this->isRising($hist)) {
            $from = format_bytes((int)$hist[0]);
            $to   = format_bytes((int)end($hist));
            return WorkerHealth::crit("发送缓冲持续增长且从不回落（{$from} → {$to}），照此下去必然丢包。说明发送速率长期高于订阅方+链路的承载能力");
        }

        $sndbufPct = $this->sndbufPercent($m);
        if ($sndbufPct !== null && $sndbufPct > MarketMetrics::SNDBUF_CRIT_PCT) {
            return WorkerHealth::warn("发送缓冲已占用 {$sndbufPct}%，接近上限");
        }

        $skipped = (int)($m['skipped_10s'] ?? 0);
        if ($skipped > 0) {
            return WorkerHealth::warn("最近 10 秒有 {$skipped} 次因订阅方积压而主动跳过发送");
        }

        $subs = (int)($m['subscribers'] ?? 0);
        if ($subs === 0) {
            return WorkerHealth::info('当前没有订阅方连接（数据仍在正常排空，不会堆积）');
        }

        $rate = (float)($m['rate_fps'] ?? 0);
        if ($rate <= 0) {
            return WorkerHealth::warn('进程在跑但分发速率为 0，确认采集器是否在写入');
        }

        return WorkerHealth::ok("{$subs} 个订阅方，{$rate} 帧/s，无丢包");
    }

    /**
     * 判断一串采样是否"持续增长且从不明显回落"。
     *
     * 不用严格单调递增：真实曲线总有抖动，偶尔小幅回落是正常的。
     * 判据是「末值明显高于首值」且「中途没有出现过明显回落」——
     * 一旦缓冲被排空过（哪怕一次），就说明消费端跟得上，不该报警。
     */
    private function isRising(array $samples): bool
    {
        $n = count($samples);
        $first = (float)$samples[0];
        $last  = (float)$samples[$n - 1];

        // 末值必须比首值高出一个有意义的量（256KB），避免在低位小幅波动时误报
        if ($last <= $first || ($last - $first) < 262144) return false;

        $peak = 0.0;
        foreach ($samples as $v) {
            $v = (float)$v;
            // 曾经从峰值掉下来 30% 以上 => 缓冲被排空过 => 消费端跟得上
            if ($peak > 0 && $v < $peak * 0.7) return false;
            $peak = max($peak, $v);
        }
        return true;
    }

    /** 发送缓冲峰值占上限的百分比 —— 判断"有人跟不上"最直观的一个数；没有上限返回 null */
    private function sndbufPercent(array $m): ?float
    {
        $max = (int)($m['sndbuf_limit'] ?? 0);
        if ($max <= 0) return null;
        return round(((int)($m['sndbuf_max'] ?? 0)) / $max * 100, 1);
    }

    /** 某个分发进程上的每条连接，字段已映射成展示用的文案与颜色 */
    private function buildConns(string $label, array $m): array
    {
        $state = json_decode((string)($m['buf_state'] ?? ''), true);
        if (!is_array($state)) return [];

        $limit = (int)($m['sndbuf_limit'] ?? 0);
        $soft = (int)($m['soft_buffer'] ?? 0);
        $out = [];
        foreach ($state as $id => $c) {
            [$hText, $hColor, $rank] = self::HEALTH[$c['health'] ?? ''] ?? [(string)($c['health'] ?? '-'), '', 9];
            $buf = (int)($c['buf'] ?? 0);
            $peak = (int)($c['buf_peak'] ?? 0);
            $depthMs = (int)($c['depth_ms'] ?? 0);

            $degrade = [];
            if ($depthMs > 0) $degrade[] = sprintf('depth 降到 %dms，已 %s', $depthMs, $this->durationText((int)($c['degraded_sec'] ?? 0)));
            if ((int)($c['degrade_n'] ?? 0) > 0) $degrade[] = sprintf('本连接降档 %d 次', (int)$c['degrade_n']);

            $extra = [];
            if ((int)($c['drop_10s'] ?? 0) > 0) $extra[] = '丢弃 ' . (int)$c['drop_10s'] . ' 条/10s';
            if ((int)($c['held'] ?? 0) > 0) $extra[] = '已积压 ' . (int)$c['held'] . 's';
            if ((int)($c['full'] ?? 0) > 0) $extra[] = '缓冲满 ' . (int)$c['full'] . ' 次';

            $out[] = [
                'market_label' => $label,
                'conn'        => (int)$id,
                'node'        => ($c['node'] ?? '') !== '' ? (string)$c['node'] : '?',
                'sub'         => (string)($c['sub'] ?? ''),
                'ip'          => (string)($c['ip'] ?? ''),
                'alive_text'  => isset($c['alive_sec']) ? $this->durationText((int)$c['alive_sec']) : '-',
                'health_text' => $hText,
                'health_color' => $hColor,
                'rank'        => $rank,
                // describeStreams()：'all' = 不传 streams；超过 8 个时是「N streams」
                'streams'     => ($c['streams'] ?? '') === 'all' ? '全部' : (string)($c['streams'] ?? ''),
                'degraded'    => $depthMs > 0,
                'degrade_text' => $degrade ? implode('；', $degrade) : '',
                'degrade_color' => $depthMs > 0 ? 'color-orange' : 'color-desc',
                'buf'         => $buf,
                'buf_text'    => format_bytes($buf),
                'buf_peak_text' => format_bytes($peak),
                'buf_pct'     => $limit > 0 ? min(100, round($peak / $limit * 100, 1)) : 0,
                'buf_color'   => $limit > 0 && $peak >= $limit ? 'layui-bg-red'
                                 : ($soft > 0 && $peak >= $soft ? 'layui-bg-orange' : 'layui-bg-green'),
                'rate_text'   => sprintf('%.1f 条/s · %s/s', (float)($c['msgs_ps'] ?? 0), format_bytes((int)($c['bytes_ps'] ?? 0))),
                'kinds'       => $this->kindShares((array)($c['kinds'] ?? []), 1),
                'rtt_text'    => ($c['rtt_avg'] ?? null) !== null ? sprintf('%s / %sms', $c['rtt_avg'], $c['rtt_max']) : '-',
                'extra_text'  => implode('；', $extra),
            ];
        }
        return $out;
    }

    /**
     * 流量构成：stream 类别 => 字节，按占比从大到小
     *
     * @param int $perSec 字节数对应多少秒（进程计数是 10 秒窗口，连接的已折算成每秒）
     * @return array<int, array{kind:string, rate_text:string, pct:float}>
     */
    private function kindShares(array $bytesByKind, int $perSec): array
    {
        $total = array_sum(array_map('intval', $bytesByKind));
        if ($total <= 0) return [];
        arsort($bytesByKind);
        $out = [];
        foreach ($bytesByKind as $kind => $bytes) {
            $out[] = [
                'kind'      => (string)$kind,
                'rate_text' => format_bytes((int)round((int)$bytes / $perSec)) . '/s',
                'pct'       => round((int)$bytes / $total * 100, 1),
            ];
        }
        return $out;
    }

    /** 告警条：最近 ALERT_WINDOW_SEC 秒的断开 / 降级，加上当前处于降级或积压的连接 */
    private function buildAlerts(array $recent, array $conns): array
    {
        $count = [];
        $nodes = [];
        foreach ($recent as $e) {
            $t = (string)($e['type'] ?? '');
            $count[$t] = ($count[$t] ?? 0) + 1;
            $nodes[$t][($e['node'] ?? '') !== '' ? $e['node'] : '?'] = true;
        }
        $nodeText = fn(string $t) => isset($nodes[$t]) ? '（机器 ' . implode('、', array_keys($nodes[$t])) . '）' : '';
        $min = intdiv(self::ALERT_WINDOW_SEC, 60);

        $alerts = [];
        foreach (['subscriber_lag_close' => '次积压断开', 'subscriber_hb_kick' => '次心跳超时', 'buffer_full' => '次缓冲满'] as $t => $what) {
            if (!empty($count[$t])) {
                $alerts[] = ['color' => 'color-red', 'text' => "最近 {$min} 分钟 {$count[$t]} {$what}" . $nodeText($t)];
            }
        }
        $degradedNow = count(array_filter($conns, fn($c) => $c['degraded']));
        if (!empty($count['subscriber_degrade']) || $degradedNow > 0) {
            $alerts[] = ['color' => 'color-orange', 'text' => sprintf(
                '最近 %d 分钟降档 %d 次%s；当前 %d 条连接处于降级',
                $min, $count['subscriber_degrade'] ?? 0, $nodeText('subscriber_degrade'), $degradedNow
            )];
        }
        if (!$alerts) {
            $alerts[] = ['color' => 'color-green', 'text' => "最近 {$min} 分钟没有降级、缓冲满、积压断开或心跳超时"];
        }
        return $alerts;
    }

    /**
     * 分发事件表
     *
     * @param string $event      事件名；'' = 全部，'problem' = PROBLEM_EVENTS
     * @param string $node       订阅方机器名；'' = 全部
     * @param int    $marketType MarketDataTypeEnum 的值；0 = 全部
     * @return array{0: array, 1: string[]} [事件行, 流水里出现过的机器名]
     */
    private function buildEvents(string $event, string $node, int $marketType): array
    {
        $labels = MarketDataTypeEnum::labels();
        $rows = [];
        $nodes = [];
        foreach (MarketMetrics::readDispatchEvents() as $e) {
            $type = (string)($e['type'] ?? '');
            $n = ($e['node'] ?? '') !== '' ? (string)$e['node'] : '?';
            $nodes[$n] = true;

            if ($event === 'problem' ? !in_array($type, self::PROBLEM_EVENTS, true) : ($event !== '' && $type !== $event)) continue;
            if ($node !== '' && $n !== $node) continue;
            if ($marketType > 0 && (int)($e['market_type'] ?? 0) !== $marketType) continue;
            if (count($rows) >= self::EVENT_ROWS) continue;

            [$typeText, $typeColor] = self::EVENT_TYPES[$type] ?? [$type, ''];
            $ts = (int)($e['ts'] ?? 0);
            $rows[] = [
                'at'           => date('m-d H:i:s', intdiv($ts, 1000)) . sprintf('.%03d', $ts % 1000),
                'type_text'    => $typeText,
                'type_color'   => $typeColor,
                'market_label' => $labels[(int)($e['market_type'] ?? 0)] ?? '-',
                'node'         => $n,
                'sub'          => (string)($e['sub'] ?? ''),
                'conn'         => (int)($e['conn'] ?? 0),
                'ip'           => (string)($e['ip'] ?? ''),
                'detail'       => $this->eventDetail($type, $e),
            ];
        }
        $nodes = array_keys($nodes);
        sort($nodes);
        return [$rows, $nodes];
    }

    /** 事件详情文案。字段由 MarketDispatcher 写入，见各 connEvent() 调用处 */
    private function eventDetail(string $type, array $e): string
    {
        $bytes = fn(string $k) => format_bytes((int)($e[$k] ?? 0));
        return match ($type) {
            'subscriber_degrade', 'subscriber_recover' => sprintf(
                '%s：depth %s → %s，当时缓冲 %s%s',
                self::DEGRADE_REASONS[$e['reason'] ?? ''] ?? (string)($e['reason'] ?? ''),
                $this->floorText((int)($e['from_ms'] ?? 0)), $this->floorText((int)($e['depth_ms'] ?? 0)), $bytes('buf'),
                isset($e['degraded_sec']) ? '，这一轮降级持续 ' . $this->durationText((int)$e['degraded_sec']) : ''
            ),
            'buffer_full'          => sprintf('缓冲 %s / 上限 %s', $bytes('buf'), $bytes('max')),
            'subscriber_lag_close' => sprintf('缓冲满持续 %ss 被断开，期间跳过 %d 帧，断开时缓冲 %s',
                $e['held'] ?? '-', (int)($e['skipped'] ?? 0), $bytes('buf')),
            'subscriber_hb_kick'   => sprintf('%d 秒没回 pong', (int)($e['idle'] ?? 0)),
            'subscriber_hello_invalid' => sprintf('%s（%d 字节）', $e['error'] ?? '', (int)($e['bytes'] ?? 0)),
            'subscriber_auth_reject' => (string)($e['reason'] ?? ''),
            'subscriber_connect'   => sprintf('连上后共 %d 条连接', (int)($e['total'] ?? 0)),
            'subscriber_hello'     => '订阅 ' . (($e['streams'] ?? '') === 'all' ? '全部' : ($e['streams'] ?? '')),
            'snapshot_replay'      => ($e['asked'] ?? '') === 'all'
                ? sprintf('全量订阅，补发 %d 个 stream', (int)($e['fields'] ?? 0))
                : sprintf('补发 %d / %s 个 stream', (int)($e['fields'] ?? 0), $e['asked'] ?? '-'),
            'subscriber_close'     => sprintf('连接了 %s，期间降档 %d 次，剩余 %d 条连接',
                $this->durationText((int)($e['alive_sec'] ?? 0)), (int)($e['degrade_n'] ?? 0), (int)($e['remaining'] ?? 0)),
            default                => json_encode($e, JSON_UNESCAPED_UNICODE),
        };
    }

    /** depth 降级下限的文案：0 = 订阅时选的间隔 */
    private function floorText(int $ms): string
    {
        return $ms > 0 ? "{$ms}ms" : '订阅档';
    }

    /** 秒 -> "3天2小时" / "45分" / "12秒"，只保留两个量级 */
    private function durationText(int $sec): string
    {
        if ($sec < 60) return "{$sec}秒";
        $m = intdiv($sec, 60);
        if ($m < 60) return $sec % 60 > 0 && $m < 10 ? "{$m}分" . ($sec % 60) . '秒' : "{$m}分";
        $h = intdiv($m, 60);
        $m %= 60;
        if ($h < 24) return $m > 0 ? "{$h}小时{$m}分" : "{$h}小时";
        $d = intdiv($h, 24);
        $h %= 24;
        return $h > 0 ? "{$d}天{$h}小时" : "{$d}天";
    }
}
