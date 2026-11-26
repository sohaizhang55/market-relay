<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Workerman\Worker;
use Workerman\Timer;
use think\facade\Db;
use think\facade\Config;
use think\facade\Log;
use app\lib\WorkerService;
use app\lib\CommandHeartBeatService;
use app\lib\MarketSourceHealth;
use app\lib\SourceSelector;
use app\enum\CommonStatusEnum;
use app\enum\MarketChannelEnum;
use app\enum\MarketSourceDecisionEnum as Decision;
use app\enum\MarketSourceHealthEnum as Health;
use app\business\service\PairSourceService;
use app\service\MarketSessionService;

/**
 * 选源进程 —— 主备切换：每秒判断各行情源健不健康，决定每个交易对该用哪个源
 *
 * 健康判据只有 WS 新鲜度（MarketSourceHealth，REST 兜底不算）对上该源的
 * market_data.ticker_stale_sec / depth_stale_sec；源自己休市时不算断流。规则见 SourceSelector。
 * 只判有两个及以上启用绑定的交易对：单源的没得切，断流交给 market:price_check_expire。
 *
 * 判断出要换就真切换（PairSourceService::switchTo），没有观察模式。
 * ⚠️ 默认不启用（market.source_select.enable=false）：断流秒数没调好之前一启动就会误切，
 *    在后台「原始行情」页把各源的断流秒数调好、看过帧间隔统计之后再打开。未启用时拒绝启动，
 *    停掉残留进程、退出码 EXIT_DISABLED（见 DisabledGuardTrait），也不算进应常驻清单。
 * 帧间隔统计（调断流秒数用）在采集器里算，不在这里，见 MarketSourceHealth::flushStats()。
 *
 * 命令  php think market:source_select
 * 触发  deploy/restart_market.sh
 * 单例  pid 文件 runtime/market-relay_MarketSourceSelect.pid（见 SingletonGuardTrait）
 * 依赖  表 exchange_pair · exchange_pair_source · market_data · 配置 market.source_select.*
 *       Redis market:source:ws_ts:*（读）· market:source_select:lock / failovers / state / alerts（写）
 *       告警只入队，由 market:source_select_notify（xadmin:task 每 15 秒）发出，见 report()
 *
 * 用法:
 *   php think market:source_select start -d     # 后台常驻（需 market.source_select.enable）
 *   php think market:source_select status
 *   php think market:source_select stop
 */
class MarketSourceSelect extends Command
{
    use SingletonGuardTrait;
    use DisabledGuardTrait;
    use \app\lib\EventLoopBusyTrait;

    public const WORKER_NAME = 'market_relay_MarketSourceSelect';

    /** 锁定的交易对，HASH pair_id => 锁定时刻；人工解锁 = 删掉这一项并清掉 FAILOVER_KEY 里同一项（后台「交易对」页） */
    public const LOCK_KEY = 'market:source_select:lock';
    /** 每个交易对窗口内的切走时刻，HASH pair_id => json [秒,...]。放 Redis 而不是进程内存：
     *  解锁时要一起清掉，否则解锁后第一次切走就又够数，立刻被锁回去 */
    public const FAILOVER_KEY = 'market:source_select:failovers';
    /** 每个交易对当前的选源状态，HASH pair_id => json，给后台展示 */
    public const STATE_KEY = 'market:source_select:state';
    /** 待发的告警，LIST json；由 market:source_select_notify 取出发送，见 report() */
    public const ALERT_QUEUE_KEY = 'market:source_select:alerts';
    /** 告警队列最多留多少条 */
    private const ALERT_QUEUE_MAX = 1000;

    /** 启动后多少秒内只算健康不做决定：等采集器把帧时间刷进 Redis */
    private const STARTUP_GRACE_SEC = 20;
    /** 绑定、交易对、源阈值多久重读一次（秒）。后台改了绑定最多这么久后生效 */
    private const RELOAD_SEC = 10;
    /** 「全部不健康」持续多少秒才告警（切走不等，这里只管告警） */
    private const ALL_DOWN_ALERT_SEC = 30;

    private bool $notify = true;
    private SourceSelector $selector;
    private int $startedAt = 0;

    /** @var array<int,array{symbol_code:string,type:int,source_key:string}> 启用中的交易对 */
    private array $pairs = [];
    /** @var array<int,list<string>> pair_id => 启用中的绑定，按优先级排好 */
    private array $bindings = [];
    /** @var array<string,array{ticker:int,depth:int}> source_key => 断流秒数 */
    private array $thresholds = [];
    private int $loadedAt = 0;

    /** @var array<string,int> source_key => 连续健康的起点（秒） */
    private array $healthySince = [];
    /** @var array<int,array{since:int,alerted:bool}> 正处于「全部不健康」的交易对：从何时开始、告没告过（恢复前不重复告） */
    private array $allDown = [];
    private int $stateWrittenAt = 0;

    /** 是否启用选源；启动条件与应常驻清单（MarketWorkers::expected）共用 */
    public static function enabled(): bool
    {
        return (bool)Config::get('market.source_select.enable', false);
    }

    protected function configure()
    {
        $this->setName('market:source_select')
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run in daemon mode')
            ->setDescription('Market source selector: failover between bound sources per pair');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        global $argv;
        $argv[1] = $action;
        if (isset($argv[2])) unset($argv[2]);

        if (in_array($action, ['start', 'restart', 'reload'], true) && !self::enabled()) {
            $this->stopForDisabled(
                self::WORKER_NAME,
                $output,
                '[Market-SourceSelect] market.source_select.enable 未打开（.env 的 MARKET_SOURCE_SELECT_ENABLE），不启动。'
                . '先在后台「原始行情」页调好各源的断流秒数再打开，否则会误切。'
            );
            return;
        }

        WorkerService::init(self::WORKER_NAME);
        $this->guardSingleton(self::WORKER_NAME, (string)$action, $output);

        if ($input->getOption('daemon')) {
            Worker::$daemonize = true;
        }

        $worker = new Worker();
        $worker->name  = self::WORKER_NAME;
        $worker->count = 1;

        $worker->onWorkerStart = function () use ($output, $worker) {
            CommandHeartBeatService::start($worker);
            $this->initWorkerEnv();

            $cfg = (array)Config::get('market.source_select', []);
            $this->notify        = (bool)($cfg['notify'] ?? true);
            $this->selector      = new SourceSelector(
                max(1, (int)($cfg['recover_sec'] ?? 300)),
                max(1, (int)($cfg['flap_window_sec'] ?? 3600)),
                max(1, (int)($cfg['flap_max'] ?? 3)),
            );
            $this->startedAt = time();

            Timer::add(30, $this->timedHandler('db_ping', fn() => $this->pingDb()));
            Timer::add(1, fn() => $this->runTick($output));

            Timer::add(10, function () use ($worker) {
                \app\lib\MarketMetrics::guardStat($worker->name, function () use ($worker) {
                    $snap = self::loopBusySnapshot(10.0);
                    \app\lib\MarketMetrics::putWorker($worker->name, [
                        'busy_pct'    => round($snap['pct'], 1),
                        'busy_detail' => $snap['detail'],
                        'tasks'       => json_encode(self::taskSnapshot(), JSON_UNESCAPED_UNICODE),
                    ]);
                    self::loopBusyReset();
                });
            });

            business_log('market_source_select', '选源进程启动', 'info');
            if (!Worker::$daemonize) {
                $output->writeln('<info>[Market-SourceSelect]</info> Started');
            }
        };

        Worker::runAll();
    }

    private function initWorkerEnv(): void
    {
        try {
            Db::close();
        } catch (\Exception $e) {
        }
        Config::set(['break_reconnect' => true], 'database.connections.mysql');
    }

    /** DB 心跳检测：连接断开则关闭，下次查询重新建连 */
    private function pingDb(): void
    {
        try {
            Db::query('SELECT 1');
        } catch (\Throwable $e) {
            Db::close();
        }
    }

    /** 跑一轮，异常落日志而不冒到 Workerman（冒出去这个定时器就停了，选源静默失效） */
    private function runTick(Output $output): void
    {
        try {
            $this->timed('select', fn() => $this->tick());
        } catch (\Throwable $e) {
            business_log('market_source_select', "异常: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}", 'error');
            Log::error("[MarketSourceSelect] {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}");
            if (!Worker::$daemonize) {
                $output->writeln("<error>[Market-SourceSelect]</error> {$e->getMessage()}");
            }
        }
    }

    private function tick(): void
    {
        $now = time();
        if ($now - $this->loadedAt >= self::RELOAD_SEC) {
            $this->reload();
            $this->loadedAt = $now;
        }

        [$health, $reasons] = $this->evaluateHealth($now);

        // 启动宽限期内只算健康，不做决定
        if ($now - $this->startedAt < self::STARTUP_GRACE_SEC) return;

        $redis = redis_client();
        $locks = $redis ? ($redis->hGetAll(self::LOCK_KEY) ?: []) : [];
        $state = [];

        foreach ($this->bindings as $pairId => $bound) {
            $pair = $this->pairs[$pairId] ?? null;
            if ($pair === null || count($bound) < 2) continue;

            $active = $pair['source_key'];
            $locked = isset($locks[(string)$pairId]);

            [$decision, $to, $why] = $this->selector->decide($bound, $active, $health, $this->healthySince, $now, $locked);
            $label = $this->pairLabel($pair);

            switch ($decision) {
                case Decision::KEEP:
                    // 只有真的告过「全部不健康」才发恢复；没告过的（持续不到 ALL_DOWN_ALERT_SEC）悄悄清掉
                    if (isset($this->allDown[$pairId]) && ($health[$active] ?? Health::FALLBACK) === Health::OK) {
                        if ($this->allDown[$pairId]['alerted']) {
                            $this->report('info', '行情源恢复', "{$label} 当前源 {$active} 已恢复");
                        }
                        unset($this->allDown[$pairId]);
                    }
                    break;

                case Decision::ALL_DOWN:
                    // 持续满 ALL_DOWN_ALERT_SEC 才告警：冷门币的帧本来就稀疏，在阈值附近一闪而过的会反复告警刷屏
                    $this->allDown[$pairId] ??= ['since' => $now, 'alerted' => false];
                    if (!$this->allDown[$pairId]['alerted'] && $now - $this->allDown[$pairId]['since'] >= self::ALL_DOWN_ALERT_SEC) {
                        $this->allDown[$pairId]['alerted'] = true;
                        $detail = $reasons[$active] ?? '';
                        $this->report('error', '行情源全部不健康', "{$label} {$why}" . ($detail ? "（{$detail}）" : '')
                            . '，已持续 ' . ($now - $this->allDown[$pairId]['since']) . ' 秒，保持不动');
                    }
                    break;

                case Decision::FAILOVER:
                case Decision::RECOVER:
                    unset($this->allDown[$pairId]);
                    $detail = $decision === Decision::FAILOVER && isset($reasons[$active]) ? "（{$reasons[$active]}）" : '';
                    $this->apply($pairId, $label, $decision, $active, (string)$to, $why . $detail, $now);
                    $active = (string)$to;
                    break;
            }

            $state[(string)$pairId] = json_encode([
                'active'   => $active,
                'locked'   => $locked,
                'all_down' => $this->allDown[$pairId]['alerted'] ?? false,
                'at'       => $now,
            ], JSON_UNESCAPED_UNICODE);
        }

        // 给后台展示用，每 10 秒整份覆盖一次就够
        if ($redis && $state && $now - $this->stateWrittenAt >= 10) {
            $redis->multi(\Redis::PIPELINE)->del(self::STATE_KEY)->hMSet(self::STATE_KEY, $state)->exec();
            $this->stateWrittenAt = $now;
        }
    }

    /** 执行一次切换，记录并告警；切走还要算锁定 */
    private function apply(int $pairId, string $label, Decision $decision, string $from, string $to, string $why, int $now): void
    {
        $verb = $decision === Decision::FAILOVER ? '切走' : '切回';

        if (!PairSourceService::switchTo($pairId, $to, "自动{$verb}：{$why}")) {
            $this->report('error', '行情源切换失败', "{$label} {$from} → {$to} 切换失败（交易对或绑定已不存在）");
            return;
        }
        $this->pairs[$pairId]['source_key'] = $to;

        $this->report($decision === Decision::FAILOVER ? 'warning' : 'info', '行情源' . $verb, "{$verb} {$label}：{$from} → {$to}（{$why}）");

        if ($decision !== Decision::FAILOVER) return;

        $redis = redis_client();
        if (!$redis) return;
        $history = $this->selector->recentFailovers(
            [...(json_decode((string)$redis->hGet(self::FAILOVER_KEY, (string)$pairId), true) ?: []), $now], $now
        );
        $redis->hSet(self::FAILOVER_KEY, (string)$pairId, json_encode($history));
        if ($this->selector->shouldLock($history, $now)) {
            $redis->hSet(self::LOCK_KEY, (string)$pairId, (string)$now);
            $this->report('error', '行情源切换已锁定',
                "{$label} 1 小时内切走 " . count($history) . " 次，已锁定在 {$to}，不再自动切换。"
                . '确认主源稳定后在后台「交易对」页解锁');
        }
    }

    /**
     * 每个相关源此刻的健康状态，顺带维护「连续健康起点」。
     *
     * @return array{0:array<string,Health>,1:array<string,string>} [健康状态, 不健康的原因]
     */
    private function evaluateHealth(int $now): array
    {
        $nowMs   = (int)(microtime(true) * 1000);
        $wsPrice = MarketSourceHealth::wsTsMap(MarketChannelEnum::PRICE);
        $wsDepth = MarketSourceHealth::wsTsMap(MarketChannelEnum::DEPTH);
        $health  = [];
        $reasons = [];

        $sources = array_unique(array_merge(...array_values($this->bindings ?: [[]])));
        foreach ($sources as $sk) {
            // 用 state() 不用 tradable*：这里问的是源自己此刻有没有在交易，与后台休市开关无关
            if (!MarketSessionService::stateBySourceKey($sk)->tradable()) {
                $health[$sk] = Health::CLOSED;
                unset($this->healthySince[$sk]);
                continue;
            }

            $limit = $this->thresholds[$sk] ?? ['ticker' => 0, 'depth' => 0];
            $why   = [];
            foreach ([[$wsPrice, $limit['ticker'], '价格'], [$wsDepth, $limit['depth'], '深度']] as [$map, $sec, $name]) {
                $last = (int)($map[$sk] ?? 0);
                $gap  = $last > 0 ? $nowMs - $last : null;
                $stale = $sec > 0 && ($gap === null || $gap > $sec * 1000);
                if ($stale) $why[] = $gap === null ? "{$name}帧从没收到过" : sprintf('%s %d 秒未更新', $name, intdiv($gap, 1000));
            }

            if ($why) {
                $health[$sk]  = Health::DOWN;
                $reasons[$sk] = implode('，', $why);
                unset($this->healthySince[$sk]);
            } else {
                $health[$sk] = Health::OK;
                $this->healthySince[$sk] ??= $now;
            }
        }

        return [$health, $reasons];
    }

    /** 重读启用中的交易对、绑定与源阈值 */
    private function reload(): void
    {
        $this->pairs = [];
        foreach (Db::name('exchange_pair')->where('status', CommonStatusEnum::ON->value)
                     ->field('id, symbol_code, type, source_key')->limit(5000)->select() as $p) {
            $this->pairs[(int)$p['id']] = [
                'symbol_code' => (string)$p['symbol_code'],
                'type'        => (int)$p['type'],
                'source_key'  => (string)($p['source_key'] ?? ''),
            ];
        }

        $this->bindings = [];
        foreach (Db::name('exchange_pair_source')->where('status', CommonStatusEnum::ON->value)
                     ->order('pair_id asc, priority asc, id asc')->field('pair_id, source_key')->limit(20000)->select() as $b) {
            if (isset($this->pairs[(int)$b['pair_id']])) {
                $this->bindings[(int)$b['pair_id']][] = (string)$b['source_key'];
            }
        }

        $this->thresholds = [];
        foreach (Db::name('market_data')->field('source_key, ticker_stale_sec, depth_stale_sec')->limit(5000)->select() as $m) {
            $this->thresholds[(string)$m['source_key']] = ['ticker' => (int)$m['ticker_stale_sec'], 'depth' => (int)$m['depth_stale_sec']];
        }

        // 交易对被停用或解绑后，丢掉它的告警沿，免得重新启用时沿用旧值
        $this->allDown = array_intersect_key($this->allDown, $this->bindings);
    }

    /**
     * 记日志，按配置把告警放进队列，由 market:source_select_notify 定时发出。
     *
     * 不在这里直接发：站内通知要同步连后台 Gateway 推送、Telegram 是同步 HTTP，
     * 连不上时会把事件循环卡住好几秒 —— 启动或整家交易所断流时一轮几十条告警，选源就停摆了。
     */
    private function report(string $level, string $title, string $msg): void
    {
        business_log('market_source_select', $msg, $level);
        if (!$this->notify) return;

        try {
            $redis = redis_client();
            $redis?->multi(\Redis::PIPELINE)
                ->rPush(self::ALERT_QUEUE_KEY, json_encode(['level' => $level, 'title' => $title, 'msg' => $msg], JSON_UNESCAPED_UNICODE))
                // 没人消费（定时任务没注册）时只留最近一批，不无限增长
                ->lTrim(self::ALERT_QUEUE_KEY, -self::ALERT_QUEUE_MAX, -1)
                ->exec();
        } catch (\Throwable $e) {
            business_log('market_source_select', "告警入队失败：{$e->getMessage()}（{$title}）", 'error');
        }
    }

    private function pairLabel(array $pair): string
    {
        return \app\lib\MarketPublisher::pairKey($pair['symbol_code'], $pair['type']);
    }
}
