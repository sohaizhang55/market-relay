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
use app\lib\MarketHelper;
use app\lib\MarketRestAbstract;
use app\lib\TickBudget;
use app\enum\MarketExchangeEnum;
use app\enum\MarketDataTypeEnum;
use app\business\service\MarketService;
use app\service\MarketSessionService;

/**
 * 资金费率供数进程，每个有合约源的交易所一个
 *
 * 轮询交易所 REST。
 * 这是费率唯一的供数通道，停了超过 RATE_STALE_SECONDS，下游（业务平台的资金费结算）整期跳过收费。
 *
 * 命令  php think market:funding
 * 触发  deploy/restart_market.sh
 * 单例  按交易所：pid 文件 runtime/market-relay_MarketFunding_{exchange}.pid（见 SingletonGuardTrait）
 * 依赖  表 market_data · exchange_pair
 *
 * 用法:
 *   php think market:funding start -e huobi -d      # 后台常驻，只管 huobi 的合约
 *   php think market:funding status -e binance      # 看某家的进程状态
 *   php think market:funding stop -e huobi
 *
 * 设计依据：.agents/plan/market_funding_split.md
 */
class MarketFunding extends Command
{
    use SingletonGuardTrait;
    use \app\lib\EventLoopBusyTrait;

    /**
     * 费率新鲜度阈值(秒)：funding_rate_ts 早于此值即视为供数断了。
     * 与下游资金费结算的校验阈值一致 —— 那边超过它就整期跳过收费；后台「原始行情」页据此标红。
     * 注意 funding_rate_ts 是**毫秒**时间戳，比较时要换算。
     */
    public const RATE_STALE_SECONDS = 3600;

    /** REST 轮询周期(秒)。一圈 = 合约数 ÷ REST_PER_TICK × 本值，须远小于 RATE_STALE_SECONDS */
    private const REST_INTERVAL = 30;

    /** REST 每轮最多拉几个合约 */
    private const REST_PER_TICK = 4;

    /** 费率没变时最多多久落一次库(秒)，保证 funding_rate_ts 持续刷新 */
    private const WRITE_MIN_INTERVAL = 60.0;

    /** 单轮墙钟预算(秒)，防止同步 HTTP 阻塞超过心跳 TTL */
    private const TICK_BUDGET_SEC = 3.0;

    /** 合约源清单缓存(秒) */
    private const SOURCES_TTL = 300;

    private MarketExchangeEnum $exchange;

    /** REST 轮询游标：下一个要检查的合约下标 */
    private int $restCursor = 0;

    /** 落库节流：source_key => ['rate' => 落库精度的费率, 'at' => 时间戳] */
    private array $lastWrite = [];

    private array $sourcesCache = [];
    private float $sourcesAt = 0.0;
    private ?TickBudget $tickBudget = null;

    /** 某交易所费率进程的 worker 名，也是 pid / 日志 / 心跳 / 指标键的名字 */
    public static function workerName(string $exchange): string
    {
        return 'market_relay_MarketFunding_' . $exchange;
    }

    /** 该交易所有没有合约源（含 debug_source_keys 过滤）；启动条件与应常驻清单共用 */
    public static function hasSwapSources(string $exchange): bool
    {
        $query = Db::name('market_data')->where('exchange', $exchange)
            ->where('type', MarketDataTypeEnum::SWAP->value)->where('source_key', '<>', '');
        $debugSourceKeys = array_filter((array)Config::get('market.debug_source_keys', []));
        if ($debugSourceKeys) $query->whereIn('source_key', $debugSourceKeys);
        return $query->count() > 0;
    }

    protected function configure()
    {
        $this->setName('market:funding')
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run in daemon mode')
            ->addOption('exchange', 'e', Option::VALUE_REQUIRED, '只管这一家的合约，取值见 MarketExchangeEnum（必填）')
            ->setDescription('Funding rate feed worker, the only supply channel of funding rates');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        global $argv;
        $argv[1] = $action;
        if (isset($argv[2])) unset($argv[2]);

        $exchangeValue = strtolower(trim((string)$input->getOption('exchange')));
        if ($exchangeValue === '') {
            $output->writeln('<error>Usage Error: --exchange (-e) is REQUIRED.</error>');
            $output->writeln('Example: php think market:funding start -e huobi -d');
            return;
        }
        $exchange = MarketExchangeEnum::tryFrom($exchangeValue);
        if ($exchange === null) {
            $output->writeln("<error>[Market-Funding] 未知交易所 '{$exchangeValue}'，取值见 MarketExchangeEnum</error>");
            return;
        }
        $this->exchange = $exchange;
        $workerName = self::workerName($exchange->value);

        if (in_array($action, ['start', 'restart'], true) && !self::hasSwapSources($exchange->value)) {
            $output->writeln("<error>[Market-Funding] 未找到 exchange={$exchange->value} 的合约 market_data 配置（或被 debug_source_keys 白名单过滤为空），Worker 未启动。</error>");
            return;
        }

        WorkerService::init($workerName);
        $this->guardSingleton($workerName, (string)$action, $output);

        if ($input->getOption('daemon')) {
            Worker::$daemonize = true;
        }

        $worker = new Worker();
        $worker->name  = $workerName;
        $worker->count = 1;

        $worker->onWorkerStart = function () use ($output, $worker) {
            CommandHeartBeatService::start($worker);
            $this->initWorkerEnv();
            // DB 探活：MySQL 断过一次后连接不会自愈，SELECT 1 失败即 close，下次查询重新建连
            Timer::add(30, $this->timedHandler('db_ping', fn() => $this->pingDb()));

            $interval = self::REST_INTERVAL;
            // @no-task-report: runTick() 内部经 timed('funding') 记存活证据
            Timer::add($interval, fn() => $this->runTick($output));

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

            if (!Worker::$daemonize) {
                $output->writeln("<info>[Market-Funding]</info> Started: exchange={$this->exchange->value} source=exchange REST interval={$interval}s");
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

    /** 跑一轮：装配墙钟预算，异常落日志而不冒到 Workerman */
    private function runTick(Output $output): void
    {
        $this->tickBudget = TickBudget::ofSeconds(self::TICK_BUDGET_SEC);
        try {
            $this->timed('funding', fn() => $this->tickFromRest($output));
        } catch (\Throwable $e) {
            business_log('market_funding', "[{$this->exchange->value}] 异常: {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}", 'error');
            Log::error("[MarketFunding][{$this->exchange->value}] {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}");
            if (!Worker::$daemonize) {
                $output->writeln("<error>[Market-Funding]</error> {$e->getMessage()}");
            }
        } finally {
            $this->tickBudget = null;
        }
    }

    /** 本轮预算是否已用尽，判在每次外呼之前 */
    private function budgetExpired(): bool
    {
        return $this->tickBudget !== null && $this->tickBudget->expired();
    }

    /** 把本轮剩余预算压进单次 HTTP 超时 */
    private function restTimeout(int $preferred): int
    {
        return $this->tickBudget === null ? $preferred : $this->tickBudget->timeout($preferred);
    }

    /**
     * 本交易所的合约源，清单带缓存、休市过滤不缓存
     *
     * @return array<int, array{source_key:string, exchange:string, symbol:string, type:int}>
     */
    private function loadSwapSources(): array
    {
        $now = microtime(true);
        if ($now - $this->sourcesAt >= self::SOURCES_TTL) {
            $query = Db::name('market_data')->field('source_key, exchange, symbol, type')
                ->where('exchange', $this->exchange->value)
                ->where('type', MarketDataTypeEnum::SWAP->value);

            $debugSourceKeys = array_filter((array)Config::get('market.debug_source_keys', []));
            if ($debugSourceKeys) {
                $query->whereIn('source_key', $debugSourceKeys);
                business_log('market_funding', "[{$this->exchange->value}] debug_source_keys 白名单生效，仅供数 "
                    . implode(',', $debugSourceKeys) . ' —— 其余合约的费率全部停更', 'warning');
            }

            $rows = $query->select()->toArray();
            $this->sourcesCache = array_values(array_filter($rows, fn($r) => !empty($r['source_key'])));
            $this->sourcesAt    = $now;
        }

        return array_values(array_filter(
            $this->sourcesCache,
            fn(array $r) => MarketSessionService::tradableBySourceKey((string)$r['source_key'])
        ));
    }

    /** 按游标轮询费率已陈旧的合约，每轮最多 REST_PER_TICK 个 */
    private function tickFromRest(Output $output): void
    {
        $sources = $this->loadSwapSources();
        if (empty($sources)) {
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Market-Funding][{$this->exchange->value}]</comment> 无合约行情源，跳过");
            }
            return;
        }

        $client = MarketRestAbstract::make($this->exchange);
        if (!$client) {
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Market-Funding][{$this->exchange->value}]</comment> 无 REST 实现，跳过");
            }
            return;
        }

        $staleBeforeMs = (int)(microtime(true) * 1000) - self::REST_INTERVAL * 1000;
        $count  = count($sources);
        $start  = $this->restCursor % $count;
        $polled = 0;

        for ($k = 0; $k < $count; $k++) {
            $idx = ($start + $k) % $count;
            $src = $sources[$idx];
            $sourceKey = (string)$src['source_key'];

            $row = Db::name('market_data')->where('source_key', $sourceKey)->field('funding_rate_ts')->find();
            if (!$row) continue;
            if ((int)$row['funding_rate_ts'] >= $staleBeforeMs) continue;

            // 成败都推进游标，一个恒失败的合约不能卡住后面所有
            $this->restCursor = ($idx + 1) % $count;

            if ($this->budgetExpired()) break;

            $result = $client->withTimeout($this->restTimeout(5))
                ->fetchRestFundingRate((string)$src['symbol'], (int)$src['type']);
            if ($result === null) {
                business_log('market_funding', "[{$sourceKey}] REST fetch failed", 'error');
                if (!Worker::$daemonize) {
                    $output->writeln("<error>[Market-Funding]</error> {$sourceKey} REST 拉取失败");
                }
                break;
            }

            // 优先用交易所时间；取不到才用本地时间，留 0 会被结算侧判成从未采集
            $exchangeTs = (int)($result['exchange_ts'] ?? 0);
            $this->applyFundingRate(
                $src,
                (string)$result['rate'],
                (string)$result['estimated'],
                $exchangeTs > 0 ? $exchangeTs : (int)(microtime(true) * 1000),
                (int)($result['next_funding_ts'] ?? 0),
                $output
            );

            if (++$polled >= self::REST_PER_TICK) break;
        }

        if (!Worker::$daemonize) {
            $output->writeln(sprintf(
                '<comment>[Market-Funding][%s]</comment> contracts=%d polled=%d cursor=%d',
                $this->exchange->value, $count, $polled, $this->restCursor
            ));
        }
    }

    /** 费率落库到本机 market_data + exchange_pair，经 fundingRateChanged() 节流 */
    private function applyFundingRate(array $src, string $rate, string $estimated, int $ts, int $nextFundingTs, Output $output): void
    {
        $sourceKey = (string)$src['source_key'];

        $rateChanged = $this->fundingRateChanged($sourceKey, $rate);
        if ($rateChanged === null) {
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Market-Funding]</comment> {$sourceKey} rate={$rate} 命中节流，跳过落库");
            }
            return;
        }

        MarketService::updateFundingRate($this->exchange, (string)$src['symbol'], (int)$src['type'], $rate, $estimated, $ts, $nextFundingTs);

        if ($rateChanged) {
            business_log('market_funding', "[{$sourceKey}] <= rate={$rate} estimated={$estimated} ts={$ts} next={$nextFundingTs}", 'warning');
        }
        if (!Worker::$daemonize) {
            $output->writeln("<info>[Market-Funding]</info> {$sourceKey} rate={$rate} next="
                . ($nextFundingTs > 0 ? date('m-d H:i', intdiv($nextFundingTs, 1000)) : '-'));
        }
    }

    /**
     * 落库节流：按 decimal(10,6) 精度比较，值变了立即放行，没变则最多 WRITE_MIN_INTERVAL 秒一次
     *
     * @return bool|null null = 不必落库；true = 费率变了（含首次）；false = 值没变、只为刷新 ts
     */
    private function fundingRateChanged(string $sourceKey, string $rate): ?bool
    {
        $now  = microtime(true);
        $curr = is_numeric($rate) ? bcadd(MarketHelper::safeNumStr($rate), '0', 6) : $rate;
        $last = $this->lastWrite[$sourceKey] ?? null;
        $same = $last !== null && $last['rate'] === $curr;

        if ($same && ($now - $last['at']) < self::WRITE_MIN_INTERVAL) {
            return null;
        }

        $this->lastWrite[$sourceKey] = ['rate' => $curr, 'at' => $now];
        return !$same;
    }
}
