<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketDataTypeEnum;

/**
 * BingX REST 客户端（只接 USDT 永续，含 NC 前缀的 TradFi 合约）
 *
 * 接口与字段均为 2026-10-20 对 open-api.bingx.com 实测所得（官方文档是前端渲染的单页，抓不到正文）：
 *   GET /openApi/swap/v2/quote/ticker?symbol=     → data.lastPrice
 *   GET /openApi/swap/v2/quote/premiumIndex?symbol= → data.markPrice / lastFundingRate / nextFundingTime / updateTime
 *   GET /openApi/swap/v2/quote/klines?symbol=&interval=&startTime=&limit= → data[{open,close,high,low,volume,time}]，**正序**
 * 每个响应都包一层 {"code":0,"msg":"","data":...}；code 非 0（如限流 100410「请稍后重试」）一律按取数失败返回 null。
 *
 * ⚠️ 限流比币安紧：实测连续十几个请求就会回 100410。调用方（market:price_fallback / market:kline_backfill / market:funding）
 * 已按交易所单独起进程、每轮有条数配额，这里不再自行节流。
 */
class MarketRestBingx extends MarketRestAbstract
{
    /** 周期映射: 平台标准(秒) => BingX interval，WS 订阅 (MarketBingx) 与 REST 回补共用 */
    public const PERIOD_MAP = [
        60    => '1m',
        300   => '5m',
        900   => '15m',
        3600  => '1h',
        14400 => '4h',
        86400 => '1d',
    ];

    /** REST 根地址，见 config/market.php endpoints.bingx.rest */
    private function base(): string
    {
        return rtrim((string)\think\facade\Config::get('market.endpoints.bingx.rest', 'https://open-api.bingx.com'), '/');
    }

    /**
     * 取 data 段。null = 请求失败或 code 非 0（含限流），调用方按「失败、下轮重试」处理
     */
    private function getData(string $path, int $timeoutSec): mixed
    {
        $resp = MarketHelper::httpGetJson($this->base() . $path, $this->timeout($timeoutSec));
        if (!is_array($resp) || (int)($resp['code'] ?? -1) !== 0) return null;
        return $resp['data'] ?? null;
    }

    /** BingX 只接永续；现货 symbol 不会出现在 market_data 里，传进来直接返回失败 */
    private function isSwap(int $type): bool
    {
        return $type === MarketDataTypeEnum::SWAP->value;
    }

    public function fetchRestLastPrice(string $symbol, int $type): ?string
    {
        if (!$this->isSwap($type)) return null;
        $data = $this->getData('/openApi/swap/v2/quote/ticker?symbol=' . rawurlencode($symbol), 3);
        return isset($data['lastPrice']) && is_numeric($data['lastPrice']) ? (string)$data['lastPrice'] : null;
    }

    public function fetchRestMarkPrice(string $symbol, int $type): ?string
    {
        if (!$this->isSwap($type)) return null;
        $data = $this->getData('/openApi/swap/v2/quote/premiumIndex?symbol=' . rawurlencode($symbol), 3);
        return isset($data['markPrice']) && is_numeric($data['markPrice']) ? (string)$data['markPrice'] : null;
    }

    /**
     * 同 Binance：premiumIndex 一个接口同时给标记价与当期费率，BingX 也没有独立的预估费率字段
     */
    public function fetchRestFundingRate(string $symbol, int $type): ?array
    {
        if (!$this->isSwap($type)) return null;
        $data = $this->getData('/openApi/swap/v2/quote/premiumIndex?symbol=' . rawurlencode($symbol), 3);
        $rate = $data['lastFundingRate'] ?? null;
        if ($rate === null || !is_numeric($rate)) return null;
        return [
            'rate'            => (string)$rate,
            'estimated'       => (string)$rate,
            'next_funding_ts' => (int)($data['nextFundingTime'] ?? 0),
            // premiumIndex 没有服务器时间字段，updateTime 是上一次结算时刻、不能当「现在」用，填 0 让调用方退回本地时间
            'exchange_ts'     => 0,
        ];
    }

    /**
     * 历史 K 线。BingX 的 startTime 语义：只返回 [startTime, startTime + limit 根] 这个窗口里的数据，
     * 窗口落在休市时段（如外汇合约的周末）就回空数组 —— 这是「这一段确实没有数据」，不是失败。
     * K 线没有成交额字段，amount 用 成交量 × 收盘价 近似，与 WS 侧 MarketBingx 的处理一致。
     */
    public function fetchRestKlines(string $symbol, int $type, string $period, int $sinceMs, int $limit): ?array
    {
        if (!$this->isSwap($type)) return [];
        $interval = self::PERIOD_MAP[(int)$period] ?? null;
        if (!$interval) return [];

        $size = min(max(1, $limit), $this->klineMaxLimit($type));
        $rows = $this->getData('/openApi/swap/v2/quote/klines?symbol=' . rawurlencode($symbol)
            . "&interval={$interval}&startTime={$sinceMs}&limit={$size}", 5);
        if (!is_array($rows)) return null;

        $candles = [];
        foreach ($rows as $row) {
            if (!isset($row['time'])) continue;
            $close = MarketHelper::safeNumStr($row['close'] ?? 0);
            $vol   = MarketHelper::safeNumStr($row['volume'] ?? 0);
            $candles[] = [
                'id'     => intdiv((int)$row['time'], 1000),
                'open'   => $row['open'] ?? 0,
                'high'   => $row['high'] ?? 0,
                'low'    => $row['low'] ?? 0,
                'close'  => $row['close'] ?? 0,
                'vol'    => $row['volume'] ?? 0,
                'amount' => bcmul($vol, $close, 10),
            ];
        }
        usort($candles, fn($a, $b) => $a['id'] <=> $b['id']);
        return $candles;
    }

    /**
     * 2026-10-23 实测：合约 1440。⚠️ 超过时回的是 code 0 加空数组，和「这一段没有数据」分不出来，
     * 所以绝不能超 —— 超了会被当成交易所确认没有、记进 KlineFetchMemory
     */
    public function klineMaxLimit(int $type): int
    {
        return 1440;
    }
}
