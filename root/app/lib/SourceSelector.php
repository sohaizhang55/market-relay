<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketSourceDecisionEnum as Decision;
use app\enum\MarketSourceHealthEnum as Health;

/**
 * 主备切换的决策规则 —— 纯计算，不碰库、不碰 Redis，输入什么就只按什么判
 *
 * 规则（一个交易对一次判一轮）：
 *   · 当前生效源断流 → 立即切到「按优先级第一个健康的源」；一个健康的都没有 → 保持不动并告警
 *   · 当前生效源不在启用的绑定里（被停用 / 删掉）→ 同上，按切走处理
 *   · 当前生效源休市 → 保持：休市没有帧是计划内的
 *   · 当前生效源健康，且有优先级更高的源连续健康满 recover_sec → 切回最高的那个
 *   · 已锁定的交易对一律保持（1 小时内切走 flap_max 次以上，见 shouldLock，等人工解锁）
 *
 * 切走快、切回慢：切走是为了价格不停，越快越好；切回是为了用回更好的源，确认稳定了再回，
 * 否则主源时好时坏时会来回切，而每切一次价格都会按两家报价差跳一下（不做价差补偿）。
 */
final class SourceSelector
{
    /**
     * @param int $recoverSec    优先级更高的源要连续健康多少秒才切回
     * @param int $flapWindowSec 切走次数的统计窗口（秒）
     * @param int $flapMax       窗口内切走达到这个次数就锁定
     */
    public function __construct(
        private int $recoverSec,
        private int $flapWindowSec,
        private int $flapMax,
    ) {
    }

    /**
     * 对一个交易对做本轮决定。
     *
     * @param list<string>          $bindings     启用中的绑定，已按优先级排好（第一个是主源）
     * @param string                $active       当前生效源（exchange_pair.source_key）
     * @param array<string,Health>  $health       source_key => 健康状态，缺的按 Health::FALLBACK
     * @param array<string,int>     $healthySince source_key => 连续健康的起点（秒），此刻不健康的不在里面
     * @param bool                  $locked       是否已锁定
     * @return array{0:Decision,1:?string,2:string} [决定, 目标源, 原因]
     */
    public function decide(array $bindings, string $active, array $health, array $healthySince, int $now, bool $locked): array
    {
        if ($locked) {
            return [Decision::KEEP, null, '已锁定，不自动切换'];
        }

        $state = fn(string $sk): Health => $health[$sk] ?? Health::FALLBACK;
        $idx   = array_search($active, $bindings, true);

        if ($idx === false) {
            $to = $this->firstOk($bindings, $state, null);
            return $to === null
                ? [Decision::ALL_DOWN, null, "当前源 {$active} 不在启用的绑定里，且没有健康的源可切"]
                : [Decision::FAILOVER, $to, "当前源 {$active} 不在启用的绑定里"];
        }

        $activeState = $state($active);

        if ($activeState === Health::CLOSED) {
            return [Decision::KEEP, null, "当前源 {$active} 休市"];
        }

        if ($activeState === Health::DOWN) {
            $to = $this->firstOk($bindings, $state, $active);
            return $to === null
                ? [Decision::ALL_DOWN, null, "当前源 {$active} 断流，且没有健康的备源"]
                : [Decision::FAILOVER, $to, "当前源 {$active} 断流"];
        }

        // 当前源健康：看优先级更高的源有没有稳定恢复
        for ($i = 0; $i < $idx; $i++) {
            $sk = $bindings[$i];
            if ($state($sk) !== Health::OK || !isset($healthySince[$sk])) continue;
            $stable = $now - $healthySince[$sk];
            if ($stable >= $this->recoverSec) {
                return [Decision::RECOVER, $sk, "优先级更高的 {$sk} 已连续健康 {$stable} 秒"];
            }
        }

        return [Decision::KEEP, null, ''];
    }

    /**
     * 切走记录里落在窗口内的次数是否已达到锁定线。
     *
     * @param list<int> $failovers 该交易对的切走时刻（秒），含刚刚这一次
     */
    public function shouldLock(array $failovers, int $now): bool
    {
        return count($this->recentFailovers($failovers, $now)) >= $this->flapMax;
    }

    /**
     * 只留窗口内的切走记录，调用方用它修剪自己保存的列表。
     *
     * @param list<int> $failovers
     * @return list<int>
     */
    public function recentFailovers(array $failovers, int $now): array
    {
        return array_values(array_filter($failovers, fn(int $t) => $now - $t < $this->flapWindowSec));
    }

    /** 按优先级第一个健康的源，可排除一个（当前断流的那个） */
    private function firstOk(array $bindings, callable $state, ?string $except): ?string
    {
        foreach ($bindings as $sk) {
            if ($sk !== $except && $state($sk) === Health::OK) return $sk;
        }
        return null;
    }
}
