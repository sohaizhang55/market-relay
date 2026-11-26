<?php

declare(strict_types=1);

namespace app\lib;

/**
 * 事件循环占用率统计。
 *
 * Workerman 是单线程事件循环：socket 只在循环回到 select/epoll 时才继续被读取。
 * 所以「进程忙不忙」这个问题的正确口径是**循环被同步工作占住的比例**，
 * 而不是某一个函数跑得快不快。
 *
 * ⚠️ 这里踩过一次坑，值得写下来：订阅方最早的 busy% 是
 * 「窗口内所有 processFrame 耗时之和 ÷ 窗口长度」，只覆盖落库函数体，
 * 不含解压/反序列化、不含 Workerman 自己的 fread 与解帧、**不含任何定时器**、
 * 也不含早退帧。于是它可以显示个位数而事件循环其实已经饱和 ——
 * 「监控说没事、实际已经卡死」。本 trait 就是为了让这个口径在采集端与订阅端统一。
 *
 * 分桶而不是只记总数：饱和时要能立刻看出是谁占的，否则只知道慢、不知道慢在哪。
 */
trait EventLoopBusyTrait
{
    /** @var array<string,float> 桶名 => 累计占用(毫秒)，由 loopBusyReset() 按统计窗口清零 */
    private static array $loopBusy = [];

    /**
     * @var array<string,array{last:int,runs:int,ms:float}> 桶名 => 任务存活证据
     *      last 最后一次执行结束的毫秒时间戳 / runs 累计执行次数 / ms 上一次耗时
     *
     * ⚠️ **不**随统计窗口清零：它回答的是「这个任务最后一次跑是什么时候」，
     * 一个 30 秒周期的任务在 10 秒窗口里本来就该有两个窗口是 0 次，清零会让它看起来死了。
     *
     * 为什么挂在 timed() 上而不是各处新增埋点：timed() 已经在包每一个 Timer 回调、
     * 而且已经带了桶名 —— 顺手就有了「谁、什么时候、跑了多久」。零新增调用点，
     * 也不会出现"加了新 Timer 却忘了上报"。
     */
    private static array $taskStat = [];

    /**
     * 把一段**在事件循环里同步执行**的工作计入占用统计。
     * 用 finally 记账：被包住的代码抛异常时，这段时间照样占了循环，不能不算。
     */
    protected function timed(string $bucket, callable $fn)
    {
        $t0 = microtime(true);
        try {
            return $fn();
        } finally {
            $ms = (microtime(true) - $t0) * 1000;
            self::$loopBusy[$bucket] = (self::$loopBusy[$bucket] ?? 0) + $ms;

            // 任务存活证据。同样用 finally：抛异常的那一次也**确实跑过**，
            // 记成"没跑"会把"任务在跑但一直失败"误报成"任务没在跑"，
            // 两者的处置方向完全不同
            $t = self::$taskStat[$bucket] ?? ['last' => 0, 'runs' => 0, 'ms' => 0.0];
            self::$taskStat[$bucket] = [
                'last' => (int)(microtime(true) * 1000),
                'runs' => $t['runs'] + 1,
                'ms'   => round($ms, 1),
            ];
            // 同一笔也喂给进程级登记处：心跳定时器从那里带出去，
            // 于是**所有**常驻进程（不只行情那几个）都能被按任务判活
            \app\lib\TaskLiveness::touch($bucket, $ms);
        }
    }

    /**
     * 把一个事件回调包成"自带计时"的回调，在**赋值处**包装。
     *
     * 为什么要有它：`timed('msg', function () use (...) { ... })` 这种在回调体**内部**
     * 再套一层闭包的写法，被三个采集器的 onMessage 逐字复制了三遍，
     * 而每一遍都得把外层那串 `use (...)` 原样再抄一次（含 `&$lastMsgTime` 这种引用捕获）。
     * 抄漏一个变量不会报错，只会让那个变量在回调里变成 null —— 是最难查的一类错。
     *
     * 包在赋值处则一个 use 列表都不用重复：
     *     $con->onMessage = $this->timedHandler('msg', function ($con, $raw) use (...) { ... });
     *
     * @param string $bucket 计入哪个占用分桶
     * @param \Closure $fn 原回调，参数原样透传
     */
    protected function timedHandler(string $bucket, \Closure $fn): \Closure
    {
        return function (...$args) use ($bucket, $fn) {
            // 经 TaskLiveness::guard()：Timer 回调抛出的异常在这里被兜住并留痕，
            // Redis 断线还会顺手重建连接。不兜的话它冒到 Workerman，
            // 而那边 catch 之后 echo 就继续调度 —— daemon 模式 stdout 是丢弃的，
            // 于是"每轮都在抛"与"一切正常"从外部长得一模一样。
            // 注意只有**Timer 回调**这一层这么做；直接调 timed() 的地方保持原样往外抛
            // touch:false —— timed() 自己在 finally 里已经记过一次，
            // 两边都记会让 runs 翻倍（判活侧用它当分母推算"平均多久一轮"）
            return \app\lib\TaskLiveness::guard(
                $bucket,
                fn() => $this->timed($bucket, static fn() => $fn(...$args)),
                false
            );
        };
    }

    /**
     * @param float $window 统计窗口长度(秒)
     * @return array{pct:float,detail:string} pct=总占用率；detail=按耗时降序的分桶明细，只列 ≥1% 的
     */
    protected static function loopBusySnapshot(float $window): array
    {
        $window = max(0.001, $window);
        $pct = array_sum(self::$loopBusy) / 1000 / $window * 100;

        $buckets = self::$loopBusy;
        arsort($buckets);
        $parts = [];
        foreach ($buckets as $name => $ms) {
            $p = $ms / 1000 / $window * 100;
            if ($p < 1.0) continue;
            $parts[] = sprintf('%s=%.1f%%', $name, $p);
        }

        return ['pct' => $pct, 'detail' => $parts ? '[' . implode(' ', $parts) . ']' : ''];
    }

    protected static function loopBusyReset(): void
    {
        self::$loopBusy = [];
    }

    /**
     * 任务存活快照，随各进程既有的 putWorker() 一起上报。
     *
     * 监控页据此回答「这个任务最后一次跑是什么时候」—— 与进程心跳互补：
     * 心跳只证明进程活着，证明不了它挂着的定时器还在转。
     * Workerman 会吞掉定时器回调里的异常并继续调度，所以"进程活着但某个任务
     * 早就不跑了"是完全可能的，而且从进程视角完全看不出来。
     *
     * @return array<string,array{last:int,runs:int,ms:float}>
     */
    protected static function taskSnapshot(): array
    {
        return self::$taskStat;
    }
}
