<?php

declare(strict_types=1);

namespace app\command;

use app\business\service\MarketService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;

/**
 * 定期清理 market_source_kline / exchange_pair_kline 两张K线表，按 (source_key/pair_id, period)
 * 各留最近 N 个周期的时长，N 按周期取自 config/market.php 的 kline_retention.*。
 * exchange_pair_kline 是分发给订阅方的 pair K 线，清理会让可查历史变短，2026-08-14 确认接受
 * 这个取舍。通过 xadmin:task 每日调度一次。
 */
class MarketKlineClean extends Command
{
    protected function configure()
    {
        $this->setName('market:kline_clean')
            ->setDescription('Clean up market_source_kline / exchange_pair_kline, keep latest N candles per group');
    }

    protected function execute(Input $input, Output $output)
    {
        $lockKey = 'market_kline_clean_lock';
        if (!Cache::set($lockKey, 1, 300)) {
            $output->writeln('[SKIP] Another market kline clean process is running.');
            return;
        }

        try {
            $output->writeln('[START] Market kline clean: ' . date('Y-m-d H:i:s'));


            // 保留量按周期取，由 MarketService 内部解析配置，见 MarketHelper::klineKeep()
            $sourceDeleted = MarketService::cleanSourceKline();
            $pairDeleted   = MarketService::cleanPairKline();

            $output->writeln("[DONE] market_source_kline deleted={$sourceDeleted}, exchange_pair_kline deleted={$pairDeleted}");
            if ($sourceDeleted > 0 || $pairDeleted > 0) {
                business_log('market_kline_clean', "source_kline deleted={$sourceDeleted}, pair_kline deleted={$pairDeleted}", 'info');
            }
        } catch (\Throwable $e) {
            business_log('market_kline_clean', 'MarketKlineClean command error: ' . $e->getMessage(), 'error');
            $output->error($e->getMessage());
        } finally {
            Cache::delete($lockKey);
        }
    }
}
