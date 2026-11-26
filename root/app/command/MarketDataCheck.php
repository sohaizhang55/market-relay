<?php

declare(strict_types=1);

namespace app\command;

use think\admin\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;
use app\enum\MarketChannelEnum;
use app\enum\MarketExchangeEnum;
use app\enum\MarketSessionStateEnum;
use app\business\service\MarketService;
use app\lib\MarketRestAbstract;
use app\service\MarketSessionService;

/**
 * 逐个行情源检查 tick / kline / depth / trade 四类原始数据是否在持续更新
 *
 * 看的是源层落点（热层 · 原始盘口/成交快照 · market_source_kline + 未落库缓冲），不看交易对。
 * 休市中的源只展示不判定；源本来就不产出的频道标 n/a（由各交易所实现覆盖 MarketRestAbstract::expectedChannels 声明）。
 *
 * 命令  php think market:data_check
 * 触发  手工排查
 * 单例  无（只读）
 * 依赖  表 market_data · market_source_kline · Redis 热层与原始快照 · 配置 market.stale_seconds
 *
 * 用法:
 *   php think market:data_check                        # 全部源，逐行列出
 *   php think market:data_check --exchange=bingx       # 只看某个交易所
 *   php think market:data_check --source=BINGX_ncfxeur2usdusdt_2  # 只看一个源
 *   php think market:data_check --bad                  # 只列有问题的源
 *   php think market:data_check --stale=60             # 覆盖陈旧阈值(秒)，默认 market.stale_seconds
 *   php think market:data_check --verify               # 只给退出码
 *
 * 退出码  0 = 全部正常；1 = 有源的应有频道陈旧或从无数据
 */
class MarketDataCheck extends Command
{
    /** K 线最新一根落后几个周期算陈旧：当前根 + 上一根都算正常 */
    private const KLINE_LAG_PERIODS = 2;

    private const COL_WIDTH = 14;

    protected function configure()
    {
        $this->setName('market:data_check')
            ->addOption('exchange', null, Option::VALUE_REQUIRED, '只看某个交易所，如 bingx')
            ->addOption('source', null, Option::VALUE_REQUIRED, '只看一个 source_key，如 BINGX_ncfxeur2usdusdt_2')
            ->addOption('bad', null, Option::VALUE_NONE, '只列有问题的源')
            ->addOption('stale', null, Option::VALUE_REQUIRED, '陈旧阈值(秒)，默认 market.stale_seconds')
            ->addOption('verify', null, Option::VALUE_NONE, '只给退出码，不打印')
            ->setDescription('检查各行情源 tick / kline / depth / trade 数据是否正常更新');
    }

    protected function execute(Input $input, Output $output)
    {
        $quiet   = (bool)$input->getOption('verify');
        $badOnly = (bool)$input->getOption('bad');
        $stale   = (int)($input->getOption('stale') ?: Config::get('market.stale_seconds', 300));

        $query = Db::name('market_data')->field('source_key,exchange,symbol,type,update_ts')->order('exchange,type,symbol');
        if ($ex = $input->getOption('exchange')) $query->where('exchange', strtolower((string)$ex));
        if ($sk = $input->getOption('source')) $query->where('source_key', (string)$sk);
        $rows = $query->select()->toArray();

        if (!$rows) {
            if (!$quiet) $output->writeln('<error>market_data 中没有匹配的源</error>');
            return 1;
        }

        $klineLatest = $this->klineLatest(array_column($rows, 'source_key'));
        $nowMs = (int)(microtime(true) * 1000);

        $lines = [];
        $bad = 0;
        $closed = 0;
        foreach ($rows as $r) {
            [$cells, $isBad, $isClosed] = $this->checkSource($r, $klineLatest, $nowMs, $stale);
            if ($isBad) $bad++;
            if ($isClosed) $closed++;
            if ($badOnly && !$isBad) continue;
            $lines[] = $this->pad((string)$r['source_key'], 26) . implode('', array_map(fn($c) => $this->pad($c, self::COL_WIDTH), $cells));
        }

        if (!$quiet) {
            $output->writeln(sprintf('陈旧阈值 %ds · K 线落后 %d 个周期算陈旧 · ok=正常 STALE=陈旧 NONE=从无数据 n/a=该源不产出 [休市]=只展示不判定',
                $stale, self::KLINE_LAG_PERIODS));
            $output->writeln('');
            $output->writeln($this->pad('source_key', 26) . $this->pad('状态', self::COL_WIDTH)
                . implode('', array_map(fn($h) => $this->pad($h, self::COL_WIDTH), ['tick', 'kline', 'depth', 'trade'])));
            foreach ($lines as $l) $output->writeln($l);
            $output->writeln('');
            $summary = sprintf('共 %d 个源：异常 %d · 休市 %d · 正常 %d', count($rows), $bad, $closed, count($rows) - $bad - $closed);
            $output->writeln($bad ? "<error>[FAIL] {$summary}</error>" : "<info>[OK] {$summary}</info>");
            if ($this->closedButLive) {
                $output->writeln("<comment>注：{$this->closedButLive} 个源判休市、价格却仍在更新 —— calendar_code 可能是占位或配错，详查 market:session_check</comment>");
            }
            if ($this->klineUnflushedHint()) {
                $output->writeln('<comment>注：部分 K 线只在 Redis 缓冲里、尚未落库 —— market:db_flush 可能没在运行</comment>');
            }
        }

        return $bad ? 1 : 0;
    }

    /**
     * 检查一个源
     *
     * @return array{0:string[],1:bool,2:bool} [状态 + 四列单元格, 是否异常, 是否休市]
     */
    private function checkSource(array $r, array $klineLatest, int $nowMs, int $stale): array
    {
        $sk = (string)$r['source_key'];
        $type = (int)$r['type'];
        $exchange = MarketExchangeEnum::tryFrom((string)$r['exchange']);
        $client = $exchange ? MarketRestAbstract::make($exchange) : null;
        $expected = $client ? $client::expectedChannels((string)$r['symbol'], $type) : MarketRestAbstract::expectedChannels((string)$r['symbol'], $type);

        $isClosed = MarketSessionService::stateBySourceKey($sk) === MarketSessionStateEnum::CLOSED;
        $staleMs = $stale * 1000;

        // tick / depth / trade：最后一次更新的毫秒时间戳
        $hot = MarketService::getHotMarketData($sk, ['update_ts']);
        $trades = MarketService::getRawTradesBySourceKey($sk);
        $ts = [
            'tick'  => (int)($hot['update_ts'] ?? 0) ?: (int)$r['update_ts'],
            'depth' => MarketService::getRawDepthBySourceKey($sk)['ts'],
            'trade' => $trades ? max(array_map(fn($t) => (int)($t['ts'] ?? 0), $trades)) : 0,
        ];
        $channelOf = ['tick' => MarketChannelEnum::PRICE, 'depth' => MarketChannelEnum::DEPTH, 'trade' => MarketChannelEnum::TRADE];

        $isBad = false;
        $cells = [];
        foreach (['tick', 'depth', 'trade'] as $name) {
            $need = in_array($channelOf[$name], $expected, true);
            $t = $ts[$name];
            if ($t <= 0) {
                $cells[$name] = $need ? 'NONE' : 'n/a';
                if ($need && !$isClosed) $isBad = true;
                continue;
            }
            $age = $nowMs - $t;
            $ok = $age <= $staleMs;
            $cells[$name] = ($ok ? 'ok ' : 'STALE ') . self::human($age);
            if (!$ok && $need && !$isClosed) $isBad = true;
        }

        // kline：每个周期最新一根不能落后超过 KLINE_LAG_PERIODS 个周期
        $lagging = [];
        $none = true;
        foreach (MarketRestAbstract::klinePeriods() as $p) {
            $latest = $klineLatest[$sk][$p] ?? 0;
            if ($latest > 0) $none = false;
            if ($latest <= 0 || $nowMs - $latest > self::KLINE_LAG_PERIODS * $p * 1000) {
                $lagging[] = self::periodLabel($p);
            }
        }
        $needKline = in_array(MarketChannelEnum::KLINE, $expected, true);
        if ($none) {
            $cells['kline'] = $needKline ? 'NONE' : 'n/a';
        } else {
            $cells['kline'] = $lagging ? 'STALE ' . implode(',', $lagging) : 'ok';
        }
        if (($none || $lagging) && $needKline && !$isClosed) $isBad = true;

        if ($isClosed && $ts['tick'] > 0 && $nowMs - $ts['tick'] <= $staleMs) $this->closedButLive++;

        $status = $isClosed ? '[休市]' : ($isBad ? 'FAIL' : 'OK');
        return [[$status, $cells['tick'], $cells['kline'], $cells['depth'], $cells['trade']], $isBad, $isClosed];
    }

    /**
     * 各源各周期最新一根 K 线的毫秒时间戳：落库的 max(ts) 与 Redis 未落库缓冲取大
     *
     * @return array<string,array<int,int>> source_key => period => ts
     */
    private function klineLatest(array $sourceKeys): array
    {
        $out = [];
        foreach (array_chunk($sourceKeys, 500) as $chunk) {
            $rows = Db::name('market_source_kline')
                ->whereIn('source_key', $chunk)
                ->field('source_key,period,MAX(ts) AS ts')
                ->group('source_key,period')
                ->select()->toArray();
            foreach ($rows as $row) {
                $out[$row['source_key']][(int)$row['period']] = (int)$row['ts'];
                $this->dbKlineRows++;
            }
        }

        $redis = redis_client();
        $wanted = array_flip($sourceKeys);
        foreach (($redis ? $redis->sMembers('market:kline:dirty:src') : []) ?: [] as $member) {
            // 成员形如 {source_key}:{period}:{ts}，source_key 本身不含冒号
            $parts = explode(':', (string)$member);
            if (count($parts) !== 3 || !isset($wanted[$parts[0]])) continue;
            [$sk, $p, $ts] = [$parts[0], (int)$parts[1], (int)$parts[2]];
            if ($ts > ($out[$sk][$p] ?? 0)) {
                $out[$sk][$p] = $ts;
                $this->pendingCount++;
            }
        }
        return $out;
    }

    /** 判休市、价格却仍在更新的源数 */
    private int $closedButLive = 0;

    /** 落库表里查到的 (源, 周期) 数 */
    private int $dbKlineRows = 0;

    /** 只靠 Redis 缓冲补上的 (源, 周期) 数 */
    private int $pendingCount = 0;

    /** 库里一根都没有、全在缓冲里 —— 落库进程多半没跑 */
    private function klineUnflushedHint(): bool
    {
        return $this->dbKlineRows === 0 && $this->pendingCount > 0;
    }

    private static function human(int $ms): string
    {
        $s = intdiv(max(0, $ms), 1000);
        return match (true) {
            $s < 120   => "{$s}s",
            $s < 7200  => intdiv($s, 60) . 'm',
            $s < 172800 => intdiv($s, 3600) . 'h',
            default    => intdiv($s, 86400) . 'd',
        };
    }

    private static function periodLabel(int $p): string
    {
        return match (true) {
            $p % 86400 === 0 => ($p / 86400) . 'd',
            $p % 3600 === 0  => ($p / 3600) . 'h',
            default          => ($p / 60) . 'm',
        };
    }

    /** 按显示宽度补齐（中文算 2 列） */
    private function pad(string $s, int $width): string
    {
        return $s . str_repeat(' ', max(1, $width - mb_strwidth($s)));
    }
}
