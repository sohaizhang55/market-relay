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
use app\lib\MarketRestAbstract;
use app\lib\TickBudget;
use app\enum\MarketExchangeEnum;
use app\service\MarketSessionService;

/**
 * 按交易所常驻、走交易所 REST 的行情维护进程的公共骨架：MarketPriceFallback · MarketKlineBackfill
 *
 * 两者原本是同一个 market:backfill 进程里的两个任务，共用一个事件循环和一份墙钟预算 ——
 * K 线补数一忙，价格兜底就得排队。2026-10 拆成两个进程，骨架留在这里：
 *   -e 选交易所 · 单例 pid · 心跳 · 指标上报 · 单调度器 · 单轮墙钟预算 · 源清单缓存 · 配置合并 · 游标轮转
 *
 * 子类给出：命令名（configure）、workerName、任务（registerTasks）、预算、日志频道、指标。
 */
abstract class MarketRestWorkerAbstract extends Command
{
    use SingletonGuardTrait;
    use \app\lib\EventLoopBusyTrait;

    /** 本进程负责的交易所（-e 传入） */
    protected MarketExchangeEnum $exchange;

    /** 某交易所该进程的 worker 名，也是 pid / 日志 / 心跳 / 指标键的名字 */
    abstract public static function workerName(string $exchange): string;

    /** 日志与控制台前缀，如 Price-Fallback */
    abstract protected function logTag(): string;

    /** business_log 频道 */
    abstract protected function logChannel(): string;

    /** 用 registerTask() 登记本进程的任务 */
    abstract protected function registerTasks(Output $output): void;

    /** 单轮墙钟预算(秒) */
    abstract protected function budgetSeconds(): float;

    /** 启动时控制台多打的一段说明，如各任务周期 */
    abstract protected function bannerDetail(): string;

    /** 每 10 秒随指标上报的子类字段（已 json_encode 的标量） */
    protected function workerMetrics(): array
    {
        return [];
    }

    /** 指标上报后清零子类的窗口计数 */
    protected function resetWindowStats(): void
    {
    }

    /** 子类 configure() 共用的参数 */
    protected function configureWorker(string $name, string $description): void
    {
        $this->setName($name)
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run in daemon mode')
            ->addOption('exchange', 'e', Option::VALUE_REQUIRED, '只管这一家的源，取值见 MarketExchangeEnum（必填）')
            ->setDescription($description);
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        global $argv;
        $argv[1] = $action;
        if (isset($argv[2])) unset($argv[2]);

        $tag = $this->logTag();
        $exchangeValue = strtolower(trim((string)$input->getOption('exchange')));
        if ($exchangeValue === '') {
            $output->writeln('<error>Usage Error: --exchange (-e) is REQUIRED.</error>');
            $output->writeln("Example: php think {$this->getName()} start -e huobi -d");
            return;
        }

        $exchange = MarketExchangeEnum::tryFrom($exchangeValue);
        if ($exchange === null) {
            $output->writeln("<error>[{$tag}] 未知交易所 '{$exchangeValue}'，取值见 MarketExchangeEnum</error>");
            return;
        }
        $this->exchange = $exchange;
        $workerName = static::workerName($exchange->value);

        // 与 MarketWorkers::restWorkers() 同一判据
        if (in_array($action, ['start', 'restart'], true) && !self::hasSources($exchange->value)) {
            $output->writeln("<error>[{$tag}] 未找到 exchange={$exchange->value} 的 market_data 配置（或被 debug_source_keys 白名单过滤为空），Worker 未启动。</error>");
            return;
        }

        WorkerService::init($workerName);
        // 必须在 Worker::runAll() 之前，见 SingletonGuardTrait
        $this->guardSingleton($workerName, (string)$action, $output);

        if ($input->getOption('daemon')) {
            Worker::$daemonize = true;
        }

        $worker        = new Worker();
        // 运维坐标 name = workerName()：
        //   heartbeat:cmd:{name} · heartbeat:stopping:{name} · heartbeat:inst:{name}
        //   market:metrics:worker:{name} · runtime/{name}.pid · runtime/log/{name}.log
        $worker->name  = $workerName;
        $worker->count = 1;

        $worker->onWorkerStart = function () use ($output, $worker, $tag) {
            CommandHeartBeatService::start($worker);

            $this->initWorkerEnv();
            // DB 探活：MySQL 断过一次后连接不会自愈，SELECT 1 失败即 close，下次查询重新建连
            Timer::add(30, $this->timedHandler('db_ping', fn() => $this->pingDb()));

            // 任务表 + 单调度器：每 SCHEDULER_TICK 秒挑一个最逾期的任务跑，一个周期最多一件
            $this->registerTasks($output);

            // @no-task-report: 调度器挑出的每个任务都经 runTick() 的 timed() 记存活证据
            Timer::add(self::SCHEDULER_TICK, function () use ($output) {
                $this->runDueTask($output);
            });

            // 指标上报，10 秒一次。guardStat 理由见 MarketMetrics::guardStat()
            Timer::add(10, function () use ($worker) {
                \app\lib\MarketMetrics::guardStat($worker->name, function () use ($worker) {
                    $snap = self::loopBusySnapshot(10.0);
                    \app\lib\MarketMetrics::putWorker($worker->name, [
                        'busy_pct'    => round($snap['pct'], 1),
                        'busy_detail' => $snap['detail'],
                        'tasks'       => json_encode(self::taskSnapshot(), JSON_UNESCAPED_UNICODE),
                    ] + $this->workerMetrics());
                    self::loopBusyReset();
                    $this->resetWindowStats();

                    // 兜底埋点在内存累加，搭这个定时器批量落盘
                    \app\lib\MarketMetrics::flushGroups();
                });
            });

            if (!Worker::$daemonize) {
                $output->writeln("<info>[{$tag}]</info> Started: exchange={$this->exchange->value} source=exchange REST " . $this->bannerDetail());
            }
        };

        Worker::runAll();
    }

    /** 调度器周期(秒)，只负责挑任务，耗时在任务里、由 budgetSeconds() 约束 */
    private const SCHEDULER_TICK = 1;

    /** @var array<string,array{interval:int,last:float,run:callable}> 任务表 */
    private array $tasks = [];

    protected function registerTask(string $name, int $intervalSec, callable $run): void
    {
        $this->tasks[$name] = [
            'interval' => max(1, $intervalSec),
            // 置为 now：等满一个周期再首跑，避免启动时几件事同时开跑
            'last'     => microtime(true),
            'run'      => $run,
        ];
    }

    /** 挑一个最逾期的任务跑，一个调度周期最多一个（按逾期时长挑，防止固定顺序下后面的任务饿死） */
    private function runDueTask(Output $output): void
    {
        $now  = microtime(true);
        $pick = null;
        $worst = -1.0;

        foreach ($this->tasks as $name => $t) {
            $overdue = $now - $t['last'] - $t['interval'];
            if ($overdue < 0) continue;
            if ($overdue > $worst) { $worst = $overdue; $pick = $name; }
        }
        if ($pick === null) return;

        // 先记时刻再跑：跑完再记的话慢任务会被立刻再判逾期、占住调度器
        $this->tasks[$pick]['last'] = $now;
        $this->runTick($pick, $output, $this->tasks[$pick]['run']);
    }

    /** 跑一个任务：装配单轮墙钟预算，异常落日志而不冒到 Workerman */
    private function runTick(string $name, Output $output, callable $fn): void
    {
        $this->tickBudget = TickBudget::ofSeconds($this->budgetSeconds());
        try {
            $this->timed($name, $fn);
        } catch (\Throwable $e) {
            business_log(
                $this->logChannel(),
                "[{$name}] 异常: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}",
                'error'
            );
            Log::error("[{$this->logTag()}][{$name}] {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}");
            if (!Worker::$daemonize) {
                $output->writeln("<error>[{$this->logTag()}][{$name}]</error> {$e->getMessage()}");
            }
        } finally {
            $this->tickBudget = null;
        }
    }

    /** 当前 tick 的墙钟预算，见 runTick() */
    private ?TickBudget $tickBudget = null;

    /** 把本轮剩余预算压进单次 HTTP 超时 */
    protected function restTimeout(int $preferred): int
    {
        return $this->tickBudget === null ? $preferred : $this->tickBudget->timeout($preferred);
    }

    /** 本轮预算是否已用尽，判在每次外呼之前 */
    protected function budgetExpired(): bool
    {
        return $this->tickBudget !== null && $this->tickBudget->expired();
    }

    private function initWorkerEnv(): void
    {
        try {
            Db::close();
        } catch (\Exception $e) {
        }
        Config::set(['break_reconnect' => true], 'database.connections.mysql');
    }

    /**
     * DB 心跳检测：连接断开则关闭，下次查询重新建连
     */
    private function pingDb(): void
    {
        try {
            Db::query('SELECT 1');
        } catch (\Throwable $e) {
            Db::close();
        }
    }

    /** 行情源清单缓存(秒)，后台增删交易对后最多这么久生效 */
    private const SOURCES_TTL = 300;

    /** @var array<string,array> "{type}" => 结果；'*' 表示不限 type */
    private array $sourcesCache = [];
    /** @var array<string,float> 对应的缓存时刻 */
    private array $sourcesAt = [];

    /**
     * 本交易所的行情源（清单带缓存，休市过滤不缓存）
     *
     * symbol 取采集器建连用的那一列：火币合约 REST 要 BTC-USDT，不是 source_key 里的规范化形式。
     *
     * @param int|null $type 只要某个 MarketDataTypeEnum，null = 全部
     * @param bool $openOnly true = 滤掉休市中的源（价格兜底）；false = 全要（K 线补数要补休市前留下的洞）
     * @return array<int, array{source_key:string, exchange:string, symbol:string, type:int}>
     */
    protected function loadSources(?int $type = null, bool $openOnly = true): array
    {
        $ck  = $type === null ? '*' : (string)$type;
        $now = microtime(true);
        if (isset($this->sourcesAt[$ck]) && ($now - $this->sourcesAt[$ck]) < self::SOURCES_TTL) {
            return $openOnly ? $this->openOnly($this->sourcesCache[$ck]) : $this->sourcesCache[$ck];
        }

        $query = Db::name('market_data')->field('source_key, exchange, symbol, type')
            ->where('exchange', $this->exchange->value);
        if ($type !== null) {
            $query->where('type', $type);
        }

        // array_filter 不能省：(array)"" 是 [""]，会让 whereIn 匹配不到任何行
        $debugSourceKeys = array_filter((array)Config::get('market.debug_source_keys', []));
        if ($debugSourceKeys) {
            $query->whereIn('source_key', $debugSourceKeys);
            business_log($this->logChannel(),
                'debug_source_keys 白名单生效，仅处理 ' . implode(',', $debugSourceKeys)
                . ' —— 其余源本进程一概不管', 'warning');
        }

        $rows = $query->select()->toArray();

        $out = array_values(array_filter($rows, fn($r) => !empty($r['source_key'])));
        $this->sourcesCache[$ck] = $out;
        $this->sourcesAt[$ck]    = $now;
        return $openOnly ? $this->openOnly($out) : $out;
    }

    /** 该交易所有没有行情源（含 debug_source_keys 过滤）；启动条件与应常驻清单共用 */
    public static function hasSources(string $exchange): bool
    {
        $query = Db::name('market_data')->where('exchange', $exchange)->where('source_key', '<>', '');
        $debugSourceKeys = array_filter((array)Config::get('market.debug_source_keys', []));
        if ($debugSourceKeys) $query->whereIn('source_key', $debugSourceKeys);
        return $query->count() > 0;
    }

    /** 本交易所生效的一段配置：market.{section} 打底，market.{section}.exchanges.{exchange} 浅合并逐键覆盖 */
    protected function cfg(string $section): array
    {
        $base = (array)Config::get("market.{$section}", []);
        $override = (array)($base['exchanges'][$this->exchange->value] ?? []);
        unset($base['exchanges']);
        return array_merge($base, $override);
    }

    /** 滤掉休市中的源（后台休市总开关关掉时原样返回），休市期间外呼只会白耗配额 */
    private function openOnly(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn(array $r) => MarketSessionService::tradableBySourceKey((string)($r['source_key'] ?? ''))
        ));
    }

    /** 按游标旋转列表，让本轮从上次停下的源开始扫，防止靠前的源长期占满配额 */
    protected function rotateByCursor(array $rows, string $cursorKey): array
    {
        if (empty($rows) || $cursorKey === '') return $rows;

        foreach ($rows as $i => $r) {
            if ((string)($r['source_key'] ?? '') !== $cursorKey) continue;
            return $i === 0 ? $rows : array_merge(array_slice($rows, $i), array_slice($rows, 0, $i));
        }

        // 游标指向的源已被删除：从头开始
        return $rows;
    }

    /** 该交易所的 REST 客户端，单次超时压进本轮预算；未知 exchange 返回 null */
    protected function restClient(string $exchangeValue): ?MarketRestAbstract
    {
        $exchange = MarketExchangeEnum::tryFrom($exchangeValue);
        if ($exchange === null) return null;
        return MarketRestAbstract::make($exchange)?->withTimeout($this->restTimeout(5));
    }
}
