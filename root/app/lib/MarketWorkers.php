<?php

declare(strict_types=1);

namespace app\lib;

use app\command\MarketCollectorAbstract;
use app\command\MarketDispatcher;
use app\command\MarketFunding;
use app\command\MarketKlineBackfill;
use app\command\MarketPriceFallback;
use app\command\MarketRestWorkerAbstract;
use app\command\MarketSourceSelect;
use app\enum\MarketDataTypeEnum;
use app\enum\MarketExchangeEnum;
use think\facade\Config;
use think\facade\Db;

/**
 * 行情常驻进程清单 —— 心跳健康检查（CommandHeartbeatCheckExpire）与部署核对（restart_market.sh --verify）共用一份
 *
 * market-relay 只有一条流程：采集 → 更新 pair → 分发，所以清单只有一份，不分角色。
 *
 * 为什么需要「应常驻」清单：心跳检查原来只扫「注册表里有、但心跳停了」的指令 ——
 * 一个**从来没启动过**的进程压根不在注册表里，于是永远不会被告警。market:price_fallback、
 * market:kline_backfill 与 market:funding 正是这种：忘了启动没有任何即时告警，
 * 而后果是 WS 静默时价格没有兜底、K 线缺口没人补、资金费率彻底断供。
 *
 * 采集器、补数器、费率进程按 market_data 现算：它们的 worker name 取决于配了哪几个交易所×类型，
 * 写死一份清单必然漂移，而漂移的后果是恒定误报或恒定漏报。
 */
final class MarketWorkers
{
    /**
     * 已改名 / 下线的进程名。注册表从不 sRem，跑过它们的机器上这些名字会永远留着；
     * 不列在这里，心跳检查会把它们当成「持续中断」恒定误报。
     * 要从这里删掉一个名字，先写迁移把它从 Redis 注册表里 sRem 掉（例：20261022100000_purge_alltick_redis）。
     */
    private const RETIRED = [
        'market_relay_MarketDispatchServer',   // 2026-10-19 按 pair 类型拆成 _Spot / _Swap 两个进程
        'market_relay_MarketSubscriber',       // 2026-10-22 去掉三种 mode，没有订阅方角色了
        // 2026-10-26 拆成 market_relay_MarketPriceFallback_* 与 market_relay_MarketKlineBackfill_*
        'market_relay_MarketBackfill_huobi',
        'market_relay_MarketBackfill_binance',
        'market_relay_MarketBackfill_bingx',
    ];

    /** Go 影子进程的 worker name 后缀。与 market-relay/go 里的 shadowSuffix 一致，改动要两边同步 */
    public const GO_SHADOW_SUFFIX = '_Go';

    /**
     * Go 分发机并行试跑时用的 worker name（PHP 名 + GO_SHADOW_SUFFIX），避免和 PHP 进程抢同一个心跳键。
     * 试跑进程随时会停，停了不算故障，所以列进 noHeartbeatOk()。
     *
     * @return list<string>
     */
    private static function goShadowNames(): array
    {
        return array_map(
            static fn(MarketDataTypeEnum $t) => MarketDispatcher::workerName($t->value) . self::GO_SHADOW_SUFFIX,
            MarketDataTypeEnum::cases()
        );
    }

    /**
     * 必须常驻的进程（worker name => 用途）。
     *
     * @return array<string,string>
     */
    public static function expected(): array
    {
        $out = ['market_relay_MarketDbFlusher' => '行情不落库']
            + self::collectors() + self::restWorkers() + self::funding() + self::dispatch();

        // 选源没启用时拒绝启动，不该算缺失
        if (MarketSourceSelect::enabled()) {
            $out[MarketSourceSelect::WORKER_NAME] = '主源断流不会切备源';
        }
        return $out;
    }

    /**
     * 「没有心跳是正确状态」的进程 —— 心跳检查据此把它们从中断告警里摘掉。
     *
     * 注册表只 sAdd 从不 sRem：已下线的、没启用的选源进程、交易对被删掉之后不再需要的采集器，
     * 名字都会永远留在里面。按「可能出现的名字全集 − 应常驻清单」算，不靠逐个列举。
     *
     * @return list<string>
     */
    public static function noHeartbeatOk(): array
    {
        $possible = array_merge(self::RETIRED, [MarketSourceSelect::WORKER_NAME], self::allCollectorNames(), self::goShadowNames());
        return array_values(array_diff($possible, array_keys(self::expected())));
    }

    /**
     * 该跑哪几个采集器。判据与采集器自己的启动条件一致（MarketCollectorAbstract::execute 里
     * 该 exchange×type 查不到源就不建 Worker）；debug_source_keys 白名单也跟着过滤。
     *
     * @return array<string,string> worker 名 => 说明
     */
    public static function collectors(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        try {
            $query = Db::name('market_data')->field('exchange,type')->group('exchange,type');
            $debug = array_filter((array)Config::get('market.debug_source_keys', []));
            if ($debug) $query->whereIn('source_key', $debug);
            $rows = $query->select()->toArray();
        } catch (\Throwable $e) {
            // 库不可用时返回空清单而不是抛：判活检查不该被它的一个数据源拖垮。但要留痕
            business_log('heartbeat_health', '[COLLECTOR_LIST] 读取 market_data 失败：' . $e->getMessage(), 'error');
            return $cache = [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[MarketCollectorAbstract::workerName((string)$r['exchange'], (int)$r['type'])] = sprintf(
                '%s %s行情停更', $r['exchange'], (int)$r['type'] === MarketDataTypeEnum::SPOT->value ? '现货' : '合约'
            );
        }
        return $cache = $out;
    }

    /**
     * 该跑哪几个价格兜底 / K 线补数进程（每个有源的交易所各一个），判据与 MarketRestWorkerAbstract::hasSources 一致。
     *
     * @return array<string,string>
     */
    public static function restWorkers(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        try {
            $out = [];
            foreach (Db::name('market_data')->group('exchange')->column('exchange') as $exchange) {
                if (!MarketRestWorkerAbstract::hasSources((string)$exchange)) continue;
                $out[MarketPriceFallback::workerName((string)$exchange)] = "{$exchange} WS 静默时价格无兜底";
                $out[MarketKlineBackfill::workerName((string)$exchange)] = "{$exchange} K线缺口没人补";
            }
        } catch (\Throwable $e) {
            business_log('heartbeat_health', '[BACKFILL_LIST] 读取 market_data 失败：' . $e->getMessage(), 'error');
            return $cache = [];
        }
        return $cache = $out;
    }

    /**
     * 该跑哪几个费率进程（每个有合约源的交易所一个），判据与 MarketFunding::hasSwapSources 一致。
     *
     * @return array<string,string>
     */
    public static function funding(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        try {
            $out = [];
            foreach (Db::name('market_data')->group('exchange')->column('exchange') as $exchange) {
                if (!MarketFunding::hasSwapSources((string)$exchange)) continue;
                $out[MarketFunding::workerName((string)$exchange)] = "{$exchange} 资金费率停更";
            }
        } catch (\Throwable $e) {
            business_log('heartbeat_health', '[FUNDING_LIST] 读取 market_data 失败：' . $e->getMessage(), 'error');
            return $cache = [];
        }
        return $cache = $out;
    }

    /**
     * 分发进程：现货、合约各一个（market:dispatch -t 1 / -t 2，各自端口），两个都要跑：
     * 少一个，订阅方就整类（全部现货或全部合约）收不到行情。
     *
     * @return array<string,string>
     */
    public static function dispatch(): array
    {
        $out = [];
        foreach (MarketDataTypeEnum::cases() as $type) {
            $label = $type === MarketDataTypeEnum::SPOT ? '现货' : '合约';
            $out[MarketDispatcher::workerName($type->value)] = "订阅方收不到{$label}行情";
        }
        return $out;
    }

    /**
     * 采集器名的全集（交易所枚举 × 现货 / 合约），只用来算 noHeartbeatOk()。
     *
     * @return list<string>
     */
    private static function allCollectorNames(): array
    {
        $out = [];
        foreach (MarketExchangeEnum::cases() as $ex) {
            foreach (MarketDataTypeEnum::cases() as $type) {
                $out[] = MarketCollectorAbstract::workerName($ex->value, $type->value);
            }
        }
        return $out;
    }
}
