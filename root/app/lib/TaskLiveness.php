<?php

declare(strict_types=1);

namespace app\lib;

/**
 * 进程内的「任务级存活证据」登记处。
 *
 * 为什么进程心跳不够：Workerman **吞掉定时器回调里的异常并继续调度**
 * （Timer::tick 与 Events\Select::safeCall 都是 catch 之后 echo）。
 * 于是下面这种状态完全可能，而且从进程视角一点看不出来：
 *
 *   撮合进程 PID 在、事件循环在转、心跳每 5 秒照写 —— 而撮合那个 Timer 早就不跑了。
 *
 * `CommandHeartBeatService` 证明的是"事件循环还活着"，
 * 这个类补的是"**那件事**还在做"。两者是不同的判据，缺一不可。
 *
 * 用法（不改回调体，只包住闭包）：
 *
 *     Timer::add(0.01, TaskLiveness::wrap('match_tick', function () { ... }));
 *
 * 热路径只动内存（三个整数），不碰 Redis —— 观测手段本身不能成为阻塞源。
 * 快照由 CommandHeartBeatService 的 5 秒心跳定时器顺手带出去。
 */
class TaskLiveness
{
    /** @var array<string,array{last:int,runs:int,ms:float}> 桶名 => 最后一次执行时刻(ms) / 次数 / 本次耗时 */
    private static array $stat = [];

    /** @var array<string,int> 桶名 => 累计抛异常次数（进程内，随进程重启归零） */
    private static array $fails = [];

    /** @var array<string,int> 桶名 => 上次写错误日志的时刻，用于限流 */
    private static array $lastLog = [];

    /** 同一个桶最多多久写一条错误日志。任务常是 10ms 一轮，出问题就是每轮都出 */
    private const LOG_INTERVAL = 60;

    /**
     * 包住一个 Timer 回调。
     *
     * 刻意返回闭包而不是提供 `run()` 让调用方自己包一层 ——
     * 后者要求改动回调体内部，而"埋点污染业务代码"正是要避免的。
     */
    public static function wrap(string $bucket, \Closure $fn): \Closure
    {
        return function (...$args) use ($bucket, $fn) {
            return self::guard($bucket, static fn() => $fn(...$args));
        };
    }

    /**
     * 跑一次任务：记存活证据 + 兜住异常 + Redis 断线自愈。
     *
     * ## 为什么要兜住异常
     *
     * 不兜的话异常会冒到 Workerman，而它 **catch 之后 echo 就继续调度**
     * （Timer::tick 与 Events\Select::safeCall）。daemon 模式下 stdout 是丢弃的，
     * 于是「这个任务每一轮都在抛」和「一切正常」在外部**长得一模一样**。
     * 这里兜住不改变"继续调度"这个行为，只是让它留下痕迹。
     *
     * ## 为什么要在这里重连 Redis
     *
     * phpredis 的两种断线结果完全不同（本机 6.3.0 实测）：连接被踢掉时下一次调用
     * 自动重连、连 SELECT 的库都保留；而 **Redis 真的宕过一段时间**之后会进入
     * "server went away"，**服务恢复了也永不自愈**。而 ThinkPHP 的 cache 驱动
     * 只在 `if (!$this->handler)` 时建连接，于是 redis_client() 会一直把那个
     * 死句柄发给所有人 —— Redis 重启一次，撮合/结算/落库/分发全部瘫痪到手工重启为止，
     * 而进程心跳一切正常。
     *
     * 常驻进程的每个 Timer 回调都经过这里，所以这是**唯一**需要写这段自愈的地方。
     *
     * 日志按桶限流：任务往往是 10ms 一轮，出问题就是每轮都出，
     * 而 business_log 是同步 fsync —— 逐条写会把故障放大成第二场故障。
     */
    /**
     * @param bool $touch 是否在这里记存活证据。
     *   调用方内部**已经**记过时必须传 false —— EventLoopBusyTrait::timed() 就是这种，
     *   它自己在 finally 里 touch 一次。两边都记会让 runs 翻倍，
     *   而 runs 是判活侧推算"平均多久一轮"的分母：翻倍之后告警里那句
     *   「平均 X 秒一轮」会差一倍，正好在排障时误导人。（这个 bug 就这么出过一次。）
     */
    public static function guard(string $bucket, \Closure $fn, bool $touch = true)
    {
        $t0 = microtime(true);
        try {
            return $fn();
        } catch (\Throwable $e) {
            self::$fails[$bucket] = (self::$fails[$bucket] ?? 0) + 1;

            // Redis 句柄可能已经永久损坏，重建一条，下一轮就能恢复。
            // 判据放宽到"消息里带 Redis 字样"：不同 phpredis 版本抛的类不完全一样，
            // 认死一个类名会在换版本时静默失效
            $isRedis = $e instanceof \RedisException
                || stripos($e->getMessage(), 'redis') !== false
                || stripos($e->getMessage(), 'went away') !== false;
            if ($isRedis && function_exists('redis_reconnect')) {
                redis_reconnect();
            }

            $now = time();
            if (($now - (self::$lastLog[$bucket] ?? 0)) >= self::LOG_INTERVAL) {
                self::$lastLog[$bucket] = $now;
                // ⚠️ 写日志本身必须再包一层。business_log 是同步 fsync，
                // 磁盘满 / 日志目录不可写时它会抛 —— 而这里已经在**异常处理路径**上，
                // 再抛一次就直接冒到 Workerman，这层兜底整个白做。
                // 兜底代码自己不能成为新的故障点。
                try {
                business_log('task_error', sprintf(
                    '[%s] 任务回调抛异常（已累计 %d 次）：%s @ %s:%d%s'
                    . ' —— Workerman 会吞掉它继续调度，所以进程看起来完全正常',
                    $bucket, self::$fails[$bucket], $e->getMessage(),
                    basename($e->getFile()), $e->getLine(),
                    $isRedis ? '；已重建 Redis 连接，下一轮应恢复' : ''
                ), 'error');
                } catch (\Throwable $ignored) {
                }
            }
            return null;
        } finally {
            // finally：抛异常的那一次**也要**记一笔存活证据。
            // 只在成功路径记的话，一个每次都抛的任务看起来会像"从没跑过"，
            // 而它其实在跑、只是每次都失败 —— 两种情况的处置方向完全相反
            if ($touch) self::touch($bucket, (microtime(true) - $t0) * 1000);
        }
    }

    public static function touch(string $bucket, float $ms): void
    {
        $p = self::$stat[$bucket] ?? ['last' => 0, 'runs' => 0, 'ms' => 0.0];
        self::$stat[$bucket] = [
            'last' => (int)(microtime(true) * 1000),
            'runs' => $p['runs'] + 1,
            'ms'   => round($ms, 1),
        ];
    }

    /**
     * @return array<string,array{last:int,runs:int,ms:float,fails?:int}>
     *   fails 只在非 0 时出现 —— 判活那一侧看到它就知道"任务在跑，但一直失败"，
     *   这与"任务停了"是两种故障，处置方向不同
     */
    public static function snapshot(): array
    {
        $out = self::$stat;
        foreach (self::$fails as $bucket => $n) {
            if ($n > 0 && isset($out[$bucket])) $out[$bucket]['fails'] = $n;
        }
        return $out;
    }
}
