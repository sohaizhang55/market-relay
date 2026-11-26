<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Workerman\Worker;
use think\facade\Db;
use app\enum\MarketExchangeEnum;
use app\enum\MarketDataTypeEnum;
use app\enum\MarketChannelEnum;
use app\lib\WorkerService;
use app\lib\CommandHeartBeatService;
use app\lib\MarketPublisher;
use app\lib\MarketHelper;

/**
 * 单交易所行情直连采集服务基类：纯 WS 采集 + 落库 + 分发入队。
 *
 * 子类实现  exchange() 交易所标识 · startCollectors() 该交易所的 WS 连接与订阅协议
 * 子类沿用  Worker 编排 · 观测埋点 · handleXxx() 落库与分发骨架
 * 子类      MarketHuobi · MarketBinance · MarketBingx
 *
 * 命令  php think market:{exchange}
 * 触发  systemd market_relay-market-{exchange}-{type}.service
 * 单例  pid 文件 runtime/market-relay_market_{exchange}_{type}.pid（见 SingletonGuardTrait）
 * 依赖  配置 market.debug_source_keys · 表 market_data
 *
 * 用法:
 *   php think market:huobi start -t 1 -d         # 现货采集，落本机 MarketService
 *   php think market:huobi start -t 2 -d         # 合约采集
 *   php think market:huobi status -t 1           # 看进程状态
 *
 *   php think market:huobi stop -t 1
 *
 * 采集器只管把帧交给 MarketService：它更新源层与 pair 层，每次 pair 更新后写 pair 快照（分发协议 v3），
 * 由 market:dispatch 广播。
 *
 * 价格 REST 兜底在 market:price_fallback、K线补数在 market:kline_backfill，资金费率取数在 market:funding：
 * 同步 MySQL 扫描 + 阻塞 curl 会堵住 WS 事件循环，那段时间本机行情全部停止更新。
 */
abstract class MarketCollectorAbstract extends Command
{
    use SingletonGuardTrait;

    use \app\lib\EventLoopBusyTrait;

    // WS 重连退避（2026-08-19 火币 429 事故）：原先固定 5 秒重连会一直硬撞被限流的 host，
    // 限流窗口永远刷不掉；火币 WS 与 REST 共用 api.hbdm.com 配额，还会连带拖死 REST 兜底
    // （REST 侧冷却见 MarketHelper::inRateLimitCooldown）。抖动是必需的：一台机 Huobi 有
    // 3 条 WS（Spot/Swap/MarkPrice），同时断开时无抖动会集体在同一秒重连成周期性脉冲。

    /** 首次重连延迟(秒)，也是退避的基数 */
    protected const WS_RECONNECT_BASE_DELAY = 5;

    /** 退避上限(秒)。5min 足以让绝大多数交易所的限流窗口过期，又不至于长时间不重试 */
    protected const WS_RECONNECT_MAX_DELAY = 300;

    /** 连接标识 => 连续失败次数 */
    protected array $wsReconnectFails = [];

    /**
     * 计算某条 WS 连接本次的重连延迟：base * 2^n 截顶后取 [50%, 100%] 区间的随机值。
     * 每调用一次即视为又失败一次，由 onClose 调用。
     *
     * @param string $connKey 连接标识，如 'huobi:swap' / 'huobi:mark_price'，各连接独立计数
     */
    protected function nextReconnectDelay(string $connKey): int
    {
        $fails = $this->wsReconnectFails[$connKey] ?? 0;
        $this->wsReconnectFails[$connKey] = $fails + 1;

        $delay = min(
            self::WS_RECONNECT_BASE_DELAY * (2 ** min($fails, 16)), // 先截指数再乘，防大数溢出
            self::WS_RECONNECT_MAX_DELAY
        );

        // 半抖动：保底一半延迟，另一半随机，既散开脉冲又不会退化成"几乎立刻重连"
        return (int)max(1, $delay / 2 + mt_rand(0, (int)($delay * 500)) / 1000);
    }

    /**
     * 连接确认可用后清零退避计数，由 onMessage(真正收到数据) 调用。
     *
     * 刻意不放 onConnect：握手成功不等于连接可用，交易所可能握手后立刻断开，
     * 那样退避会永远停在第 1 级，等于没有退避。
     */
    protected function resetReconnectBackoff(string $connKey): void
    {
        unset($this->wsReconnectFails[$connKey]);
    }

    /** 行情数据帧静默多久就强制重连（秒） */
    protected const WS_SILENCE_RECONNECT_SEC = 90;

    /** 一次连接内逐条记录的订阅错误上限，之后只计数：订阅一次几百条，全被拒时不刷满日志 */
    private const SUB_ERROR_LOG_LIMIT = 20;

    /**
     * 看门狗：行情数据帧静默超过 WS_SILENCE_RECONNECT_SEC 就强制重连，并写业务日志。
     *
     * 判据是数据帧时间，不是任意下行帧：交易所心跳（火币 ping、BingX Ping）在订阅丢失后照发，
     * 按任意帧判断会把「连接活着、数据停了」当成正常（2026-11 火币合约整批停 4 小时未重连即此）。
     * 日志里带上心跳时间，事后能区分是「连接活着、数据停了」还是「连接本身断了」。
     *
     * @param int $lastDataTime 最后一个行情数据帧的秒级时间戳
     * @param int $lastMsgTime  最后一个任意下行帧（含心跳）的秒级时间戳
     */
    protected function watchdogCheck(\Workerman\Connection\AsyncTcpConnection $con, string $connName, int $lastDataTime, int $lastMsgTime, Output $output): void
    {
        $dataAge = time() - $lastDataTime;
        if ($dataAge <= self::WS_SILENCE_RECONNECT_SEC) return;

        $msgAge = time() - $lastMsgTime;
        $state = $msgAge <= self::WS_SILENCE_RECONNECT_SEC
            ? "心跳仍在（最后一帧 {$msgAge} 秒前）：连接活着但没有行情数据，订阅可能丢了或被拒"
            : "心跳也停了（最后一帧 {$msgAge} 秒前）：连接本身断了";
        business_log('market_collect_stat', "[{$connName}] {$dataAge} 秒没有行情数据帧，强制重连。{$state}", 'warning');
        if (!Worker::$daemonize) {
            $output->writeln("<error>[{$connName}]</error> No data for {$dataAge}s ({$state}), forcing reconnect...");
        }
        $con->close();
    }

    /**
     * 记录一条订阅被拒：每次连接前 SUB_ERROR_LOG_LIMIT 条逐条写业务日志，之后只在达到上限时提示一次。
     *
     * @param string $detail 交易所返回的错误内容（原样，便于对照交易所文档）
     * @param int    $count  本次连接已记录的订阅错误数，onConnect 时清零
     */
    protected function logSubError(string $connName, string $detail, int &$count): void
    {
        $count++;
        if ($count <= self::SUB_ERROR_LOG_LIMIT) {
            business_log('market_collect_stat', "[{$connName}] 订阅被拒 #{$count}：{$detail}", 'error');
        } elseif ($count === self::SUB_ERROR_LOG_LIMIT + 1) {
            business_log('market_collect_stat',
                "[{$connName}] 订阅被拒已超过 " . self::SUB_ERROR_LOG_LIMIT . ' 条，本次连接后续的不再逐条记录', 'error');
        }
    }

    /**
     * 本采集器对应的交易所
     */
    abstract protected function exchange(): MarketExchangeEnum;

    /**
     * 建立该交易所的 WebSocket 连接并订阅指定 symbols（各交易所自行实现协议细节）
     */
    abstract protected function startCollectors(Output $output, int $type, array $symbols): void;

    protected function configure()
    {
        $this->setName('market:' . $this->exchange()->value)
            ->addArgument('action', Argument::OPTIONAL, "start|stop|restart|reload|status", 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run in daemon mode')
            ->addOption('type', 't', Option::VALUE_REQUIRED, 'Specify type (1:Spot, 2:Swap)')
            ->setDescription('Market Data Crawler Service - ' . $this->exchange()->value);
    }

    /**
     * 采集器的 worker 名。定义只留这一处：判活侧要按 market_data 推算「本机该有哪几个采集器」
     * （见 MarketWorkers::collectors），两边各拼一遍必然漂移成恒定误报或恒定漏报。
     */
    public static function workerName(string $exchange, int $type): string
    {
        $typeName = $type === MarketDataTypeEnum::SPOT->value ? 'Spot' : 'Swap';
        return "market_relay_Crawler_{$exchange}_{$typeName}";
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        global $argv;
        $argv[1] = $action;
        if (isset($argv[2])) unset($argv[2]);

        $targetType = $input->getOption('type');
        if (!$targetType) {
            $output->writeln("<error>Usage Error: --type (-t) is REQUIRED.</error>");
            $output->writeln("Example: php think market:{$this->exchange()->value} start -t 1");
            return;
        }

        $exchange = $this->exchange()->value;
        $taskName = "market_relay_market_{$exchange}_{$targetType}";

        WorkerService::init($taskName);

        if ($input->getOption('daemon')) {
            Worker::$daemonize = true;
        }

        $symbols = Db::name('market_data')
            ->where('exchange', $exchange)
            ->where('type', $targetType);


        // 默认值必须是 [] 且要 array_filter 掉空串：(array)"" 得到 [""] 是**非空数组**，
        // 会让下面的 if 恒为真、whereIn('source_key', ['']) 匹配不到任何行 → Worker 拒绝启动。
        // 而 config/market.php 在 .gitignore 里不上生产，生产端很可能正好没有这一项
        $debugSourceKeys = array_filter((array)\think\facade\Config::get('market.debug_source_keys', []));
        if ($debugSourceKeys) {
            $symbols->whereIn('source_key', $debugSourceKeys);
            // 用 implode 而不是直接拼数组：拼接得到字面量 "Array"，且 PHP8 起会抛
            // Array to string conversion 警告（被 ThinkPHP 错误处理器转成异常）
            $output->writeln('<comment>[Market] DEBUG_SOURCE_KEYS 白名单生效，仅订阅: ' . implode(',', $debugSourceKeys) . '</comment>');
            // writeln 是前台输出，daemon 下没人看得到；而这个开关能同时砍掉采集、回补与
            // 心跳判活三处，留不下痕迹就是一台"看起来在跑、其实只跑一个源"的机器
            business_log('market_collect_stat',
                '[启动] debug_source_keys 白名单生效，仅订阅 ' . implode(',', $debugSourceKeys)
                . ' —— 其余源全部不采集；回补与心跳判活也被同一份配置收窄', 'warning');
        }

        $symbols = $symbols->column('symbol');

        if (empty($symbols)) {
            $output->writeln("<error>[Market] 未找到 exchange={$exchange} type={$targetType} 的 market_data 配置（或被 debug_symbols 白名单过滤为空），Worker 未启动。</error>");
            return;
        }

        $worker        = new Worker();
        // Redis 侧运维坐标（可照抄进 redis-cli），name = market_relay_Crawler_{huobi|binance}_{Spot|Swap}：
        //   heartbeat:cmd:{name}       心跳，TTL 15s，值为 JSON{pid,tick,ts,tasks}
        //   heartbeat:stopping:{name}  正常停机标记，TTL 60s，值为 pid
        //   heartbeat:inst:{name}      HASH，field={主机名}#{pid}，值为毫秒时间戳
        //   market:metrics:worker:{name}  HASH，进程指标
        //   name 同时进 SET heartbeat:registry、market:metrics:workers
        // ⚠️ pid 与日志用的是 $taskName，**和上面这套名字不一样**：
        //   runtime/market-relay_market_{exchange}_{type}.pid、runtime/log/同名 .log
        $worker->name  = self::workerName($exchange, (int)$targetType);
        // 单例守卫必须在 runAll() 之前：放进 onWorkerStart 时 master 已 fork 完，
        // worker 退出只会被 master 重新拉起。判据是「归不归本命令的 pid 文件管」而不是
        // 「有没有别人在跑」，否则每次正常 restart 都会被自己拒掉，见 SingletonGuardTrait
        $this->guardSingleton($worker->name, (string)$action, $output);
        $worker->count = 1;

        $worker->onWorkerStart = function () use ($output, $targetType, $symbols, $worker) {
            CommandHeartBeatService::start($worker);

            $this->initWorkerEnv();

            $this->statLastFlush = microtime(true);
            $this->startCollectors($output, (int)$targetType, $symbols);

            // 成交合并写入，见 handleTrade()。timedHandler 让这部分开销单独记进 trade_flush 占用分桶
            \Workerman\Timer::add(self::TRADE_FLUSH_MS / 1000, $this->timedHandler('trade_flush', fn() => $this->flushTrades()));

            // 源的 WS 新鲜度每秒落 Redis 一次，主备切换靠它判断流（10 秒一刷的指标太粗，见 MarketSourceHealth）
            \Workerman\Timer::add(1, fn() => \app\lib\MarketSourceHealth::flush());
            // 帧间隔统计每 10 分钟落一次，后台「原始行情」页调断流秒数用（不依赖选源进程是否启用）
            \Workerman\Timer::add(600, fn() => \app\lib\MarketSourceHealth::flushStats());


            // 观测指标定期刷盘：touchCollect() 热路径只累加内存，真正写 Redis 只发生在这里
            \Workerman\Timer::add(10, function () use ($targetType, $symbols, $worker, $output) {
                // 整体包一层：Workerman 会吞掉定时器异常并继续调度，异常若发生在 putWorker()
                // 之前，指标就永久冻结而进程毫发无损 —— 见 guardStat() 注释
                \app\lib\MarketMetrics::guardStat($worker->name, function () use ($targetType, $symbols, $worker, $output) {
                \app\lib\MarketMetrics::flushGroups();
                // trade 滚动窗口溢出 = 真正被丢弃的成交笔数（口径见 MarketPublisher::countTradeOverflow）。
                //
                // 这是**信息不是告警**：容量 = trade_window ÷ tick_ms（默认 30 笔 ÷ 0.2s
                // = 150 笔/秒/source_key，口径与 MarketMonitor::tradeOverflowNote() 一致），
                // 币安现货 BTC 常年超标，活跃对上溢出是结构性常态。调大 trade_window 收益有限
                // （下游 updateTrade() 同样只留 trade_window 笔），调小 tick_ms 代价更大
                // （10 倍分发频次，出口带宽本就是瓶颈）。成交流唯一消费方是 getTradesById()
                // 喂给「最近成交」列表，不做 VWAP、不进结算、不参与定价。
                // 只有确实要让前端显示更多成交时才动 trade_window（A/B 两侧共用同一配置项）。
                $overflow = MarketPublisher::takeTradeWindowOverflow();
                if ($overflow > 0) {
                    // 频道原来错写成 market_dispatch_stat（那是**分发机**的日志），于是巡检
                    // 取分发 stat 最后一行时常拿到采集器这条，里面没有 subs=/kicked(
                    business_log('market_collect_stat',
                        "[{$worker->name}] trade_window_overflow={$overflow}"
                        . ' (活跃交易对常态：容量=trade_window÷tick_ms，超出部分下游本也只留 trade_window 笔)',
                        'info');
                }

                // 三项现场指标，回答同一问题的三个侧面「采集进程是不是跟不上了」：
                //   age    帧龄。恒定偏大多半是时钟偏差；单调增长才是排队
                //   busy   事件循环占用率。socket 只在循环回到 select 时才继续被读
                //   recvq  内核接收队列。>0 且持续就是实打实的堆积 —— 唯一的铁证
                // 要一起看：age 大 + recvq 0 + busy 低 ⇒ 时钟或上游慢，不是我们的问题
                $window = max(0.001, microtime(true) - $this->statLastFlush);
                $this->statLastFlush = microtime(true);

                $snap  = self::loopBusySnapshot($window);
                $recvq = \app\lib\KernelSocketQueue::forSelf();

                $ageParts = [];
                $agePayload = [];
                foreach ($this->ageStat as $chan => $a) {
                    if ($a['n'] <= 0) continue;
                    $avg = $a['ms'] / $a['n'];
                    $short = MarketChannelEnum::shorten((string)$chan);
                    $ageParts[] = sprintf('%s=%.0f/%.0fms', $short, $avg, $a['max']);
                    $agePayload[$short] = ['avg' => round($avg), 'max' => round($a['max']), 'n' => $a['n']];
                }

                $line = sprintf(
                    '[%s] busy=%.1f%%%s recvq=%s age(%s) symbols=%d%s',
                    $worker->name, $snap['pct'], $snap['detail'],
                    $recvq['rx'] < 0 ? 'n/a' : sprintf('%dB/%dconn', $recvq['rx'], $recvq['conns']),
                    $ageParts ? implode(' ', $ageParts) : '-',
                    count($symbols),
                    $overflow > 0 ? " trade_overflow={$overflow}" : ''
                );
                // warning 线与监控页卡片、judgeHealth 共用 MarketMetrics 阈值 ——
                // "日志报 warning"与"卡片变黄"必须同一时刻，否则得在脑子里换算
                business_log('market_collect_stat', $line,
                    ($snap['pct'] > \app\lib\MarketMetrics::BUSY_WARN_PCT) ? 'warning' : 'info');
                if (!Worker::$daemonize) {
                    $output->writeln("<comment>[collect][stat]</comment> {$line}");
                }

                \app\lib\MarketMetrics::putWorker($worker->name, [
                    // 任务存活证据：桶名 => {last, runs, ms}，见 EventLoopBusyTrait::taskSnapshot()
                    'tasks' => json_encode(self::taskSnapshot(), JSON_UNESCAPED_UNICODE),
                    'exchange'       => $this->exchange()->value,
                    'market_type'    => (int)$targetType,
                    'symbol_count'   => count($symbols),
                    'symbols'        => json_encode(array_slice($symbols, 0, 60)),
                    'trade_overflow' => $overflow,
                    'busy_pct'       => round($snap['pct'], 1),
                    // 分桶明细也要上卡片，否则只知道忙、不知道忙在哪
                    'busy_detail'    => $snap['detail'],
                    // 内核接收队列，与帧龄是两回事：帧龄含时钟偏差，这一项不含，
                    // 它只反映"字节到了但我们还没读"。-1 = 本平台读不到
                    'recvq_bytes'    => $recvq['rx'],
                    'recvq_conns'    => $recvq['conns'],
                    'age'            => json_encode($agePayload),
                ]);

                $this->ageStat = [];
                self::loopBusyReset();
                });
            });
        };

        Worker::runAll();
    }

    /**
     * 初始化 Worker 运行环境 (DB连接等)
     */
    protected function initWorkerEnv()
    {
        try {
            \think\facade\Db::close();
        } catch (\Exception $e) {
        }
        \think\facade\Config::set(['break_reconnect' => true], 'database.connections.mysql');
    }

    /**
     * DB 心跳检测：连接断开则关闭让框架自动重连
     */
    protected function pingDb(): void
    {
        try {
            \think\facade\Db::query('SELECT 1');
        } catch (\Throwable $e) {
            \think\facade\Db::close();
        }
    }

    // 以下：写入本机 MarketService，由它在 pair 层顺带写分发快照

    // ⚠️ touchCollect / touchLand / touchAge 三个都是**监控埋点，不是业务逻辑**。
    // 全部删掉行情照样跑，只是监控页和落库链路诊断页会瞎掉 —— 所以里面永远不要加
    // 有副作用的东西（写库、发通知、抛异常），也不要让业务分支依赖它们的返回。
    // 三者答两个不同问题：
    //   touchCollect / touchLand  数据在链路的哪一跳停了 → Redis group:{scope}
    //                             scope 约定表与不变量见 MarketMetrics::touchGroup()
    //   touchAge                  数据有多旧、我们跟不跟得上 → worker 指标的 age 字段
    // 命名统一用 touchXxx 前缀，就是为了在 handleXxx 里一眼从业务调用中挑出来。

    /**
     * [埋点·scope=collect] 记一笔「交易所实际推过来的数据」，只动进程内数组，
     * 由 stat 定时器批量刷进 Redis。
     *
     * 落库/分发的速率是我们自己定的，看不出交易所那边是不是断了；只有这一路才能回答
     * 「BTCUSDT 的深度是不是已经 30 秒没更新了」。
     *
     * 刻意不改成从落库时间戳反推（试过，只对一半频道成立）：price/mark_price 可以，
     * depth 的 ts 限频 1 秒勉强够用，trade 里只有每笔成交自己的时间（冷门对会报假断流，
     * MarketDispatcher 的 age 采样已踩过同一个坑），kline 在 Redis 里没有任何新鲜度标记。
     * 删掉埋点后 trade/kline 两列会变空白，看着更像「采集断了」。
     */
    protected function touchCollect(MarketChannelEnum $channel, string $sourceKey, string $suffix = ''): void
    {
        \app\lib\MarketMetrics::touchGroup(
            \app\lib\MarketMetrics::SCOPE_COLLECT,
            $channel->value,
            $sourceKey,
            0,
            $suffix
        );
    }

    /**
     * [埋点·scope=land] 记一笔「已写进本机 MarketService」。
     *
     * 采集与落库在同一个进程里，本机就是链路终点 —— 采集器不打 land 就没有任何人会打，
     * 于是首页行情卡 total_groups 恒为 0（整张卡永久隐藏）、落库链路诊断页 land 列恒空。
     * 调用位置与 land 语义一致：**updateXxx 之后**，不是收到帧的时候。
     */
    protected function touchLand(MarketChannelEnum $channel, string $sourceKey, string $suffix = ''): void
    {
        \app\lib\MarketMetrics::touchGroup(
            \app\lib\MarketMetrics::SCOPE_LAND,
            $channel->value,
            $sourceKey,
            0,
            $suffix
        );
    }

    /** 上一次 stat 刷新的时刻，用于算事件循环占用率的窗口长度 */
    private float $statLastFlush = 0.0;

    /**
     * 单个 source_key 在一个合并间隔内最多缓存的成交笔数，超出丢最旧的。
     * 远大于常态：超过 trade_window 的部分下游本就只留最新的，这里只防内存失控
     */
    private const TRADE_BUFFER_MAX = 2000;

    /**
     * 成交合并写入间隔(毫秒)：这段时间内收到的成交先攒在内存，到点由 flushTrades() 一次性写入。
     * 与 market.dispatch.tick_ms 默认值一致。刻意不做成配置：逐帧写入的旧路径已删除，没有可切回的档位
     */
    private const TRADE_FLUSH_MS = 100;

    /**
     * @var array<string,array{exchange:MarketExchangeEnum,type:int,symbol:string,items:array}>
     *      source_key => 待写入的成交，按到达顺序
     */
    private array $tradeBuffer = [];

    /**
     * @var array<string,array{n:int,ms:float,max:float}> 频道 => 帧龄样本。
     * 帧龄 = 本机收到的时刻 − 帧上交易所打的时间戳。
     */
    private array $ageStat = [];

    /**
     * [埋点·worker.age] 记一次帧龄。与 scope 体系无关，不进 group HASH。
     *
     * 这个数把时钟偏差 + 网络传输 + 接收排队合在一起，三者分不开，但正因如此它是最灵敏的
     * 单一指标：稳态应在百毫秒级，稳定偏大则三者必有一坏。恒定偏移多半是时钟，
     * **单调增长**才是接收排队（配合 recvq 确认）。
     *
     * ⚠️ 只能采「ts 语义 = 这份数据何时生成」的频道。kline 的 id 是蜡烛起始时刻、trade 的
     * ts 是每笔成交自己的时间，采进来会得到吓人却无意义的数字 —— 分发机侧已踩过这个坑。
     */
    private function touchAge(MarketChannelEnum $channel, int $exchangeTsMs): void
    {
        if ($exchangeTsMs <= 0) return;
        $age = microtime(true) * 1000 - $exchangeTsMs;
        // 负值只可能来自时钟偏差(本机慢于交易所)，照样记 —— 它同样是要暴露的信号
        $k = $channel->value;
        if (!isset($this->ageStat[$k])) $this->ageStat[$k] = ['n' => 0, 'ms' => 0.0, 'max' => 0.0];
        $this->ageStat[$k]['n']++;
        $this->ageStat[$k]['ms'] += $age;
        $this->ageStat[$k]['max'] = max($this->ageStat[$k]['max'], $age);
    }

    /**
     * 全部 handleXxx() 的统一骨架，四步固定顺序（只在这里写一次，下面几个不重复）：
     *
     *   1. touchCollect  埋点，收到帧就记，不管后面成不成
     *      price / depth 另记一笔 MarketSourceHealth::touch() —— 这一笔**不是埋点**，主备切换靠它判断流
     *   2. touchAge      埋点，仅 price / mark_price / depth 采，理由见 touchAge() 的 ⚠️
     *   3. updateXxx     **唯一的业务行** —— 落库与本机热层，也在这一步写 pair 分发快照
     *   4. touchLand     埋点，必须在第 3 步之后，见 MarketMetrics::touchGroup() 不变量 ②
     *
     * 4 行里 3 行是埋点，删掉 1/2/4 功能完全不变。改这里时先分清动的是哪一类：
     * 埋点顺序错了只是监控页失真，第 3 步错了是真丢数据。
     */
    protected function handlePrice(MarketExchangeEnum $exchange, string $symbol, int $type, array $tick, $ts): void
    {
        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);
        $this->touchCollect(MarketChannelEnum::PRICE, $sourceKey);
        \app\lib\MarketSourceHealth::touch(MarketChannelEnum::PRICE, $sourceKey);
        $this->touchAge(MarketChannelEnum::PRICE, (int)$ts);
        \app\business\service\MarketService::updatePrice($exchange, $symbol, $type, $tick, $ts);
        $this->touchLand(MarketChannelEnum::PRICE, $sourceKey);
    }

    protected function handleKline(MarketExchangeEnum $exchange, int $type, string $symbol, string $period, array $kline): void
    {
        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);
        $this->touchCollect(MarketChannelEnum::KLINE, $sourceKey, (string)$period);
        \app\business\service\MarketService::updateSourceKline($exchange, $type, $symbol, $period, $kline);
        $this->touchLand(MarketChannelEnum::KLINE, $sourceKey, (string)$period);

        // ⚠️ K 线的 close 恒为「收盘前最后一次采样值」，不是交易所终值。收盘判定与 REST
        // 复核整套已于 2026-08-27 下线（连同必达通道），本进程只收帧落库，修值归 market:kline_backfill。
        //
        // 已知缺口：WS 断流期间那几根的 close 会停在断开时刻，而回补按 ts 存在性判断、刻意
        // 跳过已存在的行（见 MarketKlineBackfill::klineBackfillFix 的 $sinceMs 过滤），不会被修正。
    }

    protected function handleDepth(MarketExchangeEnum $exchange, int $type, string $symbol, array $depth): void
    {
        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);
        $this->touchCollect(MarketChannelEnum::DEPTH, $sourceKey);
        \app\lib\MarketSourceHealth::touch(MarketChannelEnum::DEPTH, $sourceKey);
        // 只在帧里**真的带** ts 时才采样：币安现货 partial depth 没有时间戳字段，
        // updateDepth() 会退化成 microtime()，那样采出来永远是 0，制造"一切正常"的假象
        if (isset($depth['ts'])) $this->touchAge(MarketChannelEnum::DEPTH, (int)$depth['ts']);
        // 本机逐帧照常处理，不限频：分发出去的 pair 盘口就是这一路，要最新的
        \app\business\service\MarketService::updateDepth($exchange, $type, $symbol, $depth);
        $this->touchLand(MarketChannelEnum::DEPTH, $sourceKey);
    }

    /**
     * trade 不走上面的同步骨架：收帧只做第 1 步并攒进缓冲，第 3、4 步由 flushTrades() 按间隔批量做。
     * 不预先截到 trade_window：MarketPublisher::countTradeOverflow() 靠本批到达笔数统计真实丢包。
     * 设计依据：.agents/plan/trade_coalesce.md §2.1
     */
    protected function handleTrade(MarketExchangeEnum $exchange, int $type, string $symbol, array $data): void
    {
        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);
        $this->touchCollect(MarketChannelEnum::TRADE, $sourceKey);

        // 币安一帧一笔（单个对象），火币 / BingX 一帧多笔（列表）：统一按列表、按到达顺序追加
        $trades = array_is_list($data) ? $data : [$data];
        if (!$trades) return;

        if (!isset($this->tradeBuffer[$sourceKey])) {
            $this->tradeBuffer[$sourceKey] = ['exchange' => $exchange, 'type' => $type, 'symbol' => $symbol, 'items' => []];
        }
        $items = &$this->tradeBuffer[$sourceKey]['items'];
        foreach ($trades as $item) {
            $items[] = $item;
        }
        if (count($items) > self::TRADE_BUFFER_MAX) {
            $items = array_slice($items, -self::TRADE_BUFFER_MAX);
        }
        unset($items);
    }

    /**
     * 把每个 source_key 攒下的成交整批交给 updateTrade()，updateTrade 认列表形态。
     * 刻意不在停机时补写：最多丢一个间隔的成交，只影响「最近成交」列表，onWorkerStop 已归 CommandHeartBeatService
     */
    private function flushTrades(): void
    {
        if (!$this->tradeBuffer) return;

        $buffer = $this->tradeBuffer;
        $this->tradeBuffer = [];
        foreach ($buffer as $sourceKey => $b) {
            \app\business\service\MarketService::updateTrade($b['exchange'], $b['type'], $b['symbol'], $b['items']);
            $this->touchLand(MarketChannelEnum::TRADE, (string)$sourceKey);
        }
    }

    /**
     * 处理标记价格 (采集自交易所官方 markPrice，仅合约行情有该数据，供强平判断使用)
     */
    protected function handleMarkPrice(MarketExchangeEnum $exchange, string $symbol, int $type, string $markPrice, int $ts): void
    {
        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);
        $this->touchCollect(MarketChannelEnum::MARK_PRICE, $sourceKey);
        $this->touchAge(MarketChannelEnum::MARK_PRICE, $ts);
        \app\business\service\MarketService::updateMarkPrice($exchange, $symbol, $type, $markPrice, $ts);
        $this->touchLand(MarketChannelEnum::MARK_PRICE, $sourceKey);
    }


}
