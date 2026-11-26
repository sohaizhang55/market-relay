<?php

declare(strict_types=1);

namespace app\command;

use think\console\Output;
use Workerman\Worker;
use think\facade\Db;
use think\facade\Config;
use app\lib\MarketHelper;
use app\enum\MarketExchangeEnum;
use app\enum\MarketDataTypeEnum;
use app\business\service\MarketService;

/**
 * 价格兜底进程，每个交易所一个：WS 静默时用交易所 REST 续上成交价 / 标记价格
 *
 * 过期判据与价格可用性判定、过期告警同一个数（market.stale_seconds），见 restFallbackTick()。
 * K 线补数不在这里，见 MarketKlineBackfill；资金费率见 MarketFunding。
 *
 * 命令  php think market:price_fallback
 * 触发  deploy/restart_market.sh
 * 单例  按交易所：pid 文件 runtime/market-relay_MarketPriceFallback_{exchange}.pid（见 SingletonGuardTrait）
 * 依赖  配置 market.rest_fallback · market.stale_seconds
 *
 * 用法:
 *   php think market:price_fallback start -e huobi -d     # 后台常驻，只管 huobi 的源
 *   php think market:price_fallback status -e bingx
 *   php think market:price_fallback stop -e binance
 */
class MarketPriceFallback extends MarketRestWorkerAbstract
{
    /** 价格兜底扫描周期(秒)。是「多久看一眼」，不是「多旧算过期」（那是 market.stale_seconds） */
    private const REST_FALLBACK_INTERVAL = 10;

    /** 扫描游标：存上一轮停下的 source_key（不存下标，源集合增删后仍能定位） */
    private string $restFallbackCursor = '';

    public static function workerName(string $exchange): string
    {
        return 'market_relay_MarketPriceFallback_' . $exchange;
    }

    protected function configure()
    {
        $this->configureWorker('market:price_fallback', 'Market price fallback worker: refresh stale last/mark price via exchange REST');
    }

    protected function logTag(): string
    {
        return 'Price-Fallback';
    }

    protected function logChannel(): string
    {
        return 'market_rest_fallback';
    }

    protected function registerTasks(Output $output): void
    {
        $this->registerTask('rest_fallback', self::REST_FALLBACK_INTERVAL, fn() => $this->restFallbackTick($output));
    }

    /** 单轮墙钟预算：rest_fallback.tick_budget_sec，没配时沿用拆分前共用的 kline_backfill.tick_budget_sec */
    protected function budgetSeconds(): float
    {
        return (float)($this->cfg('rest_fallback')['tick_budget_sec']
            ?? $this->cfg('kline_backfill')['tick_budget_sec'] ?? 3);
    }

    protected function bannerDetail(): string
    {
        return 'interval=' . self::REST_FALLBACK_INTERVAL . 's';
    }

    /** 每轮最多修几个过期源的默认值，配置见 market.rest_fallback.max_fixes_per_tick */
    private const REST_FALLBACK_MAX_FIXES = 4;

    /**
     * 修复本交易所过期的成交价 / 标记价格，每轮最多 max_fixes_per_tick 个，带游标轮转
     *
     * 阈值 = max(1, market.stale_seconds - rest_fallback.lead_seconds)，与价格可用性判定、过期告警同一个数。
     * ⚠️ 过期判定读 Redis 热层（readStaleTs），不能改回读 MySQL：刷盘一慢就会误判全部过期。
     */
    private function restFallbackTick(Output $output): void
    {
        $fallbackCfg = $this->cfg('rest_fallback');
        if (!(bool)($fallbackCfg['enable'] ?? true)) {
            if (!Worker::$daemonize) {
                $output->writeln('<comment>[Price-Fallback]</comment> 已由 market.rest_fallback.enable 关闭');
            }
            return;
        }

        $staleSeconds = (int)Config::get('market.stale_seconds', 300);
        $leadSeconds  = (int)($fallbackCfg['lead_seconds'] ?? 0);
        $maxAgeSeconds = max(1, $staleSeconds - $leadSeconds);
        $staleBeforeMs = (int)(microtime(true) * 1000) - $maxAgeSeconds * 1000;
        $maxFixes = max(1, (int)($fallbackCfg['max_fixes_per_tick'] ?? self::REST_FALLBACK_MAX_FIXES));

        $sources = $this->loadSources();
        $fixed = 0;
        $stale = 0;
        $checked = 0;
        // 收工原因：'' = 整轮扫完，'quota' = 撞条数配额，'budget' = 撞墙钟预算
        $stopReason = '';

        // 循环与推进游标必须看同一份顺序
        $ordered = $this->rotateByCursor($sources, $this->restFallbackCursor);
        foreach ($ordered as $src) {
            $checked++;
            $sourceKey = (string)$src['source_key'];
            $row = $this->readStaleTs($sourceKey, (int)$src['type']);
            if (!$row) continue;

            $lastPriceStale = (int)$row['update_ts'] < $staleBeforeMs;
            $markPriceStale = (int)$src['type'] === MarketDataTypeEnum::SWAP->value
                && (int)$row['mark_ts'] < $staleBeforeMs;

            if (!$lastPriceStale && !$markPriceStale) continue;
            $stale++;

            // 判在外呼之前；break 而不是 return，下面还要推进游标
            if ($this->budgetExpired()) { $stopReason = 'budget'; break; }

            if ($lastPriceStale) {
                $this->restFallbackSyncLastPrice($src, (int)$row['update_ts'], $output);
                $fixed++;
            }
            if ($markPriceStale) {
                $this->restFallbackSyncMarkPrice($src, (int)$row['mark_ts'], $output);
                $fixed++;
            }

            if ($fixed >= $maxFixes) {
                $stopReason = 'quota';
                break;
            }
        }

        if ($stopReason !== '') {
            // 提前收工：记下一个该扫的 source_key
            $this->restFallbackCursor = (string)($ordered[$checked]['source_key']
                ?? ($ordered[0]['source_key'] ?? ''));
        } else {
            $this->restFallbackCursor = ''; // 整轮扫完没提前收工，下轮从头开始
        }

        if (!Worker::$daemonize) {
            // stale 只是本轮扫到的过期源数，不是全库过期总数
            $output->writeln(sprintf(
                '<comment>[Price-Fallback]</comment> sources=%d checked=%d stale=%d fixed=%d (max=%d) cursor=%s%s',
                count($sources), $checked, $stale, $fixed, $maxFixes,
                $this->restFallbackCursor,
                match ($stopReason) {
                    'quota'  => ' 配额用尽(rest_fallback.max_fixes_per_tick)，剩余留待下一轮',
                    'budget' => ' 墙钟预算用尽(tick_budget_sec)，配额未跑满，剩余留待下一轮',
                    default  => '',
                }
            ));
        }
    }

    /**
     * 取新鲜度判据用的 update_ts / mark_ts，先读热层再回落 MySQL（现货不取 mark_ts，热层没有这个字段）
     *
     * @return array{update_ts:int|string, mark_ts:int|string}|null
     */
    private function readStaleTs(string $sourceKey, int $type): ?array
    {
        $fields = $type === MarketDataTypeEnum::SWAP->value
            ? ['update_ts', 'mark_ts']
            : ['update_ts'];

        $hot = MarketService::getHotMarketData($sourceKey, $fields);
        if ($hot !== null) {
            return ['update_ts' => $hot['update_ts'], 'mark_ts' => $hot['mark_ts'] ?? 0];
        }

        return Db::name('market_data')->where('source_key', $sourceKey)
            ->field('update_ts, mark_ts')->find();
    }

    /**
     * REST 拉一次成交价落库，并随 pair 层更新分发出去
     *
     * @param array $src 行情源行
     * @param int $tsBefore 发起 REST 前读到的 update_ts，用于写入前的新鲜度守卫
     * @param Output $output
     */
    private function restFallbackSyncLastPrice(array $src, int $tsBefore, Output $output): void
    {
        $sourceKey = (string)$src['source_key'];
        $client = $this->restClient((string)$src['exchange']);
        if (!$client) {
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Price-Fallback]</comment> {$sourceKey} 跳过: exchange={$src['exchange']} 无 REST 实现");
            }
            return;
        }

        $price = $client->fetchRestLastPrice((string)$src['symbol'], (int)$src['type']);
        if ($price === null) {
            business_log('market_rest_fallback', "[LastPrice] {$sourceKey} REST fetch failed", 'error');
            if (!Worker::$daemonize) {
                $output->writeln("<error>[Price-Fallback]</error> {$sourceKey} LastPrice REST 拉取失败");
            }
            return;
        }

        // ⚠️ 新鲜度守卫：REST 期间 WS 已恢复就放弃写入，否则旧价覆盖新价、价格回跳。
        //    必须与 $tsBefore 走同一个读取口 readStaleTs()，不能拿两个时钟比大小
        $fresh = $this->readStaleTs($sourceKey, (int)$src['type']);
        $now   = $fresh['update_ts'] ?? null;
        if ($now !== null && (int)$now > $tsBefore) {
            business_log('market_rest_fallback', "[LastPrice] {$sourceKey} skipped: WS recovered during REST (ts {$tsBefore} -> {$now})");
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Price-Fallback]</comment> {$sourceKey} LastPrice 放弃写入: REST 期间 WS 已恢复");
            }
            return;
        }

        $exchange = MarketExchangeEnum::from((string)$src['exchange']);
        $ts       = (int)(microtime(true) * 1000);
        $tick     = $this->restFallbackTick24h($sourceKey, (string)$price);

        // updatePrice() 走到 pair 层时会自己写 pair 快照分发出去（分发协议 v3），这里不再单独发布
        MarketService::updatePrice($exchange, (string)$src['symbol'], (int)$src['type'], $tick, $ts);

        // 埋点：这一路是靠兜底刷新的，监控页据此区分「WS 送的」与「REST 续的」
        \app\lib\MarketMetrics::touchGroup(
            \app\lib\MarketMetrics::SCOPE_FALLBACK,
            \app\enum\MarketChannelEnum::PRICE->value,
            $sourceKey
        );

        business_log('market_rest_fallback', "[LastPrice] {$sourceKey} <= {$price} (REST 兜底 + 已分发)", 'warning');
        if (!Worker::$daemonize) {
            $output->writeln("<info>[Price-Fallback]</info> {$sourceKey} LastPrice <= {$price}");
        }
    }

    /**
     * 用兜底拿到的当前价拼一份不破坏 24h 统计的 tick：只换 close，high/low 与当前价取极值，其余沿用现值
     *
     * ⚠️ 不能省：MarketService::updatePrice() 没传的字段一律写 0，会抹掉 24h 成交与涨跌幅。
     *
     * @return array{open:string,close:string,high:string,low:string,amount:string,vol:string,count:int}
     */
    private function restFallbackTick24h(string $sourceKey, string $price): array
    {
        $cols = ['open', 'high', 'low', 'amount', 'vol', 'count'];
        $row  = MarketService::getHotMarketData($sourceKey, $cols)
            ?: Db::name('market_data')->where('source_key', $sourceKey)
                ->field('open, high, low, amount, vol, count')->find();

        $p    = MarketHelper::safeNumStr($price);
        $num  = fn(string $f) => MarketHelper::safeNumStr($row[$f] ?? 0);
        $open = $num('open');
        $high = $num('high');
        $low  = $num('low');

        return [
            // 值为 0 当作没有历史，退回当前价
            'open'   => bccomp($open, '0', 10) > 0 ? $open : $p,
            'close'  => $p,
            'high'   => bccomp($high, $p, 10) > 0 ? $high : $p,
            'low'    => (bccomp($low, '0', 10) > 0 && bccomp($low, $p, 10) < 0) ? $low : $p,
            'amount' => $num('amount'),
            'vol'    => $num('vol'),
            // count 是 int 列，走 safeNumStr 会变成 "123.0000000000" 再被 MySQL 截断
            'count'  => (int)($row['count'] ?? 0),
        ];
    }

    /**
     * REST 拉一次标记价格落库，并随 pair 层更新分发出去
     *
     * @param array $src 行情源行
     * @param int $tsBefore 发起 REST 前读到的 mark_ts，用途同 restFallbackSyncLastPrice()
     * @param Output $output
     */
    private function restFallbackSyncMarkPrice(array $src, int $tsBefore, Output $output): void
    {
        $sourceKey = (string)$src['source_key'];
        $client = $this->restClient((string)$src['exchange']);
        if (!$client) {
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Price-Fallback]</comment> {$sourceKey} 跳过: exchange={$src['exchange']} 无 REST 实现");
            }
            return;
        }

        $price = $client->fetchRestMarkPrice((string)$src['symbol'], (int)$src['type']);
        if ($price === null) {
            business_log('market_rest_fallback', "[MarkPrice] {$sourceKey} REST fetch failed", 'error');
            if (!Worker::$daemonize) {
                $output->writeln("<error>[Price-Fallback]</error> {$sourceKey} MarkPrice REST 拉取失败");
            }
            return;
        }

        // 新鲜度守卫，同 restFallbackSyncLastPrice()
        $fresh = $this->readStaleTs($sourceKey, (int)$src['type']);
        $now   = $fresh['mark_ts'] ?? null;
        if ($now !== null && (int)$now > $tsBefore) {
            business_log('market_rest_fallback', "[MarkPrice] {$sourceKey} skipped: WS recovered during REST (ts {$tsBefore} -> {$now})");
            if (!Worker::$daemonize) {
                $output->writeln("<comment>[Price-Fallback]</comment> {$sourceKey} MarkPrice 放弃写入: REST 期间 WS 已恢复");
            }
            return;
        }

        $exchange = MarketExchangeEnum::from((string)$src['exchange']);
        $ts       = (int)(microtime(true) * 1000);

        // 同 restFallbackSyncLastPrice()：标记价格参与强平，订阅方必须同步刷新 ——
        // 由 updateMarkPrice() 在 pair 层写 pair 快照完成
        MarketService::updateMarkPrice($exchange, (string)$src['symbol'], (int)$src['type'], $price, $ts);

        \app\lib\MarketMetrics::touchGroup(
            \app\lib\MarketMetrics::SCOPE_FALLBACK,
            \app\enum\MarketChannelEnum::MARK_PRICE->value,
            $sourceKey
        );

        business_log('market_rest_fallback', "[MarkPrice] {$sourceKey} <= {$price} (REST 兜底 + 已分发)", 'warning');
        if (!Worker::$daemonize) {
            $output->writeln("<info>[Price-Fallback]</info> {$sourceKey} MarkPrice <= {$price}");
        }
    }
}
