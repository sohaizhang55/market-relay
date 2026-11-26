<?php

declare(strict_types=1);

namespace app\lib;

use Workerman\Timer;
use Workerman\Worker;

/**
 * 通用 Workerman 常驻进程心跳上报服务
 *
 * 用途：检测「进程 PID 仍存活，但事件循环已被阻塞式调用/死循环卡死，
 * 业务代码不再执行」的半死状态——这种情况 Workerman/系统进程守护
 * 都无法感知（它们只能感知进程退出），必须由业务自己上报活体信号。
 *
 * 用法：在各 Workerman command 的 onWorkerStart 内调用一次即可，
 * 内部通过独立 Timer 低频上报，不影响原有的高频业务 Timer。
 */
class CommandHeartBeatService
{
    /**
     * 心跳 key。写者只有本类，所以定义也只该有本类这一处 ——
     * 读者（CommandHeartbeatCheckExpire 的过期检查、MarketMonitor 的存活判定）
     * 此前各自硬编码了同样的字面量，改前缀时会漏掉其中某一处，
     * 而漏掉的后果是"心跳一切正常"或"全部离线"这种最难察觉的错。
     *
     * 拼出来长这样：heartbeat:cmd:market_relay_MarketDbFlusher，TTL 15s，值为 JSON{pid,tick,ts,tasks}
     */
    public const KEY_PREFIX   = 'heartbeat:cmd:';

    /**
     * 实例注册表。`heartbeat:cmd:{name}` 是 setex 覆盖写的——**多个同名实例互相覆盖同一个 key**，
     * 于是「有几个在跑」这个问题它根本回答不了，判活侧看到的永远是「齐全」。
     * 现网真出过：4 个合约撮合引擎同时撮同一个订单簿，而所有检查都说正常。
     *
     * 这里按实例各占一个 field：
     *   HASH heartbeat:inst:{name}   field = {hostname}#{pid}   value = 最后心跳的毫秒时间戳
     *
     * ⚠️ 为什么是 HASH 不是 SET：SET 成员没法单独过期，kill -9 之后会永远留在里面——
     * 那正是 REGISTRY_KEY 只 sAdd 从不 sRem 的老毛病。HASH 的 value 放时间戳，
     * 每个成员自带过期依据，读侧顺手剪枝。
     *
     * ⚠️ 为什么 field 要带 hostname：心跳 payload 里只有 pid、没有主机名，
     * 多机共用同一个 Redis 时分不出这个心跳是不是本机的——而 posix_kill 只能验本机的 pid。
     *
     * 拼出来长这样：heartbeat:inst:market_relay_MarketDbFlusher，HGETALL 可见全部实例
     */
    public const KEY_INST_PREFIX = 'heartbeat:inst:';

    /**
     * 默认心跳间隔（秒）。判活那一侧要用它把 tick 换算成运行时长，
     * 所以定义只能有这一处 —— 两边各写一个 5，改了其中一个就会静默算错。
     */
    public const DEFAULT_INTERVAL = 5;
    /** 跑过的 worker 名字集合：SET heartbeat:registry。只 sAdd 从不 sRem，SMEMBERS 看全量 */
    public const REGISTRY_KEY = 'heartbeat:registry';

    /**
     * 「我正在正常停止」的标记：停止中的进程自己写，起来的进程首拍删。
     *
     * 只有 heartbeat:cmd 一个信号时，「key 不在」分不出计划内停机与进程真的没了，
     * 于是每次正常重启都推一条误报。⚠️ kill -9、事件循环卡死、PHP fatal 都写不出它，
     * 那几种照常告警。
     *
     * 拼出来长这样：heartbeat:stopping:market_relay_MarketDbFlusher，TTL 60s，值为 pid
     */
    public const KEY_STOPPING_PREFIX = 'heartbeat:stopping:';

    /** 兜底寿命。正常路径下新进程首拍就删了；这个 TTL 只兜「停了但起不来」，到点恢复告警 */
    private const STOPPING_TTL = 60;

    /**
     * 启动心跳上报
     *
     * 收 Worker 实例而不是名字：停机标记要挂在 $worker->onWorkerStop 上，光有名字够不着。
     *
     * @param Worker $worker 本进程的 Worker，其 name 即指令唯一标识
     * @param int $interval 心跳间隔（秒）；健康检查以该值的 3 倍作为过期阈值
     */
    public static function start(Worker $worker, int $interval = self::DEFAULT_INTERVAL): void
    {
        $commandName = $worker->name;

        // 这里是所有常驻命令都会走的统一入口，且就在 onWorkerStart 里 ——
        // 拿它当"进程启动时刻"的记录点，一处调用就让全体 worker 的卡片有了运行时长，
        // 不用去每个 command 里各加一遍（也就不会有人漏加）
        MarketMetrics::markBoot();

        // 优雅退出时留下「我在停」。child 收到 SIGINT/SIGQUIT 必经 stopAll -> stop(false) -> 此回调
        $worker->onWorkerStop = static function () use ($commandName) {
            try {
                $redis = redis_client();
                if ($redis) {
                    $redis->setex(self::KEY_STOPPING_PREFIX . $commandName, self::STOPPING_TTL, (string)getmypid());
                }
            } catch (\Throwable $e) {
                // 写不进去就退化成误报一次 —— 在退出路径上抛异常比误报严重得多
            }
        };

        $tick = 0;

        $beat = function () use ($commandName, $interval, &$tick) {
            $tick++;
            $pid = getmypid();

            try {
                $redis = redis_client();
                if ($redis) {
                    $redis->sAdd(self::REGISTRY_KEY, $commandName);
                    // tasks：进程内各常驻任务最后一次执行的时刻。
                    //
                    // ⚠️ 心跳本身只证明"事件循环还活着"，证明不了"那件事还在做" ——
                    // Workerman 吞掉定时器回调里的异常并继续调度，于是
                    // 「撮合进程 PID 在、心跳每 5 秒照写，而撮合那个 Timer 早就不跑了」
                    // 完全可能，且从进程视角一点看不出来。
                    // 把任务快照顺路带出去，判活那一侧才有第二个维度可用。
                    // 搭已有的心跳定时器，不新增 Timer；热路径只动内存，见 TaskLiveness
                    $redis->setex(self::KEY_PREFIX . $commandName, $interval * 3, json_encode([
                        'pid'   => $pid,
                        'tick'  => $tick,
                        'ts'    => time(),
                        'tasks' => TaskLiveness::snapshot(),
                    ]));

                    // 实例注册：搭同一趟心跳，不新增 Timer。见 KEY_INST_PREFIX 注释
                    $instKey = self::KEY_INST_PREFIX . $commandName;
                    $redis->hSet($instKey, self::instanceField(), (int)(microtime(true) * 1000));
                    // 整个哈希也给一个远大于剪枝阈值的 TTL：全部实例都被 kill -9 时，
                    // 没有任何读者的情况下它自己也会消失，不留永久垃圾
                    $redis->expire($instKey, max(3600, $interval * 60));

                    // 第二拍才宣告"我起来了"。首拍是同步调用，那时 onWorkerStart 后面的初始化
                    // 还没跑；第二拍由 Timer 发，能发出来就证明事件循环在转、初始化确实完了。
                    // 首拍就删的话，初始化阻塞超过心跳 TTL 时两个 key 会同时不在
                    if ($tick === 2) {
                        $redis->del(self::KEY_STOPPING_PREFIX . $commandName);
                    }
                }
            } catch (\Throwable $e) {
                // Redis 异常不应影响业务主循环，静默降级为仅记录日志
            }

            business_log('heartbeat', "[Heartbeat] {$commandName} pid={$pid} tick={$tick}");
        };

        $beat();
        Timer::add($interval, $beat);
    }

    /**
     * 本机上这个进程号是否存在（不管它属于哪个用户）。
     *
     * posix_kill($pid, 0) 只探测、不发信号，但它返回 false 有两种原因：
     *   ESRCH(3) —— 进程确实不在
     *   EPERM(1) —— 进程在，只是属于别的用户，当前用户无权给它发信号
     * 只看返回值会把后者当成「已死」。现网就是这样出的事（2026-11-19）：一整套行情进程
     * 是 root 起的，deploy 跑 restart_market.sh 时 Workerman 的 stop 认为没在跑、单例守卫也把它们
     * 从注册表里剪掉了，于是又起了一整套，两套并行了一周。
     *
     * ext-posix 缺失时无法判定，返回 true：宁可多拒一次启动，也不能叠出第二套。
     *
     * @param int $pid 本机进程号；别的机器上的 pid 不能拿来问
     */
    public static function pidExists(int $pid): bool
    {
        if ($pid <= 0) return false;
        if (!function_exists('posix_kill')) return true;
        if (@posix_kill($pid, 0)) return true;
        // PHP 没有 POSIX_EPERM 常量，errno 1 即 EPERM
        return posix_get_last_error() === 1;
    }

    /**
     * 本机进程号属于哪个用户（用户名）。取不到返回空串。
     * 只用于拼提示文案，判定逻辑不要依赖它。
     */
    public static function pidOwner(int $pid): string
    {
        $uid = @fileowner("/proc/{$pid}");
        if (!is_int($uid)) return '';
        if (function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid($uid);
            if (is_array($pw) && isset($pw['name'])) return (string)$pw['name'];
        }
        return 'uid=' . $uid;
    }

    /** 本实例在注册表里的 field：`{hostname}#{pid}` */
    public static function instanceField(): string
    {
        return self::hostName() . '#' . getmypid();
    }

    /** 主机名。gethostname() 在极少数环境返回 false，退回一个固定串而不是让 field 变成 `#123` */
    public static function hostName(): string
    {
        $h = @gethostname();
        return is_string($h) && $h !== '' ? $h : 'unknown-host';
    }

    /**
     * 读出「此刻真的活着」的实例，顺手剪掉陈旧与已死的 field。
     *
     * 两道剪枝缺一不可：
     *   过期    —— 进程没了、心跳自然停了，超过 interval*3 就不算数
     *   本机已死 —— 优雅退出不会删自己的 field，会残留最多 interval*3 秒。
     *              restart 正好落在这个窗口里，不验 pid 就会把「刚停掉的自己」当成重复实例，
     *              把一次正常重启拒掉——那比重复进程更糟
     *
     * 只对**同主机**的 field 验 pid：posix_kill 验不了别的机器上的进程号，
     * 硬验会把另一台机器的正常实例误剪掉。
     *
     * @return array<int,array{host:string,pid:int,ts:int,self:bool}> 按 field 原样返回，不排序
     */
    public static function liveInstances(string $commandName, int $interval = self::DEFAULT_INTERVAL): array
    {
        $redis = redis_client();
        if (!$redis) return [];

        $key = self::KEY_INST_PREFIX . $commandName;
        try {
            $raw = $redis->hGetAll($key) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $host     = self::hostName();
        $selfPid  = getmypid();
        $expireBefore = (int)((microtime(true) - $interval * 3) * 1000);

        $live = [];
        $dead = [];
        foreach ($raw as $field => $ts) {
            $at = strrpos((string)$field, '#');
            if ($at === false) { $dead[] = $field; continue; }   // 不认识的格式，清掉

            $fHost = substr((string)$field, 0, $at);
            $fPid  = (int)substr((string)$field, $at + 1);

            if ((int)$ts < $expireBefore)                    { $dead[] = $field; continue; }
            // 只认「进程确实不在」才剪；属于别的用户的进程（EPERM）照样算活着，见 pidExists()
            if ($fHost === $host && !self::pidExists($fPid)) {
                $dead[] = $field; continue;
            }

            $live[] = ['host' => $fHost, 'pid' => $fPid, 'ts' => (int)$ts,
                       'self' => ($fHost === $host && $fPid === $selfPid)];
        }

        if ($dead) {
            try { $redis->hDel($key, ...$dead); } catch (\Throwable $e) { /* 剪枝失败不影响判定 */ }
        }
        return $live;
    }
}
