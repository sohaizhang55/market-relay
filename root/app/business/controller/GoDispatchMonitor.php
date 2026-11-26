<?php

declare(strict_types=1);

namespace app\business\controller;

use app\command\MarketDispatcher;
use app\enum\MarketDataTypeEnum;
use app\lib\CommandHeartBeatService;
use app\lib\MarketMetrics;
use app\lib\MarketSnapshotStore;
use app\lib\MarketWorkers;

/**
 * Go 分发监控
 *
 * 看并行试跑的 Go 分发机（仓库 go/ 目录，现货 / 合约各一个进程）和连在上面的每一个订阅方：
 * 进程吞吐与流量构成、每条连接的待发表 / 合并帧数 / 往返时延、分发事件流水（连上、握手、断开、积压断开）。
 *
 * 与「分发监控」（PHP 的 market:dispatch，DispatchMonitor）分成两页，因为两边的背压机制不同：
 * PHP 靠 Workerman 发送缓冲 + depth 降级，Go 靠每条连接的待发表 —— depth / ticker / markPrice
 * 在待发表里只留最新一帧（旧帧被「合并」掉不发），trade / kline 排队，没有降级。
 * Go 正式接管或放弃试跑时，删掉其中一页即可，不用拆分支。
 *
 * 数据来源（写入方 go/internal/dispatch/report.go，键名沿用 MarketMetrics 的约定）：
 *   进程指标 market:metrics:worker:{PHP worker name}_Go，每 10 秒写一次，buf_state 是每条连接的现场
 *   分发事件 MarketMetrics::DISPATCH_EVENT_GO_KEY
 * 只读 Redis，不碰 MySQL。
 */
class GoDispatchMonitor extends Base
{
    /** 前端自动刷新间隔(秒) */
    private const REFRESH_SECONDS = 3;

    /** 告警条统计窗口(秒) */
    private const ALERT_WINDOW_SEC = 300;

    /** 事件表每次最多返回多少条（筛选后） */
    private const EVENT_ROWS = 200;

    /** 事件筛选里「只看异常」对应的事件 */
    private const PROBLEM_EVENTS = ['subscriber_lag_close', 'subscriber_hb_kick', 'subscriber_hello_invalid', 'subscriber_auth_reject'];

    /** 分发事件 => [名称, 颜色 class]。事件全集，写入方 report.go 的 Server.event() 各调用处 */
    private const EVENT_TYPES = [
        'subscriber_lag_close'     => ['积压断开', 'color-red'],
        'subscriber_hb_kick'       => ['心跳超时', 'color-red'],
        'subscriber_hello_invalid' => ['非法 hello', 'color-orange'],
        'subscriber_auth_reject'   => ['鉴权拒绝', 'color-red'],
        'subscriber_connect'       => ['连上', ''],
        'subscriber_hello'         => ['握手', ''],
        'snapshot_replay'          => ['补快照', 'color-desc'],
        'subscriber_close'         => ['断开', 'color-desc'],
    ];

    /** 连接健康（report.go health() 的取值）=> [名称, 颜色 class, 排序：越小越靠前] */
    private const HEALTH = [
        'closing'  => ['断开中', 'color-red', 0],
        'lagging'  => ['积压中', 'color-red', 1],
        'no_hello' => ['未握手', 'color-desc', 2],
        'ok'       => ['正常', 'color-green', 3],
    ];

    /**
     * Go 分发监控
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'Go 分发监控';
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
            $name = self::workerName($type);
            $metrics = MarketMetrics::readWorker($name);
            $alive = false;
            try {
                $alive = (bool)$redis->exists(CommandHeartBeatService::KEY_PREFIX . $name);
            } catch (\Throwable $e) {
            }
            $procs[] = $this->buildProc($label, $metrics, $alive);
            foreach ($this->buildConns($label, $metrics) as $c) $conns[] = $c;
        }
        usort($conns, fn($a, $b) => [$a['rank'], -$a['pending']] <=> [$b['rank'], -$b['pending']]);

        $recent = MarketMetrics::readDispatchEvents(
            MarketMetrics::DISPATCH_EVENT_KEEP, (int)(microtime(true) * 1000) - self::ALERT_WINDOW_SEC * 1000,
            MarketMetrics::DISPATCH_EVENT_GO_KEY
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

    /** 某类 pair 的 Go 分发进程 worker name：PHP 的名字加 MarketWorkers::GO_SHADOW_SUFFIX（go/main.go 里同样拼） */
    private static function workerName(int $type): string
    {
        return MarketDispatcher::workerName($type) . MarketWorkers::GO_SHADOW_SUFFIX;
    }

    /** 一个 Go 分发进程的摘要：存活、吞吐、10 秒计数、运行时、流量构成 */
    private function buildProc(string $label, array $m, bool $alive): array
    {
        $updateTs = (int)($m['update_ts'] ?? 0);
        $age = $updateTs > 0 ? round((microtime(true) * 1000 - $updateTs) / 1000, 1) : null;
        if (!$m) {
            [$stateText, $stateColor] = ['未运行（从未上报指标）', 'color-red'];
        } elseif ($age !== null && $age > 60) {
            [$stateText, $stateColor] = [$alive ? "指标 {$age}s 未更新" : '未运行（心跳已过期）', 'color-red'];
            // 过期的指标是上一次运行留下的，吞吐与计数不再代表现在，不展示；配置类的字段留着
            $m = array_intersect_key($m, array_flip(['listen', 'pending_limit', 'dirty_key']));
        } else {
            [$stateText, $stateColor] = $alive ? ['运行中', 'color-green'] : ['心跳已过期', 'color-orange'];
        }

        // 端口配在 go/.env 里，PHP 不知道，取进程上报的监听地址
        $listen = (string)($m['listen'] ?? '');
        $shadow = str_contains((string)($m['dirty_key'] ?? ''), MarketSnapshotStore::SHADOW_DIRTY_KEY_PREFIX);
        $counter = fn(string $text, string $key, string $badColor) => [
            'text' => $text, 'n' => (int)($m[$key] ?? 0), 'color' => (int)($m[$key] ?? 0) > 0 ? $badColor : '',
        ];

        return [
            'label'       => $label,
            'listen'      => $listen !== '' ? $listen : '-',
            'state_text'  => $stateText,
            'state_color' => $stateColor,
            'metric_age'  => $age,
            'uptime_text' => isset($m['uptime_sec']) ? $this->durationText((int)$m['uptime_sec']) : '-',
            'subscribers' => (int)($m['subscribers'] ?? 0),
            'rate_text'   => isset($m['msgs_10s']) ? sprintf('取出 %s 帧/s · 发出 %.1f 条/s · %s/s · tick 最长 %sms',
                $m['rate_fps'] ?? 0, (int)$m['msgs_10s'] / 10,
                format_bytes((int)round((int)($m['bytes_10s'] ?? 0) / 10)), $m['tick_max_ms'] ?? '-') : '-',
            'source_text' => isset($m['dirty_key']) ? ($shadow ? '影子脏集合（与 PHP 分发并行）' : '正式脏集合（PHP 分发应已停）') : '-',
            'pending_text' => isset($m['pending_limit']) ? sprintf('每条连接上限 %d 条，超过即断开', (int)$m['pending_limit']) : '-',
            'runtime_text' => isset($m['goroutines']) ? sprintf('goroutine %d · 内存 %s（峰值 %s）',
                (int)$m['goroutines'], format_bytes((int)($m['mem'] ?? 0)), format_bytes((int)($m['mem_peak'] ?? 0))) : '-',
            'counters'    => isset($m['msgs_10s']) ? [
                // 合并是订阅方比更新慢时的正常现象，不算异常，不标色
                $counter('合并', 'merged_10s', ''),
                $counter('积压断开', 'lag_close_10s', 'color-red'),
                $counter('心跳超时', 'hb_kick_10s', 'color-red'),
                $counter('事件丢弃', 'event_drop_10s', 'color-orange'),
            ] : [],
            'kinds'       => $this->kindShares(json_decode((string)($m['kind_bytes_10s'] ?? ''), true) ?: [], 10),
        ];
    }

    /**
     * 某个 Go 分发进程上的每条连接，字段已映射成展示用的文案与颜色。
     * 原始字段见 go/internal/dispatch/report.go 的 connState
     */
    private function buildConns(string $label, array $m): array
    {
        $state = json_decode((string)($m['buf_state'] ?? ''), true);
        if (!is_array($state)) return [];

        $limit = (int)($m['pending_limit'] ?? 0);
        $out = [];
        foreach ($state as $id => $c) {
            [$hText, $hColor, $rank] = self::HEALTH[$c['health'] ?? ''] ?? [(string)($c['health'] ?? '-'), '', 9];
            $pending = (int)($c['pending'] ?? 0);
            $peak = (int)($c['pending_peak'] ?? 0);
            $held = (int)($c['held'] ?? 0);

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
                'pending'     => $pending,
                'pending_text' => "{$pending} / {$peak} 条",
                'pending_pct' => $limit > 0 ? min(100, round($peak / $limit * 100, 1)) : 0,
                // 过半标橙：覆盖类 stream 每个最多占一条，能涨到这么多只可能是 trade / kline 在排队
                'pending_color' => $held > 0 || ($limit > 0 && $peak >= $limit) ? 'layui-bg-red'
                                   : ($limit > 0 && $peak * 2 >= $limit ? 'layui-bg-orange' : 'layui-bg-green'),
                'merged'      => (int)($c['merged_10s'] ?? 0),
                'rate_text'   => sprintf('%.1f 条/s · %s/s', (float)($c['msgs_ps'] ?? 0), format_bytes((int)($c['bytes_ps'] ?? 0))),
                'kinds'       => $this->kindShares((array)($c['kinds'] ?? []), 1),
                'rtt_text'    => ($c['rtt_avg'] ?? null) !== null ? sprintf('%s / %sms', $c['rtt_avg'], $c['rtt_max']) : '-',
                'lagging'     => $held > 0,
                'extra_text'  => $held > 0 ? "一次写已阻塞 {$held}s" : '',
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

    /** 告警条：最近 ALERT_WINDOW_SEC 秒的积压断开 / 心跳超时，加上当前处于积压的连接 */
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
        foreach (['subscriber_lag_close' => '次积压断开', 'subscriber_hb_kick' => '次心跳超时'] as $t => $what) {
            if (!empty($count[$t])) {
                $alerts[] = ['color' => 'color-red', 'text' => "最近 {$min} 分钟 {$count[$t]} {$what}" . $nodeText($t)];
            }
        }
        $lagging = count(array_filter($conns, fn($c) => $c['lagging']));
        if ($lagging > 0) {
            $alerts[] = ['color' => 'color-orange', 'text' => "当前 {$lagging} 条连接写阻塞超过 1 秒，订阅方可能收不动"];
        }
        if (!$alerts) {
            $alerts[] = ['color' => 'color-green', 'text' => "最近 {$min} 分钟没有积压断开或心跳超时"];
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
        foreach (MarketMetrics::readDispatchEvents(MarketMetrics::DISPATCH_EVENT_KEEP, 0, MarketMetrics::DISPATCH_EVENT_GO_KEY) as $e) {
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

    /** 事件详情文案。字段由 go/internal/dispatch 写入，见各 s.event() 调用处 */
    private function eventDetail(string $type, array $e): string
    {
        return match ($type) {
            'subscriber_lag_close' => $this->lagCloseDetail($e),
            'subscriber_hb_kick'   => sprintf('%d 秒没回 pong', (int)($e['idle'] ?? 0)),
            'subscriber_hello_invalid' => sprintf('%s（%d 字节）', $e['error'] ?? '', (int)($e['bytes'] ?? 0)),
            'subscriber_auth_reject' => (string)($e['reason'] ?? ''),
            'subscriber_connect'   => sprintf('连上后共 %d 条连接', (int)($e['total'] ?? 0)),
            'subscriber_hello'     => '订阅 ' . (($e['streams'] ?? '') === 'all' ? '全部' : ($e['streams'] ?? '')),
            'snapshot_replay'      => ($e['asked'] ?? '') === 'all'
                ? sprintf('全量订阅，补发 %d 个 stream', (int)($e['fields'] ?? 0))
                : sprintf('补发 %d / %s 个 stream', (int)($e['fields'] ?? 0), $e['asked'] ?? '-'),
            'subscriber_close'     => sprintf('连接了 %s，剩余 %d 条连接',
                $this->durationText((int)($e['alive_sec'] ?? 0)), (int)($e['remaining'] ?? 0)),
            default                => json_encode($e, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * 积压断开详情：原因 + 断开那一刻的现场（字段见 go/internal/dispatch/report.go lagScene）。
     * 现场之前的旧版本 Go 进程写的事件没有 peer 字段，只显示原因
     */
    private function lagCloseDetail(array $e): string
    {
        $parts = [match ($e['reason'] ?? '') {
            'write_timeout' => sprintf('一次写阻塞超过 %ss 被断开', $e['held'] ?? '-'),
            'pending_full'  => sprintf('待发表超过 %d 条被断开', (int)($e['pending'] ?? 0)),
            default         => json_encode($e, JSON_UNESCAPED_UNICODE),
        }];
        if (!isset($e['peer'])) return $parts[0];

        $pongAge = (int)($e['pong_age_sec'] ?? 0);
        $parts[] = $e['peer'] === 'unresponsive'
            ? sprintf('倾向卡死：%d 秒没回 pong（超过 %d 秒），对方多半已掉线、休眠或程序卡住', $pongAge, (int)($e['pong_stale_sec'] ?? 0))
            : sprintf('倾向收得慢：%d 秒前还回过 pong，对方活着，多半是带宽或处理能力跟不上', $pongAge);
        if (isset($e['rtt_avg'])) {
            $parts[] = sprintf('往返时延 均 %s / 最高 %s ms', $e['rtt_avg'], $e['rtt_max'] ?? '-');
        }
        $parts[] = sprintf('断开前 %s 帧/s · %s/s', $e['msgs_ps'] ?? 0, format_bytes((int)($e['bytes_ps'] ?? 0)));
        $parts[] = sprintf('待发表 %d 条（近 10 秒峰值 %d）', (int)($e['pending_now'] ?? 0), (int)($e['pending_peak'] ?? 0));
        if (isset($e['writing_ms'])) {
            $parts[] = sprintf('当时一次写已卡 %.1f 秒', (int)$e['writing_ms'] / 1000);
        }
        $streams = (string)($e['streams'] ?? '');
        $parts[] = $streams === 'all' ? '全量订阅' : "订阅 {$streams}";
        $parts[] = '已连 ' . $this->durationText((int)($e['alive_sec'] ?? 0));
        return implode('；', $parts);
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
