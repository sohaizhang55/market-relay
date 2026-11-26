<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use app\lib\DispatchAuth;
use app\lib\WorkerService;
use app\lib\CommandHeartBeatService;
use app\lib\MarketPublisher;
use app\lib\MarketMetrics;
use app\lib\MarketSnapshotStore;
use app\lib\MarketWireProtocol;
use app\enum\MarketChannelEnum;
use app\enum\MarketSubscriberStateEnum;
use app\enum\MarketDataTypeEnum;

/**
 * 行情数据分发服务器
 *
 * 职责:
 *   1. 监听 WebSocket 端口（.env 的 MARKET_DISPATCH_SPOT_PORT / MARKET_DISPATCH_CONTRACT_PORT，必填，各一个进程），接受订阅方的连接
 *   2. 每 tick 取一次 pair 快照 HASH 里的脏字段（由 MarketService 在 pair 层覆盖写入）
 *   3. 按 stream 房间逐帧推送：每个 stream（btcusdt@depth、btcusdt@kline_1m…）一个房间，
 *      hello 里点名的 stream 就是加入的房间。一帧只压缩一次，同一份字节发给房间里的每条连接，
 *      所以分发成本只跟变化的帧数有关，跟连接数、订阅组合多少都无关。见 deliver()
 *   4. depth 按档位节流与降级：订阅时可选 100/500/1000ms；连接缓冲积压时它的 depth 降一档（最慢 5000ms，见 DEGRADE_FLOORS），
 *      30 秒没再满升回一档。其余 stream 不降级（trade 是 30 笔窗口、kline 会丢收盘值，降频会丢数据）
 *   5. 连接巡检：每 15s 下发 ping；45s 没收到 pong、或发送缓冲持续满 5s，都回收该连接。
 *      与进程级心跳互补——后者管不了链路静默中断（防火墙/NAT 悄悄丢连接、不发 FIN/RST）
 *
 * 线上只有一种编码（见 MarketWireProtocol），没有版本号，订阅方不参与协商。hello 只有 cmd / node / streams；
 * 连上默认什么都不推：收到第一条合法 hello、加入房间之后才补快照并开始推送。
 *
 * 命令  php think market:dispatch
 * 触发  deploy/restart_market.sh（`market:dispatch restart -t 1|2 -d`）；停机走 deploy/stop_all_sockets.sh
 * 单例  SingletonGuardTrait，pid 文件 runtime/market-relay_MarketDispatch.pid
 * 依赖  配置 market.dispatch.*（host/port/tick_ms/lag_close_sec/max_send_buffer）
 *       · market.inner_api.allow_ips（分发端口的 IP 白名单，留空=不限制）
 *       · Redis market:dispatch:auth（订阅方 token 与鉴权开关，见 DispatchAuth；握手头 X-Inner-Token）
 *       Redis market:dispatch:pair_snap:{type}（pair 快照 HASH）· market:dispatch:pair_dirty:{type}（脏集合），
 *       由 MarketService 在 pair 层写入（分发协议 v3：只分发 pair，帧按 {symbol_code}_{type}，订阅按 stream）
 *
 * 用法:
 *   php think market:dispatch start -t 1 -d               # 现货，后台常驻，线上唯一形态（合约同理 -t 2）
 *   php think market:dispatch start -t 1                  # 前台，带每秒汇总行
 *   php think market:dispatch start -t 1 --trace          # 前台 + 逐帧明细
 *   php think market:dispatch start -t 2 --trace=btcusdt  # 明细只留含该串的帧
 *   php think market:dispatch start -t 1 --trace --trace-body  # 明细带帧内容
 *   php think market:dispatch status -t 1
 *   php think market:dispatch stop -t 1
 *
 * 订阅端在下游业务服务器上（如下游业务平台的 market:subscribe）
 *
 * 现货与合约各一个进程、各一个端口（必填，见 portOf()）：
 *   market:dispatch start -t 1   现货，ws://A_IP:{MARKET_DISPATCH_SPOT_PORT}
 *   market:dispatch start -t 2   合约，ws://A_IP:{MARKET_DISPATCH_CONTRACT_PORT}
 *
 * 用 Postman / websocat 这类通用 WS 工具手连 ws://A_IP:8594 调试时：
 *   1. 连上先发一条文本帧：{"cmd":"hello","node":"postman","streams":["btcusdt@ticker"]}
 *      不带 streams = 本端口全部；node 可省，省了监控页归到 '?'（格式见 MarketWireProtocol::parseHello()）。
 *      不发 hello 本进程一帧都不推；hello 非法会回一条 {"_sys":"error","msg":"原因"}，事件流水记 subscriber_hello_invalid
 *   2. 行情回来是二进制帧：一帧 JSON 经 gzdeflate 后的原始字节，通用工具里读不出，
 *      看内容用分发机前台的 --trace --trace-body
 *   3. 约每 15 秒收到文本帧 {"_sys":"ping","ts":<毫秒>}；从连上起每 45 秒内至少要回一次
 *      {"_sys":"pong","ts":<原样>}，否则被断开（MarketPublisher::HEARTBEAT_INTERVAL / HEARTBEAT_TIMEOUT）。
 *      pong 必须紧贴 {"_sys": 开头，不能格式化成带空格或换行的 JSON（MarketPublisher::isSysFrame() 是前缀比对）
 *   4. 连不上先看 market.inner_api.allow_ips：非空时不在名单里的 IP 会被直接断开，对端收不到原因；
 *      再看后台「订阅方管理」的鉴权开关：打开后握手不带有效 X-Inner-Token 头会收到 403
 *
 * ⚠️ "send buffer full" 通常不是带宽或 TCP 问题，是订阅方事件循环长期饱和的下游症状：
 * 实测产出约 165 帧/s、百 KB/s 级，4MB 缓冲要订阅方十几秒不读 socket 才撑满。
 * 所以策略是 depth 降级 + 把缓冲调小 + 记下现场，而不是往上调 maxSendBufferSize —— 那只会把「丢包」
 * 换成「送达即过期」（陈旧帧到了 B 照样被 stale_seconds 判过期）。降级在缓冲过软水位且还在涨时就开始
 * （SOFT_DEGRADE_RATIO），缓冲满时再降一档；它只救得了「慢但还在读」的连接，
 * 完全卡住的订阅方靠积压断开 + 重连补快照。
 */
class MarketDispatcher extends Command
{
    use SingletonGuardTrait;


    // 任务存活证据由 timedHandler() 顺手记：常驻 Timer 的回调都经过它包装
    use \app\lib\EventLoopBusyTrait;

    /** 发送缓冲默认上限(字节)。4MB 约等于全量订阅十几秒的量：够吸收 GC 停顿和调度抖动，
     *  又小到"超过它就一定是对端坏了" */
    private const DEFAULT_MAX_SEND_BUFFER = 4 * 1024 * 1024;

    /** 分发节拍的默认间隔(毫秒)。只在 config 缺 dispatch.tick_ms 时才用得上，
     *  值以 config/market.sample.php 的 MARKET_DISPATCH_TICK_MS 为准。
     *  同一个兜底默认散在好几个文件里(分发机/发布器/监控页/自检)，各写各的会让
     *  「分发机按什么节拍发」与「监控页按什么节拍算窗口容量」对不上，容量差成倍数。
     *  改这个值时用 `grep -rn "dispatch.tick_ms" app config` 一次改全。
     *  100 是 depth 最快档（MarketWireProtocol::DEPTH_INTERVALS[0]）能兑现的前提：tick 比档位慢，档位就是空话 */
    private const DEFAULT_TICK_MS = 100;

    /**
     * 单条连接允许的 hello 次数上限。订阅方一条连接通常只发一次（再发一次是整个替换订阅并重放快照），
     * 没有上限的话，一个能连上 8594 的对端循环发 hello 就能让分发机反复进出房间、重放整份快照，
     * 再加上分发事件流水（MarketMetrics::dispatchEvent）把真诊断事件全挤出窗口
     */
    public const MAX_HELLO_PER_CONN = 5;

    /**
     * depth 降级阶梯（毫秒）：下标即降级档位，值是这一档 depth 推送间隔的下限，0 = 按订阅时声明的间隔。
     * 每次缓冲满或过软水位降一档，见 onBufferFull / conn_watch。2000 / 5000 只给降级用，订阅方不能点名，
     * 慢档定时器按 DEPTH_INTERVALS 与这里的并集注册。后台对接说明页也读它
     */
    public const DEGRADE_FLOORS = [0, 500, 1000, 2000, 5000];

    /** 降级后连续多少秒没再缓冲满，就升回一档(秒)。一次只升一档，免得在满与不满之间来回抖 */
    public const DEGRADE_RECOVER_SEC = 30;

    /**
     * 软水位：发送缓冲超过上限的这个比例、且比上一秒还多，就先降一档 depth，不等缓冲满。
     *
     * 只靠缓冲满（onBufferFull）降级来不及：满了以后要在 lag_close_sec 内把整个上限读完才能排空，
     * 持续读得慢的订阅方做不到，降级还没生效就被积压断开了（实测：PieSocket 订 288 个 stream，满即断）。
     * 软水位让降级发生在缓冲还有余量的时候，订阅方读得动降级后的量就能稳住
     */
    public const SOFT_DEGRADE_RATIO = 0.25;

    /** 软水位两次降档之间至少隔多久(秒)：给上一档时间见效，免得一秒内连降到底 */
    private const SOFT_DEGRADE_GAP_SEC = 5;

    /** 连续处于「缓冲满」状态多少秒仍不恢复，就断开该订阅方(秒) */
    private const DEFAULT_LAG_CLOSE_SEC = 5;

    /** 连接巡检周期(秒)。ping 每 HEARTBEAT_INTERVAL 拍发一次 */
    private const WATCH_INTERVAL_SEC = 1;

    /**
     * 允许用来采样"数据滞后"的频道。
     * 只收 ts 语义为"这份快照何时生成"的那几个；trade(滚动窗口里最旧一笔)
     * 与 kline(蜡烛起始时刻)的 ts 不是这个含义，采进来会得到吓人却无意义的数字。
     */
    private const AGE_SAMPLE_CHANNELS = [
        'market:raw:depth'      => true,
        'market:raw:price'      => true,
        'market:raw:mark_price' => true,
    ];

    /** fieldStreams 的兜底上界。现网百级 pair × 10 种 stream，正常远到不了 */
    private const FIELD_STREAM_MAX = 20000;

    /** @var array<int,\Workerman\Connection\TcpConnection> conn_id => 订阅方连接 */
    private array $subscribers = [];

    /** @var array<string,array<int,true>> stream => [conn_id => true]，hello 里点名了这个 stream 的连接 */
    private array $rooms = [];

    /** @var array<int,true> 不传 streams（要本端口全部）的连接。单独一个集合，免得每条全量连接都进几百个房间 */
    private array $allRoom = [];

    /** @var array<string,?string> 快照 field => stream 名，认不出存 null。field 全集几百个且几乎不变 */
    private array $fieldStreams = [];

    /** @var array<string,string> depth stream => 最新一帧 JSON。慢档定时器到点时发的就是它 */
    private array $latestDepth = [];

    /** @var array<int,array<string,true>> 慢档间隔(毫秒) => [depth stream => true]：这段时间变过、等该档定时器发 */
    private array $tierPending = [];

    /** 分发节拍(毫秒)。间隔不超过它的 depth 每 tick 直接发，慢于它的走慢档定时器 */
    private int $tickMs = self::DEFAULT_TICK_MS;

    /**
     * 某类 pair 的分发端口：config market.dispatch.ports，即 .env 的 MARKET_DISPATCH_SPOT_PORT / MARKET_DISPATCH_CONTRACT_PORT。
     * 不含 -p 覆盖：后台页面按它告诉订阅方连哪个端口。
     *
     * 没有默认值：默认端口会让"忘了配"悄悄变成"监听在某个端口上"，与防火墙、订阅方各自以为的端口对不上。
     * 没配时返回 0 —— 后台页面照常打开（显示 0），market:dispatch 启动时拒绝（见 execute）。
     *
     * 现货与合约各一个独立进程、各一个端口（对标 Binance 现货 stream 与合约 fstream 分开部署）：
     * 一个进程卡住、崩溃或重启，另一类行情照常分发。两个进程各取各的快照键，见 MarketSnapshotStore
     */
    public static function portOf(int $type): int
    {
        return (int)\think\facade\Config::get("market.dispatch.ports.{$type}", 0);
    }

    /** 本进程分发哪一类 pair（MarketDataTypeEnum 的值），由 -t 指定 */
    private int $type = 0;

    /**
     * 每连接状态：
     * conn_id => ['state'=>ACTIVE|LAGGING, 'since'=>进入 LAGGING 的时刻,
     *             'full'/'ms'/'skip'=>缓冲现场, 'closing'=>已决定断开,
     *             'streams'=>订阅(null=全部), 'degrade'/'degrade_at'/'told_ms'/'degrade_n'/'degraded_since'=>depth 降级,
     *             'buf_last'/'buf_peak'=>巡检看到的发送缓冲, 'ip'/'connected_at'=>连接现场,
     *             'node'=>机器标识, 'replayed'=>是否补过快照,
     *             'win_*'=>10 秒发出统计窗口,
     *             'hello_n'/'hello_ok'=>握手计数, 'last_active'=>最后一次 pong,
     *             'rtt_*'=>往返时延]
     */
    private array $bufState = [];

    /** @var array 10 秒窗口的分发计数器，见 resetStat() */
    private array $stat = [];

    /** @var array --trace 明细与每秒汇总的累加器 */
    private array $trace = [];

    /**
     * sndbuf 峰值的采样历史（每 10 秒一个点，保留最近 6 个 = 1 分钟）。
     * "发送缓冲持续增长且从不回落"比 send_false 早几十秒，必须看趋势。
     */
    private array $sndbufHistory = [];

    /** @var array onError 的折叠状态：['at'=>上次输出时刻,'suppressed'=>已折叠条数] */
    private array $errorEcho = ['at' => 0.0, 'suppressed' => 0];

    /**
     * 某类 pair 分发进程的 worker 名（心跳、指标、监控页用）。与采集器同一套后缀：Spot / Swap。
     * MarketWorkers::expected() 引用这里，保证「应常驻清单」与进程实际名字永远一致
     */
    public static function workerName(int $type): string
    {
        $typeName = $type === MarketDataTypeEnum::SPOT->value ? 'Spot' : 'Swap';
        return "market_relay_MarketDispatchServer_{$typeName}";
    }

    /** 某类 pair 分发进程的 pid / 日志文件名：runtime/market_dispatcher_{spot|swap}.pid */
    private static function processName(int $type): string
    {
        return 'market_dispatcher_' . ($type === MarketDataTypeEnum::SPOT->value ? 'spot' : 'swap');
    }

    protected function configure()
    {
        $this->setName('market:dispatch')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|restart|status', 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run in daemon mode')
            ->addOption('type', 't', Option::VALUE_REQUIRED, '分发哪一类 pair（必填）：1 现货 / 2 合约，各一个独立进程')
            ->addOption('port', 'p', Option::VALUE_REQUIRED, 'WebSocket listen port，默认按类型取 market.dispatch.ports')
            ->addOption('trace', null, Option::VALUE_OPTIONAL,
                '前台模式下逐帧打印分发明细。可跟过滤串(匹配频道或 pair)，如 --trace=depth 或 --trace=btcusdt；不带值=全部', false)
            ->addOption('trace-rate', null, Option::VALUE_REQUIRED,
                '--trace 明细每秒最多打印多少行，0=不限。默认 50', '50')
            ->addOption('trace-body', null, Option::VALUE_NONE,
                '--trace 明细附带帧内容(截断到 240 字符)')
            ->setDescription('Market Data Dispatch Server');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        global $argv;
        $argv[1] = $action;
        if (isset($argv[2])) unset($argv[2]);

        // 每一类 pair 一个进程：pid 文件、日志、进程名、端口、快照键全按类型分开，start/stop/status 都要带 -t
        $typeEnum = MarketDataTypeEnum::tryFrom((int)$input->getOption('type'));
        if ($typeEnum === null) {
            $output->writeln('<error>[Market-Dispatch] 必须用 -t 指定分发哪一类 pair：-t 1 现货 / -t 2 合约（各起一个进程）</error>');
            return;
        }
        $this->type = $typeEnum->value;
        $processName = self::processName($this->type);

        // 残留的旧配置段拦截：dispatcher 段已并入 dispatch，config/market.php 在 .gitignore 里不随代码上生产，
        // 兼容读取会让端口悄悄退回默认值这种静默错误一直活着，所以选拒绝启动
        $legacy = \think\facade\Config::get('market.dispatcher');
        if (is_array($legacy) && $legacy !== []) {
            $msg = '[Market-Dispatch] config/market.php 里仍有 dispatcher 段，'
                 . '该段已并入 dispatch：把 host / port / max_send_buffer 三项挪到 '
                 . "'dispatch' => [...] 下并删掉 'dispatcher' => [...]（env 变量名不变），再启动。";
            business_log('market_dispatch_stat', $msg, 'error');
            $output->writeln("<error>{$msg}</error>");
            return;
        }

        $port = (int)($input->getOption('port') ?: self::portOf($this->type));
        // 端口必填、没有默认值：没配就拒绝启动，不替运维猜一个端口
        if ($port <= 0) {
            $envKey = $this->type === MarketDataTypeEnum::SPOT->value ? 'MARKET_DISPATCH_SPOT_PORT' : 'MARKET_DISPATCH_CONTRACT_PORT';
            $msg = "[Market-Dispatch] 未配置分发端口：root/.env 缺 {$envKey}"
                 . '（值见 deploy/ports.env，deploy/install.sh 第 13 段会写入），或用 -p 指定，再启动。';
            business_log('market_dispatch_stat', $msg, 'error');
            $output->writeln("<error>{$msg}</error>");
            return;
        }
        $maxSendBuffer = (int)\think\facade\Config::get(
            'market.dispatch.max_send_buffer',
            self::DEFAULT_MAX_SEND_BUFFER
        );

        // ⚠️ 必须先 init 再碰任何 Workerman 类：Workerman 装在项目外的 Workerman/ 目录下，
        // 由 WorkerService::init() 现场挂上它的 autoloader
        WorkerService::init($processName);

        TcpConnection::$defaultMaxSendBufferSize = $maxSendBuffer;

        // 放在 init 之后：resolveBindHost() 里要读 Worker::$daemonize
        $host = $this->resolveBindHost(
            (string)\think\facade\Config::get('market.dispatch.host', '0.0.0.0'),
            $output
        );

        if ($input->getOption('daemon')) {
            Worker::$daemonize = true;
        }

        // tcp_nodelay：Workerman 只设 backlog 和 so_reuseport，从不设 TCP_NODELAY，
        // Nagle 最多能给每条消息加约 40ms 的合并延迟
        $server = new Worker("websocket://{$host}:{$port}", [
            'socket' => ['tcp_nodelay' => true, 'backlog' => 1024],
        ]);
        // 本 worker 的运维坐标（可直接照抄进 redis-cli），{Type} = Spot / Swap：
        //   heartbeat:cmd:market_relay_MarketDispatchServer_{Type}      心跳，TTL 15s
        //   heartbeat:stopping:market_relay_MarketDispatchServer_{Type} 正常停机标记，TTL 60s
        //   heartbeat:inst:market_relay_MarketDispatchServer_{Type}     HASH，field={主机名}#{pid}
        //   market:metrics:worker:market_relay_MarketDispatchServer_{Type}  HASH，进程指标
        //   ⚠️ pid 与日志用的是另一个名字：runtime/market_dispatcher_{spot|swap}.pid、runtime/log/同名.log
        $server->name  = self::workerName($this->type);
        // 单例守卫必须在 Worker::runAll() 之前，见 SingletonGuardTrait 注释
        $this->guardSingleton($server->name, (string)$action, $output);

        // ⚠️ 这里必须是 1。N 个 worker 各自 SPOP 同一个脏集合，会把帧按 N 份切开，
        // 每个订阅方只收到属于自己那个 worker 的 1/N —— 静默的、部分的行情数据。
        // 要真做多 worker，必须先把「取走 + 取值」拆成单一的 tick producer，N 个 worker 只负责扇出。
        // 但实测 53 个订阅方、单 worker 时分发机 CPU 占用 0.8%，瓶颈从来在订阅方的落库路径
        $server->count = 1;

        $tickMs      = max(10, (int)\think\facade\Config::get('market.dispatch.tick_ms', self::DEFAULT_TICK_MS));
        $this->tickMs = $tickMs;
        $lagCloseSec = max(1, (int)\think\facade\Config::get('market.dispatch.lag_close_sec', self::DEFAULT_LAG_CLOSE_SEC));

        $this->resetStat();

        // 汇总行前台默认开、每秒一行、成本恒定；明细行 --trace 才开，带过滤与每秒行数上限。
        // 线上排空积压时是 8800 帧/s，逐条 writeln 是同步 IO，会把终端和事件循环一起拖死
        $traceOpt = $input->getOption('trace');
        $this->trace = [
            // false = 没写 --trace；null = 写了但没给值(全部)；字符串 = 过滤串
            'detail' => $traceOpt !== false,
            'filter' => is_string($traceOpt) ? strtolower(trim($traceOpt)) : '',
            'rate'   => max(0, (int)$input->getOption('trace-rate')),
            'body'   => (bool)$input->getOption('trace-body'),
            'sec'    => 0,
            'lines'  => 0,
            'cut'    => 0,
            'n' => 0, 'bytes' => 0, 'msgs' => 0, 'ticks' => 0, 'chan' => [], 'age_max' => 0.0,
        ];

        $server->onError = function ($conn, $code, $msg) use ($output) {
            // SEND_FAIL(=2) 即「send buffer full and drop package」
            $this->stat['send_fail']++;
            if (Worker::$daemonize) return;

            // 前台限流打印：丢包可能每秒上千次，写 stdout 本身是同步 IO
            $now = microtime(true);
            if ($now - $this->errorEcho['at'] < 1.0) {
                $this->errorEcho['suppressed']++;
                return;
            }
            $extra = $this->errorEcho['suppressed'] > 0 ? "（另有 {$this->errorEcho['suppressed']} 条已折叠）" : '';
            $this->errorEcho = ['at' => $now, 'suppressed' => 0];
            $output->writeln("<error>[MarketDispatch]</error> onError({$code}): {$msg} {$extra}");
        };

        $server->onConnect = function ($conn) use ($output, $port, $maxSendBuffer) {
            $ip = $conn->getRemoteIp();

            // 逐连接确保 TCP_NODELAY：监听 socket 上的 context 能否被 accept 出来的连接继承是依赖操作系统的。
            // ext-sockets 未装时静默跳过（不是必需项）
            if (function_exists('socket_import_stream')) {
                try {
                    $sock = socket_import_stream($conn->getSocket());
                    if ($sock !== false) {
                        socket_set_option($sock, SOL_TCP, TCP_NODELAY, 1);
                    }
                } catch (\Throwable $e) {
                }
            }

            // 与 inner_api 的 InnerAuth 复用同一份 IP 白名单：8594 本质上是同一类内部专用接口
            $allowIps = array_filter(array_map('trim', explode(',', (string)\think\facade\Config::get('market.inner_api.allow_ips', ''))));
            if (!empty($allowIps) && !in_array($ip, $allowIps, true)) {
                business_log('inner_api', "[MarketDispatch:{$port}] rejected: ip {$ip} not in allow_ips", 'warning');
                if (!Worker::$daemonize) {
                    $output->writeln("<error>[MarketDispatch:{$port}]</error> Rejected (not in allow_ips): {$ip}");
                }
                $conn->close();
                return;
            }

            // 背压策略：缓冲满时**主动跳过**该连接，而不是继续往里塞。继续塞除了必然被
            // Workerman 丢弃之外，还要白白付出编码与 onError 的开销；主动跳过让丢弃成为可计量的行为。
            // 是 skip 不是 pause：pauseRecv() 暂停的是"读"，与此无关；这里暂停的是"往某一个连接写"，
            // 逐连接独立判断，一个慢订阅方不影响其他订阅方
            $this->bufState[$conn->id] = [
                'state'   => MarketSubscriberStateEnum::ACTIVE,
                'since'   => 0.0,   // 进入 LAGGING 的时刻
                'full'    => 0,     // 累计触发缓冲满次数
                'ms'      => 0.0,   // 累计处于 LAGGING 的毫秒数
                'skip'    => 0,     // 因 LAGGING 被跳过的 tick 数
                'closing' => false, // 巡检已决定断开，防止 destroy 到 onClose 之间重复触发
                'streams'    => null,  // null = 全部；否则 stream => 请求的 depth 间隔(ms)，见 MarketWireProtocol::parseHello()
                'degrade'    => 0,     // depth 降级档位，DEGRADE_FLOORS 的下标
                'degrade_at' => 0.0,   // 最近一次降级或升档的时刻，升档计时从这里起
                'told_ms'    => 0,     // 上次通知订阅方的 depth 下限，与当前不同才再发 throttle
                'buf_last'   => 0,     // 上一次巡检时的发送缓冲字节数，软水位据此判断是否还在涨
                'buf_peak'   => 0,     // 本统计窗口内巡检看到的发送缓冲最大值，stat 定时器读完清零
                'degrade_n'  => 0,     // 本连接累计降档次数
                'degraded_since' => 0.0, // 从不降级进入降级的时刻，升回 0 档清零
                'ip'        => $ip,
                'connected_at' => microtime(true),
                'node'      => '',
                'sub'       => '',     // 握手头 token 认出的订阅方名称，认不出为空，见 onWebSocketConnect
                'replayed'  => false,
                'hello_n'   => 0,
                'hello_ok'  => 0,
                // 最后一次收到 pong 的时刻(秒)。回收判定只看它：判活依据必须是"在工作的东西"
                'last_active' => time(),
                // 跨机往返时延（毫秒）。ping 里带出去的 ts 由 pong 原样带回，相减即得，
                // 是目前唯一能回答"A 到 B 这条链路有多快"的数
                'rtt_last'  => 0.0,
                'rtt_max'   => 0.0,
                'rtt_n'     => 0,
                'rtt_sum'   => 0.0,
                // 发出统计窗口：成功 send() 的消息条数、字节数（压缩后）、按 stream 类别的字节数、丢弃条数。
                // stat 定时器读完即清零，见 resetSendWindows()
                'win_msgs'   => 0,
                'win_bytes'  => 0,
                'win_kind'   => [],
                'win_drop'   => 0,
                'win_since'  => microtime(true),
            ];

            // 压缩帧必须走 WebSocket 二进制帧(opcode 0x2)，否则对端按文本解会乱码
            $conn->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;

            $conn->onBufferFull = function ($conn) {
                $this->stat['buf_full']++;
                if (!isset($this->bufState[$conn->id])) return;
                $this->bufState[$conn->id]['full']++;
                if ($this->bufState[$conn->id]['state'] === MarketSubscriberStateEnum::ACTIVE) {
                    $this->bufState[$conn->id]['state'] = MarketSubscriberStateEnum::LAGGING;
                    $this->bufState[$conn->id]['since'] = microtime(true);
                    $this->connEvent($conn->id, 'buffer_full', [
                        'buf' => $conn->getSendBufferQueueSize(), 'max' => $conn->maxSendBufferSize,
                    ]);
                    // 每进一次 LAGGING 降一档。降级本身不能让缓冲变空，它管的是排空之后的发送量；
                    // 一直不排空就走 lag_close_sec 断开，降到哪一档都一样
                    $this->stepDegrade($conn->id, +1, 'full', $conn->getSendBufferQueueSize());
                }
            };

            $conn->onBufferDrain = function ($conn) {
                $this->stat['buf_drain']++;
                if (!isset($this->bufState[$conn->id])) return;
                if ($this->bufState[$conn->id]['state'] === MarketSubscriberStateEnum::LAGGING) {
                    $this->bufState[$conn->id]['ms'] += (microtime(true) - $this->bufState[$conn->id]['since']) * 1000;
                    $this->bufState[$conn->id]['state'] = MarketSubscriberStateEnum::ACTIVE;
                    $this->bufState[$conn->id]['since'] = 0.0;
                    // 缓冲满期间发不出东西，降级通知攒到这里才发
                    $this->applyDegrade($conn->id);
                }
            };

            $this->subscribers[$conn->id] = $conn;
            if (!Worker::$daemonize) {
                $output->writeln(sprintf(
                    '<info>[MarketDispatch:%d]</info> Subscriber connected: %s (total: %d, maxSendBufferSize: %d bytes)',
                    $port, $ip, count($this->subscribers), $maxSendBuffer
                ));
            }
            $this->connEvent($conn->id, 'subscriber_connect', ['total' => count($this->subscribers)]);
        };

        // 握手时认订阅方：X-Inner-Token 命中谁就是谁，记下它这次的来源 IP。
        // 鉴权开关关闭时不带或带错都放行（只是认不出是谁），打开后认不出就在握手阶段回 403
        $server->onWebSocketConnect = function ($conn, $request) use ($output, $port) {
            if (!isset($this->bufState[$conn->id])) return;
            $ip  = $this->bufState[$conn->id]['ip'];
            $sub = DispatchAuth::identify((string)$request->header(strtolower(DispatchAuth::HEADER), ''));
            if ($sub !== null) {
                $this->bufState[$conn->id]['sub'] = $sub['name'];
                DispatchAuth::markSeen($sub['id'], DispatchAuth::SEEN_WS, $ip);
                return;
            }
            if (!DispatchAuth::enabled()) return;

            $reason = $request->header(strtolower(DispatchAuth::HEADER), '') === '' ? '未带 token' : 'token 无效或订阅方已停用';
            business_log('inner_api', "[MarketDispatch:{$port}] rejected: ip {$ip} {$reason}", 'warning');
            if (!Worker::$daemonize) {
                $output->writeln("<error>[MarketDispatch:{$port}]</error> Rejected ({$reason}): {$ip}");
            }
            $this->connEvent($conn->id, 'subscriber_auth_reject', ['reason' => $reason]);
            $conn->close("HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n", true);
        };

        $server->onMessage = function ($conn, $raw) use ($output) {
            if (!isset($this->bufState[$conn->id])) return;

            // 心跳帧优先分流：一次 strncmp，不进 json_decode，也不占 hello 配额。
            // 分流必须在 hello_n 计数之前，否则 pong 会把配额耗光、连接每两分钟被判死一次
            if (MarketPublisher::isSysFrame((string)$raw)) {
                $p = json_decode((string)$raw, true) ?: [];
                if (($p['_sys'] ?? '') === MarketPublisher::SYS_PONG) {
                    $this->bufState[$conn->id]['last_active'] = time();

                    // 不做时钟同步假设：这是同一台机器发出与收回的时间戳相减
                    $sent = (int)($p['ts'] ?? 0);
                    if ($sent > 0) {
                        $rtt = max(0.0, microtime(true) * 1000 - $sent);
                        $st  = &$this->bufState[$conn->id];
                        $st['rtt_last'] = round($rtt, 1);
                        $st['rtt_max']  = max($st['rtt_max'], round($rtt, 1));
                        $st['rtt_sum'] += $rtt;
                        $st['rtt_n']++;
                        unset($st);
                    }
                }
                return;
            }

            if (++$this->bufState[$conn->id]['hello_n'] > self::MAX_HELLO_PER_CONN) return;

            $hello = MarketWireProtocol::parseHello((string)$raw, $this->type, $error);
            if ($hello === null) {
                // 非法 hello 不改变订阅：已握手的连接继续按原订阅收，还没握手的连接继续一帧都不收。
                // 原因回给对端（对接时最常见的问题就是 stream 写错），同时留痕（每条连接最多 MAX_HELLO_PER_CONN 次）
                self::sendSysFrame($conn, MarketPublisher::sysFrame(
                    MarketPublisher::SYS_ERROR, (int)(microtime(true) * 1000), ['msg' => (string)$error]
                ));
                $this->connEvent($conn->id, 'subscriber_hello_invalid', [
                    'bytes' => strlen((string)$raw), 'handshaked' => $this->bufState[$conn->id]['hello_ok'],
                    'error' => mb_substr((string)$error, 0, 200),
                ]);
                // 端口对任意对端开放，原文里的换行能伪造日志行；json_encode 转义控制字符，截断处的半个 UTF-8 字符替换掉
                business_log('market_dispatch', sprintf(
                    'invalid hello conn=%d ip=%s bytes=%d error=%s head=%s',
                    $conn->id, $conn->getRemoteIp(), strlen((string)$raw),
                    json_encode((string)$error, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    json_encode(substr((string)$raw, 0, 120), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                ), 'warning');
                if (!Worker::$daemonize) {
                    $output->writeln(sprintf(
                        '<error>[MarketDispatch]</error> 拒绝非法 hello conn=%d bytes=%d：%s',
                        $conn->id, strlen((string)$raw), $error
                    ));
                }
                return;
            }

            $this->applyHello($conn, $hello, $output);

            // hello 生效后就补快照。放在 hello 之后而不是 onConnect，是为了只补它订阅的那些 stream
            $this->replaySnapshotTo($conn, $output);
        };

        $server->onClose = function ($conn) use ($output, $port) {
            // 事件要在 bufState 清掉之前记：node / ip / 连接时长都在里面
            $st = $this->bufState[$conn->id] ?? null;
            if ($st !== null) {
                $this->connEvent($conn->id, 'subscriber_close', [
                    'remaining' => count($this->subscribers) - 1,
                    'alive_sec' => (int)(microtime(true) - $st['connected_at']),
                    'degrade_n' => $st['degrade_n'],
                ]);
            }
            $this->leaveRooms($conn->id);
            unset($this->subscribers[$conn->id], $this->bufState[$conn->id]);
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[MarketDispatch:{$port}]</comment> Subscriber left (remaining: " . count($this->subscribers) . ")");
            }
        };

        $server->onWorkerStart = function () use ($output, $port, $host, $server, $maxSendBuffer, $tickMs, $lagCloseSec) {
            CommandHeartBeatService::start($server);

            $this->initWorkerEnv();
            // DB 探活：MySQL 断过一次后连接不会自愈，SELECT 1 失败即 close，下次查询重新建连
            \Workerman\Timer::add(30, $this->timedHandler('db_ping', fn() => $this->pingDb()));

            // 生效配置落一条日志：config/market.php 不随代码上生产，新增的配置项在生产上很可能在用默认值
            business_log('market_dispatch_stat', sprintf(
                'boot listen=%s:%d tick=%dms lag_close=%ds max_send_buffer=%d event_loop=%s',
                $host, $port, $tickMs, $lagCloseSec, $maxSendBuffer,
                Worker::$eventLoopClass ?: 'default'
            ));
            if (!Worker::$daemonize) {
                $output->writeln("<info>[MarketDispatch:{$port}]</info> Server started, tick={$tickMs}ms");
            }

            // 每秒一行的分发汇总。只在前台注册 —— daemon 模式下 stdout 没人看
            if (!Worker::$daemonize) {
                \Workerman\Timer::add(1, $this->timedHandler('trace_flush', function () use ($output) {
                    $this->flushTraceSummary(count($this->subscribers), $output);
                }));
            }

            // 观测埋点：每 10 秒汇总输出一次并清零。关键看两个数——
            //   sndbuf   各订阅方当前发送缓冲字节数。持续接近上限就是订阅方消费不过来
            //   drop     send() 返回 false + onError SEND_FAIL 的次数，即实际丢包量
            // @no-task-report: 它自己就是上报器；停了会被 metric_age 判出来（WorkerHealth::common() 第一段）
            \Workerman\Timer::add(10, function () use ($output, $server, $maxSendBuffer, $port) {
                // guardStat：Workerman 吞掉定时器异常后进程照跑，只有指标会永久冻结
                MarketMetrics::guardStat($server->name, function () use ($output, $server, $maxSendBuffer, $port) {
                    $redis = redis_client();
                    $snapStats = $redis ? MarketSnapshotStore::stats($redis, $this->type) : ['snap_fields' => -1, 'dirty_size' => -1];

                    $bufs = [];
                    $sndbufMax = 0;
                    foreach ($this->subscribers as $id => $conn) {
                        $size = $conn->getSendBufferQueueSize();
                        $sndbufMax = max($sndbufMax, $size);
                        $bufs[] = $id . ':' . $size;
                    }

                    // 内核 Send-Q：sndbuf 只看得见 PHP 字符串，带宽受限时排队全在这里
                    $ksq = \app\lib\KernelSocketQueue::byLocalPort($port);

                    $chan = [];
                    foreach ($this->stat['by_chan'] as $c => $n) {
                        $chan[] = MarketChannelEnum::shorten((string)$c) . '=' . $n;
                    }

                    $line = sprintf(
                        'popped=%d msgs=%d deflate=%d tick_max=%.1fms drop(send_false=%d send_fail=%d) kicked(hb=%d lag=%d) conn=%s nodes=%s rtt=%s skipped=%d buf(full=%d drain=%d) degrade(down=%d up=%d) subs=%d rooms=%d snap=%d/%d sndbuf=[%s] ksendq=%s chan[%s]',
                        $this->stat['popped'], $this->stat['msgs'], $this->stat['deflate'], $this->stat['tick_max_ms'],
                        $this->stat['send_false'], $this->stat['send_fail'],
                        $this->stat['hb_kick'], $this->stat['lag_close'],
                        $this->connHealthSummary(),
                        $this->nodesSummary(),
                        $this->rttSummary(),
                        $this->stat['skipped'],
                        $this->stat['buf_full'], $this->stat['buf_drain'],
                        $this->stat['degrade_down'], $this->stat['degrade_up'],
                        count($this->subscribers), count($this->rooms),
                        $snapStats['dirty_size'], $snapStats['snap_fields'],
                        implode(',', $bufs) ?: '-',
                        // 内核 Send-Q。n/a = 非 Linux 拿不到，刻意不写 0 免得被读成"很健康"
                        $ksq['tx'] < 0 ? 'n/a' : sprintf('%dB/%dconn', $ksq['tx'], $ksq['conns']),
                        implode(' ', $chan)
                    );
                    // 现货 / 合约两个进程写同一个统计日志，行首带上类型才分得清是谁
                    $line = sprintf('[type=%d] %s', $this->type, $line);
                    business_log('market_dispatch_stat', $line,
                        ($this->stat['send_false'] || $this->stat['send_fail'] || $this->stat['hb_kick']) ? 'warning' : 'info');
                    if (!Worker::$daemonize) {
                        $output->writeln("<comment>[MarketDispatch][stat]</comment> {$line}");
                    }

                    $this->sndbufHistory[] = $sndbufMax;
                    if (count($this->sndbufHistory) > 6) array_shift($this->sndbufHistory);

                    $busy = self::loopBusySnapshot(10.0);

                    MarketMetrics::putWorker($server->name, [
                        'busy_pct'       => round($busy['pct'], 1),
                        'busy_detail'    => $busy['detail'],
                        // 任务存活证据：与 popped/sent 互补 —— 那两个数回答"有没有在产出"，
                        // 这个回答"是哪一个定时器停了"
                        'tasks'          => json_encode(self::taskSnapshot(), JSON_UNESCAPED_UNICODE),
                        'popped_10s'     => $this->stat['popped'],
                        'deflate_10s'    => $this->stat['deflate'],
                        'tick_max_ms'    => $this->stat['tick_max_ms'],
                        'degrade_down_10s' => $this->stat['degrade_down'],
                        'degrade_up_10s'   => $this->stat['degrade_up'],
                        'send_false_10s' => $this->stat['send_false'],
                        'send_fail_10s'  => $this->stat['send_fail'],
                        'buf_full_10s'   => $this->stat['buf_full'],
                        'buf_drain_10s'  => $this->stat['buf_drain'],
                        'skipped_10s'    => $this->stat['skipped'],
                        'hb_kick_10s'    => $this->stat['hb_kick'],
                        'lag_close_10s'  => $this->stat['lag_close'],
                        'msgs_10s'       => $this->stat['msgs'],
                        // 成功 send() 的压缩后字节数，合计与按 stream 类别（MarketWireProtocol::streamKind）
                        'bytes_10s'      => $this->stat['bytes'],
                        'kind_bytes_10s' => json_encode($this->stat['kind_bytes']),
                        'soft_buffer'    => (int)($maxSendBuffer * self::SOFT_DEGRADE_RATIO),
                        'snap_fields'    => $snapStats['snap_fields'],
                        'dirty_size'     => $snapStats['dirty_size'],
                        'rate_fps'       => round($this->stat['popped'] / 10, 1),
                        'subscribers'    => count($this->subscribers),
                        'sndbuf_max'     => $sndbufMax,
                        // 与 sndbuf_max 是两回事：后者只是 PHP 字符串长度，带宽受限时它恒为 0 而这一项会涨
                        'ksendq_bytes'   => $ksq['tx'],
                        'ksendq_conns'   => $ksq['conns'],
                        'sndbuf_limit'   => $maxSendBuffer,
                        'sndbuf_detail'  => implode(',', $bufs),
                        'sndbuf_history' => json_encode($this->sndbufHistory),
                        'buf_state'      => json_encode($this->summarizeBufState()),
                        'conn_health'    => $this->connHealthSummary(),
                        'nodes'          => $this->nodesSummary(),
                    ]);
                    MarketMetrics::flushGroups();

                    $this->resetStat();
                    // 必须在 putWorker 之后：buf_state 刚把这一窗的每连接统计读走
                    $this->resetSendWindows();
                    // 与 putWorker 的 10 秒窗口配套：不清零的话 busy% 会一路累加
                    self::loopBusyReset();
                });
            });

            // ── 连接巡检（每秒一拍）────────────────────────────────────────────
            // 两条回收判据，处置方向相反，事件名分开：
            //   lag  「活着但慢」：缓冲满持续超过 lag_close_sec，查订阅方落库
            //   hb   「已经死了」：超过 HEARTBEAT_TIMEOUT 没回 pong，查网络/NAT
            // 两条缺一不可：NAT/防火墙静默丢连接时内核 socket 仍接受写入，onBufferFull 永不触发，
            // 连接永远进不了 LAGGING —— 只有心跳能回收它。TIMEOUT 与订阅方停顿余量的关系见 MarketPublisher。
            // 覆盖语义下断开是廉价止损：订阅方重连后由 replaySnapshotTo() 补全量，不存在"补历史"。
            // 用 destroy() 而不是 close()：close() 会等发送缓冲排空才真正断开，而缓冲里正是
            // 发不出去的陈旧帧，等它排空等于什么都没做。
            $watchTick = 0;
            $softBuffer = (int)($maxSendBuffer * self::SOFT_DEGRADE_RATIO);
            \Workerman\Timer::add(self::WATCH_INTERVAL_SEC, $this->timedHandler('conn_watch', function () use ($lagCloseSec, $output, $port, &$watchTick, $softBuffer) {
                $watchTick++;
                $sendPing = ($watchTick % MarketPublisher::HEARTBEAT_INTERVAL) === 0;
                $now  = microtime(true);
                $ping = $sendPing ? MarketPublisher::sysFrame(MarketPublisher::SYS_PING, (int)($now * 1000)) : '';

                // 先收集再 destroy：destroy() 会同步触发 onClose 改写 $this->subscribers
                $dead = [];
                foreach ($this->subscribers as $id => $conn) {
                    $st = $this->bufState[$id] ?? null;
                    if (!$st || !empty($st['closing'])) continue;

                    if ($st['state'] === MarketSubscriberStateEnum::LAGGING
                        && $st['since'] > 0 && ($now - $st['since']) >= $lagCloseSec) {
                        $dead[$id] = ['lag', round($now - $st['since'], 1)];
                        continue;
                    }
                    $idle = (int)$now - (int)$st['last_active'];
                    if ($idle > MarketPublisher::HEARTBEAT_TIMEOUT) {
                        $dead[$id] = ['hb', $idle];
                        continue;
                    }
                    // 软水位：缓冲过线且还在涨，先降一档（SOFT_DEGRADE_RATIO）。LAGGING 时 onBufferFull 已经降过
                    $buf = $conn->getSendBufferQueueSize();
                    $this->bufState[$id]['buf_last'] = $buf;
                    if ($buf > $st['buf_peak']) $this->bufState[$id]['buf_peak'] = $buf;
                    if ($st['state'] === MarketSubscriberStateEnum::ACTIVE && $buf >= $softBuffer && $buf > $st['buf_last']
                        && $st['degrade'] < count(self::DEGRADE_FLOORS) - 1
                        && ($now - $st['degrade_at']) >= self::SOFT_DEGRADE_GAP_SEC) {
                        $this->stepDegrade($id, +1, 'soft', $buf);
                        $this->applyDegrade($id);
                    }
                    // 降级后一段时间没再缓冲满、缓冲也回到软水位以下，升回一档。LAGGING 时不升：那时它正堵着
                    elseif ($st['degrade'] > 0 && $st['state'] === MarketSubscriberStateEnum::ACTIVE && $buf < $softBuffer
                        && ($now - $st['degrade_at']) >= self::DEGRADE_RECOVER_SEC) {
                        $this->stepDegrade($id, -1, 'recover', $buf);
                        $this->applyDegrade($id);
                    }
                    if ($sendPing && !self::sendSysFrame($conn, $ping)) {
                        // 连几十字节的心跳都塞不进发送缓冲，说明这条连接已经堵死，是丢包的前兆
                        business_log('market_dispatch', sprintf(
                            '[MarketDispatch:%d] heartbeat send failed (send buffer full), conn=%s ip=%s',
                            $port, $id, $conn->getRemoteIp()
                        ), 'warning');
                    }
                }

                foreach ($dead as $id => [$why, $n]) {
                    $this->bufState[$id]['closing'] = true;
                    $conn = $this->subscribers[$id];
                    $node = ($this->bufState[$id]['node'] ?? '') !== '' ? $this->bufState[$id]['node'] : '?';
                    if ($why === 'lag') {
                        $this->stat['lag_close']++;
                        business_log('market_dispatch_stat', sprintf(
                            'closing lagging subscriber conn=%d node=%s ip=%s held=%ss skipped=%d sndbuf=%d',
                            $id, $node, $conn->getRemoteIp(), $n, $this->bufState[$id]['skip'], $conn->getSendBufferQueueSize()
                        ), 'warning');
                        $this->connEvent($id, 'subscriber_lag_close', [
                            'held' => $n, 'skipped' => $this->bufState[$id]['skip'], 'buf' => $conn->getSendBufferQueueSize(),
                        ]);
                        if (!Worker::$daemonize) {
                            $output->writeln("<error>[MarketDispatch]</error> 断开积压订阅方 conn={$id} (已积压 {$n}s)");
                        }
                    } else {
                        $this->stat['hb_kick']++;
                        business_log('market_dispatch', sprintf(
                            '[MarketDispatch:%d] heartbeat timeout, closing dead subscriber conn=%s node=%s ip=%s idle=%ds',
                            $port, $id, $node, $conn->getRemoteIp(), $n
                        ), 'warning');
                        $this->connEvent($id, 'subscriber_hb_kick', ['idle' => $n]);
                        if (!Worker::$daemonize) {
                            $output->writeln("<error>[MarketDispatch:{$port}]</error> 心跳超时，回收死连接 conn={$id} (静默 {$n}s)");
                        }
                    }
                    $conn->destroy();
                }
            }));

            // ── 快照 tick ────────────────────────────────────────────────────────
            // 每 tick 原子取走脏字段 -> 批量 HMGET -> 逐帧按房间推送。
            // 发送量 = tick 频率 × 脏字段数，与订阅方快慢、与停摆时长都无关，
            // 这是覆盖语义相对堆积语义最本质的差别：慢消费者不再产生上游积压。
            \Workerman\Timer::add($tickMs / 1000, $this->timedHandler('snapshot_tick', function () use ($output) {
                $redis = redis_client();
                if (!$redis) return;

                // 无订阅方时照样取走脏集合：脏集合只是"这一轮变过谁"的索引，攒着没有意义，
                // 反而会在订阅方接上的那一刻堆出一大波。新连接握手后由 replaySnapshotTo() 补它订阅的那部分
                $fields = MarketSnapshotStore::swapDirtyFields($redis, $this->type);
                if (empty($fields)) return;

                $frames = MarketSnapshotStore::readFields($redis, $this->type, $fields);
                if (empty($frames)) return;

                $t0 = microtime(true);
                $out = [];
                foreach ($frames as $field => $frameJson) {
                    $this->stat['popped']++;
                    $parsed = MarketSnapshotStore::parseField($field);
                    $ch = $parsed['channel'] ?? '?';
                    $pk = $parsed['pair'] ?? '?';
                    $this->stat['by_chan'][$ch] = ($this->stat['by_chan'][$ch] ?? 0) + 1;
                    // [埋点·scope=dispatch] A 端「发出」这一跳，见 MarketMetrics::touchGroup() 约定表。
                    // v3 起这一跳按 pair 记，collect/land 两跳仍按 source_key 记（采集面没变）
                    MarketMetrics::touchGroup(
                        MarketMetrics::SCOPE_DISPATCH, $ch, $pk,
                        strlen($frameJson), $parsed['suffix'] ?? ''
                    );
                    $stream = $this->streamOfField((string)$field);
                    if ($stream === null) continue;
                    $out[] = ['pk' => $pk, 'ch' => $ch, 'stream' => $stream, 'json' => $frameJson];
                }

                // 只在前台记账：唯一的读取方 flushTraceSummary() 的定时器只在 !daemonize 时注册
                if (!Worker::$daemonize && $out) {
                    $this->traceTick($out, $output);
                }
                if ($this->subscribers) {
                    foreach ($out as $f) $this->deliver($f['stream'], $f['json']);
                }
                $this->stat['tick_max_ms'] = max($this->stat['tick_max_ms'], round((microtime(true) - $t0) * 1000, 1));
            }));

            // ── depth 慢档 ─────────────────────────────────────────────────────
            // 每个比 tick 慢的 depth 间隔一个定时器，到点把这段时间变过的 depth 各发一次最新值。
            // 覆盖语义：500ms 内变了 5 次也只发最后一次，这正是降频要的效果
            $tiers = array_unique(array_merge(MarketWireProtocol::DEPTH_INTERVALS, array_filter(self::DEGRADE_FLOORS)));
            foreach ($tiers as $iv) {
                if ($iv <= $tickMs) continue;
                \Workerman\Timer::add($iv / 1000, $this->timedHandler("depth_{$iv}ms", fn() => $this->flushTier($iv)));
            }
        };

        Worker::runAll();
    }

    /**
     * 连接健康分布，形如 `[ok:2 lagging:1]`。
     * subs=3 只说明有三条 TCP 连着，不回答其中几条真的在收数据
     */
    private function connHealthSummary(): string
    {
        $by = [];
        foreach (array_keys($this->subscribers) as $id) {
            $st = $this->bufState[$id] ?? null;
            if ($st === null) continue;
            $h = self::connHealth($st);
            $by[$h] = ($by[$h] ?? 0) + 1;
        }
        if (!$by) return '-';
        // ok 排最后：出问题的排前面，与监控页的排序原则一致
        uksort($by, fn($a, $b) => [$a === 'ok', $a] <=> [$b === 'ok', $b]);
        $parts = [];
        foreach ($by as $h => $c) $parts[] = $h . ':' . $c;
        return '[' . implode(' ', $parts) . ']';
    }

    /**
     * 按订阅方机器聚合的连接数，形如 `[svr-b:2 svr-c:1]`。
     * 掉一条是掉了一台机器、还是只掉了那台机器的一路订阅，处理完全不同
     */
    private function nodesSummary(): string
    {
        $by = [];
        foreach (array_keys($this->subscribers) as $id) {
            $n = (string)($this->bufState[$id]['node'] ?? '');
            $by[$n === '' ? '?' : $n] = ($by[$n === '' ? '?' : $n] ?? 0) + 1;
        }
        if (!$by) return '-';
        ksort($by);
        $parts = [];
        foreach ($by as $n => $c) $parts[] = $n . ':' . $c;
        return '[' . implode(' ', $parts) . ']';
    }

    /** 各订阅方的 RTT 摘要，形如 `2:avg12/max48`。没有任何 pong 时返回 '-' 而不是 0 */
    private function rttSummary(): string
    {
        $parts = [];
        foreach ($this->bufState as $id => $st) {
            if (($st['rtt_n'] ?? 0) <= 0) continue;
            $parts[] = sprintf('%d:avg%.0f/max%.0f', $id, $st['rtt_sum'] / $st['rtt_n'], $st['rtt_max']);
        }
        return $parts ? implode(' ', $parts) : '-';
    }

    /**
     * 归零 10 秒窗口的分发计数器。必须聚合后定期输出，不能每次丢包写一条日志 ——
     * 丢包可能每秒上千次，而 business_log 是同步落盘
     */
    private function resetStat(): void
    {
        $this->stat = [
            'popped'      => 0,   // 从快照取出的帧数
            'msgs'        => 0,   // 成功 send() 的消息条数(含"仅进缓冲")，全部连接合计，不含快照重放。一条 = 一帧
            'bytes'       => 0,   // 同上口径的压缩后字节数
            'kind_bytes'  => [],  // stream 类别 => 字节数，同上口径
            'deflate'     => 0,   // 压缩次数。房间模型下 ≈ 有人订阅的变化帧数，与连接数无关；明显高于 popped 才值得查
            'tick_max_ms' => 0.0, // 单个 tick（取帧 + 推送）的最长耗时。逼近 tick_ms 说明分发机跟不上节拍
            'send_false'  => 0,   // send() 返回 false 的次数(缓冲满被丢弃)
            'send_fail'   => 0,   // onError 收到 SEND_FAIL 的次数
            'buf_full'    => 0,
            'buf_drain'   => 0,
            'skipped'     => 0,   // 因订阅方 LAGGING 而主动跳过的发送次数
            'degrade_down' => 0,  // depth 降档次数
            'degrade_up'   => 0,  // depth 升档次数
            'hb_kick'     => 0,   // 心跳超时被回收的死连接数
            'lag_close'   => 0,   // 积压超时被断开的连接数
            'by_chan'     => [],
        ];
    }

    /** 每连接发出统计窗口清零，与 resetStat() 同拍 */
    private function resetSendWindows(): void
    {
        $now = microtime(true);
        foreach ($this->bufState as $id => $_) {
            $this->bufState[$id]['win_msgs']   = 0;
            $this->bufState[$id]['win_bytes']  = 0;
            $this->bufState[$id]['win_kind']   = [];
            $this->bufState[$id]['buf_peak']   = 0;
            $this->bufState[$id]['win_drop']   = 0;
            $this->bufState[$id]['win_since']  = $now;
        }
    }

    /**
     * 每个 tick 的观测记账（--trace 汇总 + 明细）。只做数组自增与至多一次 ts 采样
     */
    private function traceTick(array $frames, ?Output $output): void
    {
        $this->trace['ticks']++;
        $this->trace['n'] += count($frames);
        foreach ($frames as $f) {
            $this->trace['chan'][$f['ch']] = ($this->trace['chan'][$f['ch']] ?? 0) + 1;
            $this->trace['bytes'] += strlen($f['json']);
        }
        // 滞后采样每 tick 只取一帧，且只从「ts 表示这份快照何时生成」的频道取，见 AGE_SAMPLE_CHANNELS
        $lagMs = 0.0;
        $probe = 0;
        foreach ($frames as $f) {
            if (!isset(self::AGE_SAMPLE_CHANNELS[$f['ch']])) continue;
            $ts = self::peekTs($f['json']);
            if ($ts > 0) {
                $lagMs = microtime(true) * 1000 - $ts;
                break;
            }
            if (++$probe >= 5) break;
        }
        if ($lagMs > $this->trace['age_max']) $this->trace['age_max'] = $lagMs;
        if ($this->trace['detail'] && $output !== null) {
            $this->traceFrames($frames, count($this->subscribers), $output);
        }
    }

    private function traceFrames(array $frames, int $subs, Output $output): void
    {
        $nowSec = (int)microtime(true);
        if ($nowSec !== $this->trace['sec']) {
            // 跨秒：先把上一秒被折叠的条数交代清楚。看到的明细若像是完整的、实际漏了一大半，比不打更误导
            if ($this->trace['cut'] > 0) {
                $output->writeln(sprintf(
                    '<comment>[trace]</comment> …上一秒另有 %d 帧未打印（--trace-rate=%d 限制，可调大或加过滤串）',
                    $this->trace['cut'], $this->trace['rate']
                ));
            }
            $this->trace['sec'] = $nowSec;
            $this->trace['lines'] = 0;
            $this->trace['cut'] = 0;
        }

        $nowMs = microtime(true) * 1000;
        foreach ($frames as $f) {
            if ($this->trace['filter'] !== ''
                && stripos($f['ch'], $this->trace['filter']) === false
                && stripos($f['pk'], $this->trace['filter']) === false
            ) {
                continue;
            }

            if ($this->trace['rate'] > 0 && $this->trace['lines'] >= $this->trace['rate']) {
                $this->trace['cut']++;
                continue;
            }
            $this->trace['lines']++;

            $ts = self::peekTs($f['json']);
            $age = $ts > 0 ? sprintf('%.0fms', $nowMs - $ts) : '-';
            if ($ts > 0 && ($nowMs - $ts) > $this->trace['age_max']) $this->trace['age_max'] = $nowMs - $ts;

            $line = sprintf(
                '<comment>[trace]</comment> %s %-10s %-24s %6s age=%-8s →%d',
                date('H:i:s') . sprintf('.%03d', (int)fmod($nowMs, 1000)),
                MarketChannelEnum::shorten($f['ch']),
                $f['pk'],
                format_bytes(strlen($f['json'])),
                $age,
                $subs
            );
            if ($this->trace['body']) {
                $body = $f['json'];
                $line .= "\n          " . (strlen($body) > 240 ? substr($body, 0, 240) . '…' : $body);
            }
            $output->writeln($line);
        }
    }

    /** 每秒一行的汇总。成本与帧率无关，回答"分发频率"；10 秒那条 stat 回答"健康状况" */
    private function flushTraceSummary(int $subs, Output $output): void
    {
        if ($this->trace['n'] === 0) {
            return;   // 没数据不刷屏；真的断流了，10 秒那条 stat 会以 popped=0 体现
        }

        $chan = [];
        arsort($this->trace['chan']);
        foreach ($this->trace['chan'] as $c => $n) {
            $chan[] = MarketChannelEnum::shorten((string)$c) . '=' . $n;
        }

        $output->writeln(sprintf(
            '<info>[dispatch]</info> %s  frames=%-5d msgs=%-5d %8s  ticks=%-3d subs=%d  age~%s  chan[%s]',
            date('H:i:s'),
            $this->trace['n'], $this->trace['msgs'], format_bytes($this->trace['bytes']),
            $this->trace['ticks'], $subs,
            $this->trace['age_max'] > 0 ? sprintf('%.0fms', $this->trace['age_max']) : '-',
            implode(' ', $chan)
        ));

        $this->trace['n'] = $this->trace['bytes'] = $this->trace['msgs'] = $this->trace['ticks'] = 0;
        $this->trace['chan'] = [];
        $this->trace['age_max'] = 0.0;
    }

    /** 从帧里摘毫秒时间戳，用于算滞后。depth 的 ts 在 depth.ts 里，正则一并覆盖 */
    private static function peekTs(string $frameJson): int
    {
        if (preg_match('/"ts":(\d{10,})/', $frameJson, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * 解析实际用于 bind 的监听地址，绑不上就退回 0.0.0.0。
     * 云主机的公网 IP 通常由网关 NAT 映射、不在本机网卡上，bind 会直接失败，服务根本起不来
     */
    private function resolveBindHost(string $host, Output $output): string
    {
        $host = trim($host);
        if ($host === '' || $host === '0.0.0.0') return '0.0.0.0';

        // 端口用 0：只验证"这个地址能不能绑"，不占用目标端口
        $probe = @stream_socket_server("tcp://{$host}:0", $errno, $errstr);
        if ($probe !== false) {
            fclose($probe);
            return $host;
        }

        $msg = sprintf(
            '[MarketDispatch] market.dispatch.host=%s 无法绑定 (%s)，已退回 0.0.0.0。'
            . '云主机的公网 IP 通常由网关 NAT 映射、不在本机网卡上，不能直接 bind；'
            . '要对外监听请填 0.0.0.0，要限制来源请用 market.inner_api.allow_ips。',
            $host, $errstr ?: ('errno ' . $errno)
        );
        business_log('market_dispatch_stat', $msg, 'warning');
        if (!Worker::$daemonize) {
            $output->writeln("<error>{$msg}</error>");
        }
        return '0.0.0.0';
    }

    /**
     * 一帧推给订阅了它的连接 —— 房间模型的全部核心。
     *
     * 只循环这一帧所在房间的成员（点名订阅的 + 全量订阅的），不循环订阅组合：
     * 压缩次数 = 有人订阅的变化帧数，与连接数、订阅组合有多少种都无关。
     * depth 的成员如果间隔慢于 tick（订阅时选了慢档，或被降级），本 tick 不发，记进对应慢档等定时器发。
     */
    private function deliver(string $stream, string $json): void
    {
        $isDepth = MarketWireProtocol::isDepthStream($stream);
        if ($isDepth) $this->latestDepth[$stream] = $json;

        $now = [];
        foreach ($this->membersOf($stream) as $id) {
            if ($isDepth) {
                $iv = $this->depthInterval($id, $stream);
                if ($iv > $this->tickMs) {
                    $this->tierPending[$iv][$stream] = true;
                    continue;
                }
            }
            $now[] = $id;
        }
        if ($now) $this->sendFrame($now, $stream, $json);
    }

    /** 慢档定时器：把这段时间变过的 depth 各发一次最新值，只发给当前正处在这一档的成员 */
    private function flushTier(int $iv): void
    {
        $pending = $this->tierPending[$iv] ?? [];
        if (!$pending) return;
        $this->tierPending[$iv] = [];

        foreach ($pending as $stream => $_) {
            $json = $this->latestDepth[$stream] ?? null;
            if ($json === null) continue;
            $ids = [];
            foreach ($this->membersOf($stream) as $id) {
                if ($this->depthInterval($id, $stream) === $iv) $ids[] = $id;
            }
            if ($ids) $this->sendFrame($ids, $stream, $json);
        }
    }

    /** @return array<int,int> 某个 stream 的全部接收方：点名订阅的 + 全量订阅的 */
    private function membersOf(string $stream): array
    {
        return array_keys(($this->rooms[$stream] ?? []) + $this->allRoom);
    }

    /** 某连接某 depth stream 当前的推送间隔(毫秒)：订阅时声明的间隔与降级下限取大 */
    private function depthInterval(int $id, string $stream): int
    {
        $st = $this->bufState[$id];
        $asked = $st['streams'][$stream] ?? MarketWireProtocol::DEPTH_INTERVALS[0];
        return max($asked, self::DEGRADE_FLOORS[$st['degrade']]);
    }

    /**
     * 压缩一次、发给这批连接。LAGGING 的连接跳过；全部跳过就连压缩都省了。
     *
     * @param bool $countSend 是否计入发出统计（msgs 与每连接窗口）。快照重放传 false，
     *                        否则重连后那一波会把发出速率拉高
     */
    private function sendFrame(array $ids, string $stream, string $json, bool $countSend = true): void
    {
        $payload = null;
        $kind = '';
        foreach ($ids as $id) {
            $conn = $this->subscribers[$id] ?? null;
            if ($conn === null) continue;
            if ($this->bufState[$id]['state'] === MarketSubscriberStateEnum::LAGGING) {
                $this->bufState[$id]['skip']++;
                $this->stat['skipped']++;
                continue;
            }
            if ($payload === null) {
                $payload = MarketWireProtocol::encodeFrame($stream, $json);
                $kind = MarketWireProtocol::streamKind($stream);
                $this->stat['deflate']++;
            }
            $this->sendOne($conn, $payload, $countSend, $kind);
        }
    }

    /** 快照 field -> stream 名，带缓存：每 tick 每帧都要问一次，field 全集却几乎不变 */
    private function streamOfField(string $field): ?string
    {
        if (array_key_exists($field, $this->fieldStreams)) return $this->fieldStreams[$field];
        if (count($this->fieldStreams) > self::FIELD_STREAM_MAX) $this->fieldStreams = [];
        return $this->fieldStreams[$field] = MarketWireProtocol::streamOfField($field);
    }

    /** 把一份已解析的 hello 落到连接状态上：先退出旧房间再进新房间，再发一次 hello 等于整个替换订阅 */
    private function applyHello($conn, array $hello, Output $output): void
    {
        $id = $conn->id;
        $this->leaveRooms($id);

        $this->bufState[$id]['node']    = (string)($hello['node'] ?? '');
        $this->bufState[$id]['streams'] = $hello['streams'];
        if ($hello['streams'] === null) {
            $this->allRoom[$id] = true;
        } else {
            foreach ($hello['streams'] as $stream => $_) $this->rooms[$stream][$id] = true;
        }
        // 订阅变了要重新补快照
        $this->bufState[$id]['replayed'] = false;

        // 按"第几条成功解析的 hello"计：首条恰好非法时，真正的那条 hello 不该因此丢掉事件
        if (++$this->bufState[$id]['hello_ok'] === 1) $this->connEvent($id, 'subscriber_hello', [
            'streams' => self::describeStreams($hello['streams']),
        ]);
        if (!Worker::$daemonize) {
            $output->writeln(sprintf(
                '<info>[MarketDispatch]</info> hello conn=%d node=%s streams=%s',
                $id, ($hello['node'] ?? '') !== '' ? $hello['node'] : '?', self::describeStreams($hello['streams'])
            ));
        }
    }

    /** 连接退出它所在的全部房间。空房间顺手删掉，rooms 的大小就是"当前有人订的 stream 数" */
    private function leaveRooms(int $id): void
    {
        unset($this->allRoom[$id]);
        foreach (array_keys($this->bufState[$id]['streams'] ?? []) as $stream) {
            unset($this->rooms[$stream][$id]);
            if (empty($this->rooms[$stream])) unset($this->rooms[$stream]);
        }
    }

    /**
     * depth 降一档(+1)或升一档(-1)。只改档位与计时，通知与补发交给 applyDegrade() ——
     * 降档发生在缓冲满的时候，那一刻什么都发不出去
     *
     * @param string $reason soft = 过软水位 / full = 缓冲满 / recover = 升档，记进分发事件
     * @param int    $buf    当时的发送缓冲字节数，记进分发事件
     */
    private function stepDegrade(int $id, int $dir, string $reason, int $buf): void
    {
        $st = $this->bufState[$id] ?? null;
        if ($st === null || empty($st['hello_ok'])) return;

        $now = microtime(true);
        $level = max(0, min(count(self::DEGRADE_FLOORS) - 1, $st['degrade'] + $dir));
        // 已在最慢档时再满也刷新计时：升档要从最近一次满起算
        $this->bufState[$id]['degrade_at'] = $now;
        if ($level === $st['degrade']) return;

        $this->bufState[$id]['degrade'] = $level;
        if ($dir > 0) {
            $this->bufState[$id]['degrade_n']++;
            if ($st['degrade'] === 0) $this->bufState[$id]['degraded_since'] = $now;
        } elseif ($level === 0) {
            $this->bufState[$id]['degraded_since'] = 0.0;
        }
        $this->stat[$dir > 0 ? 'degrade_down' : 'degrade_up']++;
        $this->connEvent($id, $dir > 0 ? 'subscriber_degrade' : 'subscriber_recover', [
            'reason'  => $reason,
            'from_ms' => self::DEGRADE_FLOORS[$st['degrade']],
            'depth_ms' => self::DEGRADE_FLOORS[$level],
            'buf'     => $buf,
            // 升回 0 档时：这一轮降级一共持续了多久
            'degraded_sec' => $level === 0 && $st['degraded_since'] > 0 ? (int)($now - $st['degraded_since']) : null,
        ]);
    }

    /** 记一条分发事件：带上这条连接的 conn / node / sub / ip，见 MarketMetrics::dispatchEvent() */
    private function connEvent(int $id, string $type, array $fields): void
    {
        $st = $this->bufState[$id] ?? [];
        MarketMetrics::dispatchEvent($this->type, $type, [
            'conn' => $id,
            'node' => (string)($st['node'] ?? ''),
            'sub'  => (string)($st['sub'] ?? ''),
            'ip'   => (string)($st['ip'] ?? ''),
        ] + $fields);
    }

    /**
     * 档位变了之后：告诉订阅方现在的 depth 下限，并把它的 depth 按新档位补一次最新值。
     *
     * 补发是必须的：换档那一刻刚记进旧档待发的更新，旧档定时器按"当前档位"筛成员时会把它漏掉；
     * 冷门 symbol 的 depth 可能很久才再变一次，不补就一直停在旧值。补发是覆盖语义，重复也无害
     */
    private function applyDegrade(int $id): void
    {
        $st = $this->bufState[$id] ?? null;
        $conn = $this->subscribers[$id] ?? null;
        if ($st === null || $conn === null || $st['state'] === MarketSubscriberStateEnum::LAGGING) return;

        $depthStreams = $st['streams'] === null
            ? array_keys($this->latestDepth)
            : array_filter(array_keys($st['streams']), [MarketWireProtocol::class, 'isDepthStream']);
        if (!$depthStreams) return;   // 没订 depth，降不降级对它没有区别，也不必通知

        $floor = self::DEGRADE_FLOORS[$st['degrade']];
        if ($floor !== $st['told_ms']) {
            if (self::sendSysFrame($conn, MarketPublisher::sysFrame(
                MarketPublisher::SYS_THROTTLE, (int)(microtime(true) * 1000), ['depth_ms' => $floor]
            ))) {
                $this->bufState[$id]['told_ms'] = $floor;
            }
        }

        foreach ($depthStreams as $stream) {
            $iv = $this->depthInterval($id, $stream);
            if ($iv > $this->tickMs) {
                $this->tierPending[$iv][$stream] = true;
            } elseif (isset($this->latestDepth[$stream])) {
                $this->sendFrame([$id], $stream, $this->latestDepth[$stream], false);
            }
        }
    }

    /**
     * 发一条 _sys 系统帧（ping / throttle / error）。
     *
     * 必须临时切回 TEXT 帧(opcode 0x1)：行情连接的 websocketType 是 BINARY，而 _sys 帧
     * 永远是明文 JSON、从不压缩。用 BINARY 发出去载荷照样读得出，但通用 WS 工具会把它显示成 hex。
     * 逐帧切换是 Workerman 自己对控制帧的做法，opcode 本来就是每帧独立的
     */
    private static function sendSysFrame($conn, string $payload): bool
    {
        $prev = $conn->websocketType ?? null;
        $conn->websocketType = \Workerman\Protocols\Websocket::BINARY_TYPE_BLOB;
        $r = $conn->send($payload);
        if ($prev !== null) $conn->websocketType = $prev;
        return $r !== false;
    }

    /**
     * 把一条连接的判据合成一句人话。顺序即优先级：越靠前的越"它收不到数据"。
     * 刻意做成派生视图而不是把 state/closing/hello_ok/replayed/degrade 收敛成一个枚举：它们是正交的几维
     */
    private static function connHealth(array $st): string
    {
        if (!empty($st['closing'])) return 'closing';
        if (($st['state'] ?? MarketSubscriberStateEnum::ACTIVE) === MarketSubscriberStateEnum::LAGGING) return 'lagging';
        if (empty($st['hello_ok'])) return 'no_hello';   // 还没收到合法 hello：不推送、无 node
        if (empty($st['replayed'])) return 'handshaking';
        if (!empty($st['degrade'])) return 'degraded';   // 在收数据，只是 depth 被降了频
        return 'ok';
    }

    private function summarizeBufState(): array
    {
        $out = [];
        $now = microtime(true);
        foreach ($this->bufState as $id => $st) {
            // 窗口从上次清零或连上那一刻算起：刚连上不满 10 秒的按实际秒数折算，不固定除以 10。
            // 下限 1 秒，免得刚连上几毫秒就发出一条被折算成几百条/秒
            $winSec = max(1.0, $now - (float)($st['win_since'] ?? $now));
            $kinds = [];
            foreach ($st['win_kind'] ?? [] as $kind => $bytes) $kinds[$kind] = (int)round($bytes / $winSec);
            $buf = isset($this->subscribers[$id]) ? $this->subscribers[$id]->getSendBufferQueueSize() : 0;
            $out[$id] = [
                'msgs_ps'   => round((int)($st['win_msgs'] ?? 0) / $winSec, 1),
                'bytes_ps'  => (int)round((int)($st['win_bytes'] ?? 0) / $winSec),
                // stream 类别 => 字节/秒
                'kinds'     => $kinds,
                'ip'        => (string)($st['ip'] ?? ''),
                'alive_sec' => (int)($now - (float)($st['connected_at'] ?? $now)),
                'buf'       => $buf,
                'buf_peak'  => max($buf, (int)($st['buf_peak'] ?? 0)),
                'streams_n' => ($st['streams'] ?? null) === null ? null : count($st['streams']),
                'degrade_n' => (int)($st['degrade_n'] ?? 0),
                // 这一轮降级已持续多久(秒)，0 = 没降级
                'degraded_sec' => ($st['degraded_since'] ?? 0) > 0 ? (int)($now - $st['degraded_since']) : 0,
                'drop_10s'  => (int)($st['win_drop'] ?? 0),
                'health'   => self::connHealth($st),
                'node'     => (string)($st['node'] ?? ''),
                'sub'      => (string)($st['sub'] ?? ''),
                'state'    => ($st['state'] ?? MarketSubscriberStateEnum::ACTIVE)->value,
                'full'     => (int)($st['full'] ?? 0),
                'ms'       => (int)($st['ms'] ?? 0),
                'skip'     => (int)($st['skip'] ?? 0),
                // LAGGING 已经持续多久（秒）。"刚满一下"与"堵了五分钟"不能长得一样
                'held'     => ($st['state'] ?? null) === MarketSubscriberStateEnum::LAGGING
                              && ($st['since'] ?? 0) > 0
                              ? (int)(microtime(true) - $st['since']) : 0,
                'rtt_avg'  => ($st['rtt_n'] ?? 0) > 0 ? round($st['rtt_sum'] / $st['rtt_n'], 1) : null,
                'rtt_max'  => ($st['rtt_n'] ?? 0) > 0 ? round((float)$st['rtt_max'], 1) : null,
                'streams'  => self::describeStreams($st['streams'] ?? null),
                // 当前 depth 降级下限(ms)，0 = 没降级
                'depth_ms' => self::DEGRADE_FLOORS[$st['degrade'] ?? 0],
            ];
        }
        return $out;
    }

    /**
     * 给刚订阅的连接补一次快照：只补它订阅的那些 stream，每个 stream 一条消息。
     *
     * 没有这一步，新连接只能收到它连上之后才变脏的字段：冷门 symbol 的 price/depth
     * 会空好几分钟，而监控页的「分发」列一切正常。只发给这一个连接，不广播。
     * 覆盖语义让这件事是幂等的，所以不需要和 tick 做任何互斥
     */
    private function replaySnapshotTo($conn, Output $output): void
    {
        $id = $conn->id;
        if (!isset($this->bufState[$id]) || !empty($this->bufState[$id]['replayed'])) return;
        $this->bufState[$id]['replayed'] = true;

        $redis = redis_client();
        if (!$redis) return;

        $streams = $this->bufState[$id]['streams'];
        if ($streams === null) {
            $all = MarketSnapshotStore::readAll($redis, $this->type);
        } else {
            $fields = [];
            foreach ($streams as $stream => $_) {
                $field = MarketWireProtocol::fieldOfStream($stream, $this->type);
                if ($field !== null) $fields[] = $field;
            }
            $all = MarketSnapshotStore::readFields($redis, $this->type, $fields);
        }

        $n = 0;
        foreach ($all as $field => $json) {
            $stream = $this->streamOfField((string)$field);
            if ($stream === null) continue;
            if (MarketWireProtocol::isDepthStream($stream)) $this->latestDepth[$stream] ??= $json;
            $this->sendFrame([$id], $stream, $json, false);
            $n++;
        }

        // 订阅了但快照里还没有的 stream（symbol 写错、或这个 pair 还没开采）不报错，只记数：
        // 订阅方对着空流排查时，subscriber_hello 与这里的 fields 一对就知道是哪一边的问题
        $this->connEvent($id, 'snapshot_replay', ['fields' => $n, 'asked' => $streams === null ? 'all' : count($streams)]);
        if (!Worker::$daemonize) {
            $output->writeln(sprintf(
                '<info>[MarketDispatch]</info> snapshot replay conn=%d fields=%d/%s',
                $id, $n, $streams === null ? 'all' : count($streams)
            ));
        }
    }

    /** 日志里的一行式订阅摘要。超过 8 个只报数量，别把整个列表塞进指标事件 */
    private static function describeStreams(?array $streams): string
    {
        if ($streams === null) return 'all';
        if (count($streams) > 8) return count($streams) . ' streams';
        $parts = [];
        foreach ($streams as $stream => $iv) {
            // 默认档不写，与订阅方 hello 里的常见写法一致
            $parts[] = $iv > MarketWireProtocol::DEPTH_INTERVALS[0] ? "{$stream}@{$iv}ms" : $stream;
        }
        return implode('+', $parts);
    }

    /**
     * 实际发送。send() 返回 false 即缓冲已满、这一条被丢弃(Workerman 内部同时触发 onError SEND_FAIL)。
     * 只累加计数，不在这里写日志
     *
     * @param string $kind stream 类别（MarketWireProtocol::streamKind），按类别统计流量用
     */
    private function sendOne($conn, string $payload, bool $countSend, string $kind): void
    {
        $id = $conn->id;
        if ($conn->send($payload) === false) {
            $this->stat['send_false']++;
            // 丢弃不分是不是快照重放都记：那是实打实的损失
            if (isset($this->bufState[$id])) $this->bufState[$id]['win_drop']++;
            return;
        }
        if (!$countSend) return;

        $len = strlen($payload);
        $this->stat['msgs']++;
        $this->stat['bytes'] += $len;
        $this->stat['kind_bytes'][$kind] = ($this->stat['kind_bytes'][$kind] ?? 0) + $len;
        $this->trace['msgs']++;
        if (isset($this->bufState[$id])) {
            $st = &$this->bufState[$id];
            $st['win_msgs']++;
            $st['win_bytes'] += $len;
            $st['win_kind'][$kind] = ($st['win_kind'][$kind] ?? 0) + $len;
            unset($st);
        }
    }

    private function initWorkerEnv(): void
    {
        try {
            \think\facade\Db::close();
        } catch (\Exception $e) {
        }
        \think\facade\Config::set(['break_reconnect' => true], 'database.connections.mysql');
    }

    /**
     * DB 心跳检测：连接断开则关闭，下次查询重新建连
     */
    private function pingDb(): void
    {
        try {
            \think\facade\Db::query('SELECT 1');
        } catch (\Throwable $e) {
            \think\facade\Db::close();
        }
    }
}
