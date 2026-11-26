<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketDataTypeEnum;

/**
 * 币安 REST 客户端（现货 + U 本位合约）
 * 方法体由 MarketBinance 原样搬迁，见 MarketRestAbstract 类注释
 */
class MarketRestBinance extends MarketRestAbstract
{
    /**
     * 周期映射: 平台标准(秒) => 币安标准，WS 订阅 (MarketBinance::connectBinance) 与 REST 断档回补
     * (fetchRestKlines) 共用同一份
     */
    public const PERIOD_MAP = [
        60    => '1m',
        300   => '5m',
        900   => '15m',
        3600  => '1h',
        14400 => '4h',
        86400 => '1d',
    ];

    /**
     * REST 兜底：现货/合约成交价
     * 现货: GET /api/v3/ticker/price?symbol=BTCUSDT (base host 与 WS 现货域名一致，见 config/market.php endpoints.binance)
     * 合约: GET /fapi/v1/ticker/price?symbol=BTCUSDT
     */
    public function fetchRestLastPrice(string $symbol, int $type): ?string
    {
        $apiSymbol = strtoupper($symbol);
        $restUrls = (array)\think\facade\Config::get('market.endpoints.binance.rest', []);
        $url = $type === MarketDataTypeEnum::SPOT->value
            ? "{$restUrls['spot']}/api/v3/ticker/price?symbol={$apiSymbol}"
            : "{$restUrls['swap']}/fapi/v1/ticker/price?symbol={$apiSymbol}";

        $data = MarketHelper::httpGetJson($url, $this->timeout(3));
        return isset($data['price']) ? (string)$data['price'] : null;
    }

    /**
     * REST 兜底：合约标记价格 GET /fapi/v1/premiumIndex?symbol=BTCUSDT
     */
    public function fetchRestMarkPrice(string $symbol, int $type): ?string
    {
        $apiSymbol = strtoupper($symbol);
        $swapRest = (string)\think\facade\Config::get('market.endpoints.binance.rest.swap', 'https://fapi.binance.com');
        $url = "{$swapRest}/fapi/v1/premiumIndex?symbol={$apiSymbol}";
        $data = MarketHelper::httpGetJson($url, $this->timeout(3));
        return isset($data['markPrice']) ? (string)$data['markPrice'] : null;
    }

    /**
     * REST 兜底：GET /fapi/v1/premiumIndex?symbol=BTCUSDT
     *
     * 这是 Binance 费率的唯一供数通道：markPrice@1s 帧里虽然带 r/T，但已刻意不接
     * (见 markPriceUpdate 分支的注释)——费率是 8 小时一档的慢变量，不值得占 WS 流。
     * 同一个 premiumIndex 接口既给 markPrice 也给 lastFundingRate，与 fetchRestMarkPrice() 复用。
     */
    public function fetchRestFundingRate(string $symbol, int $type): ?array
    {
        $apiSymbol = strtoupper($symbol);
        $swapRest = (string)\think\facade\Config::get('market.endpoints.binance.rest.swap', 'https://fapi.binance.com');
        $url = "{$swapRest}/fapi/v1/premiumIndex?symbol={$apiSymbol}";
        $data = MarketHelper::httpGetJson($url, $this->timeout(3));

        $rate = $data['lastFundingRate'] ?? null;
        if ($rate === null || !is_numeric($rate)) return null;

        return [
            'rate'            => (string)$rate,
            'estimated'       => (string)$rate, // Binance 无独立预估费率字段
            'next_funding_ts' => (int)($data['nextFundingTime'] ?? 0),
            // 交易所服务器时间，让 funding_rate_ts 统一为交易所时钟口径 (Huobi 侧取响应顶层 ts)
            'exchange_ts'     => isset($data['time']) && is_numeric($data['time']) ? (int)$data['time'] : 0,
        ];
    }

    /**
     * K线断档回补：REST 拉取历史 K 线
     * 现货: GET /api/v3/klines?symbol=BTCUSDT&interval=1m&startTime=...&limit=N (base host 同 fetchRestLastPrice)
     * 合约: GET /fapi/v1/klines?symbol=BTCUSDT&interval=1m&startTime=...&limit=N
     * 响应为二维数组 [openTime, open, high, low, close, volume, closeTime, quoteVolume, ...]，
     * amount 取 quoteVolume(下标7)，与 WS kline 分支 ('amount' => $k['q']) 的映射保持一致
     */
    public function fetchRestKlines(string $symbol, int $type, string $period, int $sinceMs, int $limit): ?array
    {
        $binancePeriod = self::PERIOD_MAP[(int)$period] ?? null;
        if (!$binancePeriod) return [];

        $apiSymbol = strtoupper($symbol);
        $size = min(max(1, $limit), $this->klineMaxLimit($type));
        $restUrls = (array)\think\facade\Config::get('market.endpoints.binance.rest', []);
        $url = $type === MarketDataTypeEnum::SPOT->value
            ? "{$restUrls['spot']}/api/v3/klines?symbol={$apiSymbol}&interval={$binancePeriod}&startTime={$sinceMs}&limit={$size}"
            : "{$restUrls['swap']}/fapi/v1/klines?symbol={$apiSymbol}&interval={$binancePeriod}&startTime={$sinceMs}&limit={$size}";

        // 失败与"这一段没有数据"必须分开，见 MarketRestAbstract::fetchRestKlines 的契约。
        // 币安对早于上市的 startTime 是 clamp 到首根、不是回空，所以空数组在这里几乎只可能是失败
        $rows = MarketHelper::httpGetJson($url, $this->timeout(5));
        if (!is_array($rows)) return null;

        $candles = [];
        foreach ($rows as $row) {
            if (!isset($row[0])) continue;
            $candles[] = [
                'id'     => (int)floor(((int)$row[0]) / 1000),
                'open'   => $row[1] ?? 0,
                'high'   => $row[2] ?? 0,
                'low'    => $row[3] ?? 0,
                'close'  => $row[4] ?? 0,
                'vol'    => $row[5] ?? 0,
                'amount' => $row[7] ?? 0,
            ];
        }

        usort($candles, fn($a, $b) => $a['id'] <=> $b['id']);
        return $candles;
    }

    /** 2026-10-23 实测：现货 1000（超过静默截断），U 本位合约 1500（超过报错）。两边都能按 startTime 翻页 */
    public function klineMaxLimit(int $type): int
    {
        return $type === MarketDataTypeEnum::SPOT->value ? 1000 : 1500;
    }
}
