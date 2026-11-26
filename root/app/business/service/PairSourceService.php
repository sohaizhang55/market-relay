<?php

declare(strict_types=1);

namespace app\business\service;

use app\enum\CommonStatusEnum;
use think\facade\Db;

/**
 * 交易对数据源：绑定（配置）与切源（状态）
 *
 * 负责  读 exchange_pair_source 的绑定、把 pair 的生效源切到另一个已绑定的源
 * 不负责  判断该不该切（选源进程的事）、采集与落库（MarketService 的事）
 *
 * 两样东西别混：
 *   exchange_pair_source       配置：这个 pair 可以用哪些源、按什么顺序。人在后台改
 *   exchange_pair.source_key   状态：此刻在用哪个源。只由本类 switchTo() 改
 *
 * 切源之所以只改 source_key 一列就够：采集热路径本来就是「按 source_key 反查用我的 pair」
 * （MarketService::getPairsBySourceKey），后台、inner_api、过期告警、补数也都读这一列，
 * 改掉它全链路自动跟着走。K 线不用特殊处理 —— pair K 线的写入本来就是合并语义
 * （open 只在首次写入时定、high / low 取极值、close 覆盖），切换那一根自动保留旧源的开盘与高低点。
 */
class PairSourceService
{
    /**
     * 某个 pair 的启用中的绑定，按优先级排好（第一个是主源）。
     *
     * @return array<int,array{source_key:string,priority:int}>
     */
    public static function bindings(int $pairId): array
    {
        return Db::name('exchange_pair_source')
            ->where('pair_id', $pairId)
            ->where('status', CommonStatusEnum::ON->value)
            ->order('priority asc, id asc')
            ->limit(100)
            ->field('source_key, priority')
            ->select()
            ->toArray();
    }

    /**
     * 把 pair 的生效源切到 $toSourceKey。目标必须是该 pair 启用中的绑定；已经是它则什么都不做。
     *
     * 生效延迟：本进程立即生效；其余进程的「源 → pair」缓存最多 3 秒（MarketService::PAIRS_LOCAL_TTL）。
     * 按 pair 判开休市的映射缓存 60 秒（MarketSessionService::SOURCE_MAP_TTL），主备两源作息不同时，
     * 切换后最多 60 秒内仍按旧源的日历判。
     *
     * @param string $reason 写进切换日志，说明为什么切
     * @return bool false = 目标不是该 pair 的启用绑定，或 pair 不存在
     */
    public static function switchTo(int $pairId, string $toSourceKey, string $reason): bool
    {
        $pair = Db::name('exchange_pair')->where('id', $pairId)->field('id, symbol_code, type, source_key')->find();
        if (!$pair) return false;

        $bound = in_array($toSourceKey, array_column(self::bindings($pairId), 'source_key'), true);
        if (!$bound) return false;

        $from = (string)($pair['source_key'] ?? '');
        if ($from === $toSourceKey) return true;

        Db::name('exchange_pair')->where('id', $pairId)->update(['source_key' => $toSourceKey, 'update_ts' => time()]);
        MarketService::clearPairsCache();

        business_log('market_source_switch', sprintf(
            '[%s_%d] %s -> %s：%s', $pair['symbol_code'], (int)$pair['type'], $from ?: '-', $toSourceKey, $reason
        ), 'warning');

        return true;
    }
}
