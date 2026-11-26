<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 对账两层 K 线表：`market_source_kline`（源层） vs `exchange_pair_kline`（pair 层）。
 *
 * 为什么做成**按需命令**而不是常驻轮询：对账要回答的是"现在这两层差多少"，
 * 而这个问题只在排查时才有人问。常驻轮询会每分钟扫两张最大的表，
 * 换来一个平时没人看的数 —— `market:kline_backfill` 的 `pair_sync` 指标已经能回答
 * "补数机制有没有在工作"，那才是需要一直盯着的。
 *
 * ⚠️ 曾有顾虑"按 (pair, period) 各查一次 max(ts)，53 源 × 6 周期 × N pair
 * 开销不小"。实际不成立：两张表都有合适的复合索引
 * （`idx_kline_source_lookup(source_key,period,ts)` 与
 *  `idx_pair_period_ts_unique(pair_id,period,ts)`），
 * 各**一条聚合查询**就能拿到全部 max(ts)，是索引覆盖扫描。
 * 顾虑来自"逐个查"那种写法，不是来自问题本身。
 *
 *   php think market:kline_reconcile              # 人看
 *   php think market:kline_reconcile --verify     # 只给退出码
 *   php think market:kline_reconcile --periods=3  # 落后几个周期才算问题（默认 2）
 */
class MarketKlineReconcile extends Command
{
    /** pair 层落后多少个周期才算问题。实时同步本来就会落后不到一个周期，太紧会天天误报 */
    private const DEFAULT_LAG_PERIODS = 2;

    protected function configure()
    {
        $this->setName('market:kline_reconcile')
            ->addOption('verify', null, Option::VALUE_NONE, '只给退出码，供巡检调用')
            ->addOption('periods', null, Option::VALUE_REQUIRED, '落后几个周期算问题', (string)self::DEFAULT_LAG_PERIODS)
            ->addOption('limit', null, Option::VALUE_REQUIRED, '最多列出几条明细', '20')
            ->addOption('new-grace', null, Option::VALUE_REQUIRED,
                '交易对接入后多少秒内，pair 层为空算正常（默认 3600）', '3600')
            ->setDescription('对账两层 K 线表：pair 层是否跟得上源层');
    }

    protected function execute(Input $input, Output $output)
    {
        $quiet    = (bool)$input->getOption('verify');
        $lagLimit = max(1, (int)$input->getOption('periods'));
        $limit    = max(1, (int)$input->getOption('limit'));

        // 一条聚合拿全部源层高水位
        $src = [];
        foreach (Db::query('SELECT source_key, period, MAX(ts) AS max_ts
                            FROM market_source_kline GROUP BY source_key, period') as $r) {
            $src[$r['source_key'] . '|' . $r['period']] = (int)$r['max_ts'];
        }

        // 一条聚合拿全部pair 层高水位
        $pair = [];
        foreach (Db::query('SELECT pair_id, period, MAX(ts) AS max_ts
                            FROM exchange_pair_kline GROUP BY pair_id, period') as $r) {
            $pair[$r['pair_id'] . '|' . $r['period']] = (int)$r['max_ts'];
        }

        // create_at 用来区分「刚接入、还没播种」与「该有却一直没有」。
        // 不区分的话，每新加一个交易对就会刷出 6 行 MISSING（六个周期各一行），
        // 而一个天天冒噪音的对账没人会看第二次
        $pairs = Db::name('exchange_pair')->field('id,source_key,symbol_code,type,create_at')->select()->toArray();
        $grace = max(0, (int)$input->getOption('new-grace'));
        $now   = time();

        if (!$quiet) {
            $output->writeln(sprintf('<info>[reconcile]</info> 源层 %d 组、pair 层 %d 组、交易对 %d 个；'
                . '阈值 %d 个周期', count($src), count($pair), count($pairs), $lagLimit));
        }

        $behind = [];     // 落后
        $missing = [];    // pair 层完全没有，且已过宽限期 —— 这才是问题
        $fresh   = 0;     // pair 层完全没有，但刚接入不久 —— 正常，等 backfill 播种

        foreach ($pairs as $p) {
            $pid = (int)$p['id'];

            foreach ($src as $key => $srcMax) {
                [$sk, $period] = explode('|', $key, 2);
                if ($sk !== (string)$p['source_key']) continue;

                $periodMs = (int)$period * 1000;
                $pairMax  = $pair[$pid . '|' . $period] ?? 0;

                if ($pairMax <= 0) {
                    $age = $now - strtotime((string)($p['create_at'] ?? 'now'));
                    if ($age < $grace) { $fresh++; continue; }   // 刚接入，等播种
                    $missing[] = sprintf('pair_id=%d(%s)[%ss] pair 层一根都没有（已接入 %s），源层最新 %s',
                        $pid, $p['symbol_code'], $period, self::human($age),
                        date('m-d H:i', intdiv($srcMax, 1000)));
                    continue;
                }
                $lag = intdiv($srcMax - $pairMax, max(1, $periodMs));
                if ($lag >= $lagLimit) {
                    $behind[] = [$lag, sprintf('pair_id=%d(%s)[%ss] 落后 %d 个周期（源层 %s，pair 层 %s）',
                        $pid, $p['symbol_code'], $period, $lag,
                        date('m-d H:i', intdiv($srcMax, 1000)), date('m-d H:i', intdiv($pairMax, 1000)))];
                }
            }
        }

        // 落后最多的排前面：排查时先看最严重的那个
        usort($behind, fn($a, $b) => $b[0] <=> $a[0]);

        if (!$quiet) {
            if ($fresh > 0) {
                $output->writeln(sprintf('   跳过 %d 组：交易对接入不足 %s，pair 层还没播种是正常的'
                    . '（--new-grace 可调）', $fresh, self::human($grace)));
            }
            foreach (array_slice($missing, 0, $limit) as $m) {
                $output->writeln('   <error>[MISSING]</error> ' . $m);
            }
            foreach (array_slice($behind, 0, $limit) as [, $b]) {
                $output->writeln('   <comment>[BEHIND]</comment> ' . $b);
            }
            $more = count($missing) + count($behind) - min($limit, count($missing)) - min($limit, count($behind));
            if ($more > 0) $output->writeln("   …另有 {$more} 条未列出（--limit 调大）");
        }

        if (!$missing && !$behind) {
            $output->writeln('<info>[reconcile][OK]</info> pair 层跟得上源层');
            return 0;
        }
        $output->writeln(sprintf('<error>[reconcile][FAIL]</error> pair 层缺失 %d 组、落后 %d 组 —— '
            . '分发出去的 K 线读的正是 exchange_pair_kline；先看 market:kline_backfill 的 pair_sync 指标',
            count($missing), count($behind)));
        return 1;
    }

    /** 秒数转人话。对账输出里"已接入 3天2小时"比"270000 秒"有用得多 */
    private static function human(int $sec): string
    {
        if ($sec < 60)    return $sec . '秒';
        if ($sec < 3600)  return intdiv($sec, 60) . '分钟';
        if ($sec < 86400) return intdiv($sec, 3600) . '小时';
        return intdiv($sec, 86400) . '天' . intdiv($sec % 86400, 3600) . '小时';
    }
}
