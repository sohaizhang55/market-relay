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
use app\lib\WorkerService;
use app\lib\CommandHeartBeatService;
use app\business\service\MarketService;

/**
 * 行情落库 Flusher
 *
 * 采集进程只把行情写进 Redis 热层并标脏，本进程定时抽干脏集合、批量落库到
 * market_data / exchange_pair。采集与落库分离，避免高频 WS 把 MySQL 写压力打爆。
 *
 * ⚠️ 必须与 market:huobi / market:binance 同时启停，否则行情只进 Redis 不落库，
 * MySQL 里的价格会冻结在最后一次直连写入的值上。
 *   php think market:db_flush start -d
 */
class MarketDbFlusher extends Command
{
    use SingletonGuardTrait;
    // 全仓最可能把事件循环占住的就是本进程（同步批量写 MySQL），此前却是唯一没接
    // busy 统计的行情常驻进程 —— 「它把循环占了多久」只能靠猜
    use \app\lib\EventLoopBusyTrait;

    /**
     * 单轮落库的默认行数上限。
     *
     * 事件循环是单线程的，一轮取多少直接决定它被占住多久，而心跳 key 只有 15 秒寿命
     * （interval×3）—— 一轮超过它，进程就会被判成「事件循环卡死」，哪怕它只是在补积压。
     * 取 500 与下面 `dirtyMd > 500` 那条积压告警同一个量级，两处口径一致。
     */
    private const DEFAULT_BATCH = 500;

    protected function configure()
    {
        $this->setName('market:db_flush')
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run in daemon mode')
            ->addOption('interval', null, Option::VALUE_REQUIRED, '落库间隔 (秒)', '1')
            ->addOption('batch', null, Option::VALUE_REQUIRED, '单轮最多写多少行，0=不限', (string)self::DEFAULT_BATCH)
            ->setDescription('Flush Redis-staged market data (price/mark price) to MySQL in batches');
    }

    /** 失败事件的节流状态：['err' => 上次报的原因, 'at' => 上次上报时刻, 'n' => 期间累计次数] */
    private static array $failEcho = ['err' => '', 'at' => 0.0, 'n' => 0];

    /** 同一个原因最多多少秒上报一次事件 */
    private const FAIL_EVENT_INTERVAL = 30;

    /**
     * 把落库失败写进事件流水，带节流：原因变了立刻报，没变则最多 30 秒一条并带上累计次数。
     *
     * 节流键取错误**类型**（SQLSTATE）不取错误全串——MySQL 异常带着具体行的值，
     * 按全串比等于每行都是新情况，节流会形同虚设，而事件流水只留 500 条、很容易被刷爆。
     */
    private static function emitFailEvent(int $n, string $err): void
    {
        self::$failEcho['n'] += $n;
        $now = microtime(true);
        $kind = self::failKind($err);
        $changed = $kind !== self::$failEcho['err'];

        if (!$changed && ($now - self::$failEcho['at']) < self::FAIL_EVENT_INTERVAL) return;

        \app\lib\MarketMetrics::event('db_flush_fail', sprintf(
            'batchUpdate 失败 %d 次%s，该轮的值已跳过(不重试，等下个 tick) | %s',
            self::$failEcho['n'],
            $changed ? '' : sprintf('(近 %ds 累计)', self::FAIL_EVENT_INTERVAL),
            $err ?: '(无错误详情)'
        ));
        self::$failEcho = ['err' => $kind, 'at' => $now, 'n' => 0];
    }

    /**
     * 提取错误类型作为节流键：优先 SQLSTATE，其次异常类名，都取不到就用前 60 字符
     * （行级明细一般排在后面，前缀通常还是稳定的「哪张表哪个操作」）
     */
    private static function failKind(string $err): string
    {
        if ($err === '') return '';
        if (preg_match('#SQLSTATE\[[0-9A-Za-z]+\]#', $err, $m)) return $m[0];
        if (preg_match('#^[A-Za-z_\\\\]+(?:Exception|Error)#', $err, $m)) return $m[0];
        return mb_substr($err, 0, 60);
    }

    /** 10 秒窗口的落库统计，见下面 stat 定时器的注释 */
    private static array $stat = ['rounds' => 0, 'md' => 0, 'pair' => 0, 'ms' => 0.0, 'ms_max' => 0.0, 'fail' => 0, 'err' => ''];

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        global $argv;
        $argv[1] = $action;
        if (isset($argv[2])) unset($argv[2]);

        WorkerService::init('market_relay_MarketDbFlusher');
        // 单例守卫：已有一套在跑就拒绝再起一套。必须在 runAll() 之前——
        // 放进 onWorkerStart 里 worker 退出只会被 master 重新拉起
        $this->guardSingleton('market_relay_MarketDbFlusher', (string)$action, $output);

        if ($input->getOption('daemon')) {
            Worker::$daemonize = true;
        }

        $interval = max(0.2, (float)$input->getOption('interval'));
        $batch    = max(0, (int)$input->getOption('batch'));

        $worker = new Worker();
        // 本 worker 的运维坐标（可直接照抄进 redis-cli）：
        //   heartbeat:cmd:market_relay_MarketDbFlusher      心跳，TTL 15s，值为 JSON{pid,tick,ts,tasks}
        //   heartbeat:stopping:market_relay_MarketDbFlusher 正常停机标记，TTL 60s，值为 pid
        //   heartbeat:inst:market_relay_MarketDbFlusher     HASH，field={主机名}#{pid}，值为毫秒时间戳
        //   market:metrics:worker:market_relay_MarketDbFlusher  HASH，进程指标
        //   runtime/market-relay_MarketDbFlusher.pid、runtime/log/market-relay_MarketDbFlusher.log
        //   名字同时进 SET heartbeat:registry、market:metrics:workers
        $worker->name  = 'market_relay_MarketDbFlusher';
        $worker->count = 1;

        $worker->onWorkerStart = function ($worker) use ($output, $interval, $batch) {
            CommandHeartBeatService::start($worker);

            try {
                Db::close();
            } catch (\Exception $e) {
            }
            Config::set(['break_reconnect' => true], 'database.connections.mysql');

            // 包 timedHandler：本进程的 busy% 就是靠它算出来的，同时顺手给刷盘主循环
            // 留下任务存活证据 —— 一轮阻塞多久在 tasks 的 ms 里直接可见
            Timer::add($interval, $this->timedHandler('flush', function () use ($output, $batch) {
                try {
                    Db::query('SELECT 1');
                } catch (\Throwable $e) {
                    Db::close();
                }

                $t0 = microtime(true);
                try {
                    $result = MarketService::flushDirty($batch);
                } catch (\Throwable $e) {
                    // 这个 catch 是为了把异常捞回来记账。Workerman 会吞掉定时器异常
                    // （进程不崩，但这一轮的指标上报全被跳过 = 静默失败），
                    // 而落库失败是可预期的（MySQL 抖动/主从切换），必须计入 fail 让监控页看得见
                    \think\facade\Log::error('[MarketDbFlusher] flushDirty: ' . $e->getMessage());
                    $result = ['md' => 0, 'pair' => 0];
                    self::$stat['fail']++;
                }

                // K 线缓冲 2026-08-28 从三个进程的内存搬到 Redis，落库随之收口到本进程。
                // 与 flushDirty 同一个 tick、各自 try：K 线那边死锁退回是常态（见 flushKlineBuffer 注释），
                // 不能让它把热层落库一起带崩。两者写的是不同的表，没有先后依赖
                try {
                    MarketService::flushKlineBuffers($batch);
                } catch (\Throwable $e) {
                    \think\facade\Log::error('[MarketDbFlusher] flushKlineBuffers: ' . $e->getMessage());
                    self::$stat['fail']++;
                }
                $ms = (microtime(true) - $t0) * 1000;

                $failed = MarketService::takeFlushFail();
                if ($failed['n'] > 0) {
                    self::$stat['fail'] += $failed['n'];
                    self::$stat['err']   = $failed['err'];
                    // 监控页的告警条读的是事件流水，只写 business_log 没人会看到。
                    // 必须带原因——最常见的失败是「热层里有个 field 不是表的列」，只报次数看不出来
                    self::emitFailEvent($failed['n'], $failed['err']);
                }

                self::$stat['rounds']++;
                self::$stat['md']   += $result['md'];
                self::$stat['pair'] += $result['pair'];
                self::$stat['ms']   += $ms;
                self::$stat['ms_max'] = max(self::$stat['ms_max'], $ms);

                if (!\Workerman\Worker::$daemonize && ($result['md'] > 0 || $result['pair'] > 0)) {
                    $output->writeln("<info>[Flush]</info> market_data={$result['md']} exchange_pair={$result['pair']}");
                }
            }));

            // ── 观测 ──────────────────────────────────────────────────────────
            // 上报三个数，各回答一个问题：
            //   dirty  脏集合积压，持续增长 = 落库跟不上生产
            //   ms     单轮耗时，接近 interval 就快撑不住了
            //   fail   写失败次数，稳态恒为 0；>0 说明有行没写进去且不重试
            //          （逐个主键记在 market_db_flush_stat，见 MarketService::logFlushFail）
            //
            // 回调必须包 guardStat：第一行就是 Redis 调用，Redis 一抖异常会被 Workerman 吞掉、
            // 指标永久冻结，监控页会把「观测坏了」误报成「进程卡死」。
            // 下面的豁免注释必须**紧贴** Timer::add，挤开一行 xdata:check 就会报
            // @no-task-report: 它自己就是上报器，停了由 metric_age 判出来
            Timer::add(10, function () use ($output, $interval, $worker, $batch) {
                \app\lib\MarketMetrics::guardStat($worker->name, function () use ($output, $interval, $batch) {
                $redis = redis_client();
                $dirtyMd   = $redis ? (int)$redis->sCard(MarketService::DIRTY_MD_KEY) : -1;
                $dirtyPair = $redis ? (int)$redis->sCard(MarketService::DIRTY_PAIR_KEY) : -1;

                $r = self::$stat;
                $avg = $r['rounds'] > 0 ? $r['ms'] / $r['rounds'] : 0.0;
                $snap = self::loopBusySnapshot(10.0);

                // K 线落库开销：2026-08-28 缓冲搬 Redis 后这部分归本进程。
                // rows 与 ms 要分开看 —— busy 高到底是**行多**还是**每行慢**，
                // 前者调 interval/batch 能摊薄，后者是锁竞争或索引问题，调周期只会让每轮更重
                $kp = MarketService::klinePending();
                $kf = MarketService::takeKlineFlushStat();
                $kfPart = $kf['rounds'] > 0 ? sprintf(
                    ' kline(pending=%d/%d rounds=%d rows=%d chunks=%d ms(avg=%.1f max=%.1f))',
                    $kp['src'], $kp['pair'],
                    $kf['rounds'], $kf['rows'], $kf['chunks'],
                    $kf['ms'] / $kf['rounds'], $kf['ms_max']
                ) : sprintf(' kline(pending=%d/%d)', $kp['src'], $kp['pair']);

                $line = sprintf(
                    'dirty(md=%d pair=%d) flushed(md=%d pair=%d) rounds=%d ms(avg=%.1f max=%.1f) fail=%d interval=%.1fs busy=%.1f%% batch=%d%s%s',
                    $dirtyMd, $dirtyPair, $r['md'], $r['pair'], $r['rounds'], $avg, $r['ms_max'], $r['fail'], $interval,
                    $snap['pct'], $batch, $kfPart,
                    $r['err'] !== '' ? ' | ' . $r['err'] : ''
                );
                // 积压持续超过一轮的量、或有写失败，都要能在日志里被 grep 到
                business_log('market_db_flush_stat', $line,
                    ($r['fail'] > 0 || $dirtyMd > 500) ? 'warning' : 'info');
                if (!\Workerman\Worker::$daemonize) {
                    $output->writeln("<comment>[Flush][stat]</comment> {$line}");
                }

                \app\lib\MarketMetrics::putWorker('market_relay_MarketDbFlusher', [
                    'dirty_md'     => $dirtyMd,
                    'dirty_pair'   => $dirtyPair,
                    'md_10s'       => $r['md'],
                    'pair_10s'     => $r['pair'],
                    'rounds_10s'   => $r['rounds'],
                    'flush_ms_avg' => round($avg, 1),
                    'flush_ms_max' => round($r['ms_max'], 1),
                    'flush_fail'   => $r['fail'],
                    // 原因跟着次数一起给监控页，省得再去机器上 grep 日志
                    'flush_error'  => $r['err'],
                    // K 线两个缓冲：pending 持续增长 = 落库跟不上生产（与 dirty_* 同义）；
                    // kline_rows_10s 恒 0 而 pending>0 就是刷盘没在跑
                    'kline_pending_src'  => $kp['src'],
                    'kline_pending_pair' => $kp['pair'],
                    'kline_rows_10s'     => $kf['rows'],
                    'kline_ms_avg'       => $kf['rounds'] > 0 ? round($kf['ms'] / $kf['rounds'], 1) : 0.0,
                    'kline_ms_max'       => round($kf['ms_max'], 1),
                    'interval'     => $interval,
                    'batch_limit'  => $batch,
                    // busy_pct 持续偏高 = 落库把循环占住了，心跳随时可能排不上队被误判成卡死
                    'busy_pct'     => round($snap['pct'], 1),
                    'busy_detail'  => $snap['detail'],
                    'tasks'        => json_encode(self::taskSnapshot(), JSON_UNESCAPED_UNICODE),
                ]);

                self::$stat = ['rounds' => 0, 'md' => 0, 'pair' => 0, 'ms' => 0.0, 'ms_max' => 0.0, 'fail' => 0, 'err' => ''];
                self::loopBusyReset();

                // 顺手清一次过期指标，搭这个定时器的车但内部有 1 小时最小间隔
                // （它要 hGetAll 整个指标 hash，不能按 10 秒的节奏跑）。
                // 挂在本进程是因为它是这条链路上唯一「每台机器都有且一直在跑」的常驻进程
                $pruned = \app\lib\MarketMetrics::pruneStale();
                if ($pruned['group'] > 0 || $pruned['worker'] > 0) {
                    business_log('market_db_flush_stat', sprintf(
                        '[指标清理] 删除过期数据组 field %d 个、下线进程 %d 个（超过保留期没有任何生产者更新过）',
                        $pruned['group'], $pruned['worker']
                    ), 'info');
                }
                });
            });
        };

        Worker::runAll();
    }
}
