<?php

declare(strict_types=1);

namespace app\lib;

/**
 * 定时器单轮的**墙钟预算**。
 *
 * ── 为什么需要它 ──────────────────────────────────────────────────────────
 * 采集器与订阅方都是 Workerman 单线程事件循环：定时器回调里每一次同步 HTTP，
 * 都是整整这么久**不读 socket**。而回补类逻辑天然是"循环里连着发若干次外呼"，
 * 于是"每次超时 3 秒"× "每轮配额 5 条" = 单轮最长 15 秒不读 socket ——
 * 远超分发机 5 秒的 LAGGING 看门狗，触发 destroy() → 断开 → 全量重放 →
 * 批次更大 → 更卡。这就是"重启/网络抖动后部分模块卡住"的主要机制。
 *
 * 条数配额管不住这件事：它约束的是**次数**，而阻塞时长取决于每次有多慢，
 * 恰恰在对端病了的时候两者差最远。所以必须另有一条按时间的硬上界。
 *
 * ── 两件事一起做才有用 ────────────────────────────────────────────────────
 *  1. `expired()`：**每次外呼之前**判一次（不是循环外判一次）—— 否则预算只在
 *     进入循环时生效，进去之后想跑多久跑多久。
 *  2. `timeout()`：把单次外呼的超时**压进剩余预算**。只有 1 没有 2 的话，
 *     最后一次外呼仍可以独自把预算超掉一个完整的 timeout。
 *
 * 两者合起来，单轮阻塞的上界 ≈ 预算 + 1 秒（timeout 至少给 1 秒，见 timeout()）。
 *
 * 刻意不做的事：不重入、不异步、不跨轮记账。它只是"这一轮还剩多少时间"，
 * 越简单越不会有人绕开它。
 */
final class TickBudget
{
    private float $startedAt;
    private float $deadline;

    private function __construct(float $seconds)
    {
        $this->startedAt = microtime(true);
        // 下界 0.1s：配置写成 0 或负数时应当退化成"只做一次"，而不是"一次都不做"——
        // 后者会让回补功能被一个手滑的配置静默关停
        $this->deadline = $this->startedAt + max(0.1, $seconds);
    }

    public static function ofSeconds(float $seconds): self
    {
        return new self($seconds);
    }

    /** 剩余预算(秒)，永不为负 */
    public function remaining(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }

    /** 预算是否已用尽。**每次外呼前**都应该判一次 */
    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }

    /**
     * 把一次外呼的超时压进剩余预算。
     *
     * 向**上**取整而不是向下：curl 的 CURLOPT_TIMEOUT 是整秒，向下取整会让一个
     * 刚建好的 3 秒预算只给得出 2 秒（剩余 2.999 秒 → floor 2），凭空缩水一秒。
     * 至少给 1 秒：给 0 在 curl 语义里是"不限时"，那正好是本类要防的事情。
     *
     * 代价是单轮最多超出预算约 1 秒，可接受 —— 上界依然是常数，而不是
     * "配额 × 超时"那种会随缺口数量放大的量。
     *
     * @param int $preferred 正常情况下想用的超时(秒)
     */
    public function timeout(int $preferred): int
    {
        return max(1, min($preferred, (int)ceil($this->remaining())));
    }

    /** 本轮已消耗的毫秒数，用于日志/指标 */
    public function elapsedMs(): int
    {
        return (int)round((microtime(true) - $this->startedAt) * 1000);
    }
}
