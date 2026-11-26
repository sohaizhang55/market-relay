<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketDataTypeEnum;

/**
 * 火币 REST 客户端（现货 + 线性合约）
 * 方法体由 MarketHuobi 原样搬迁，见 MarketRestAbstract 类注释
 */
class MarketRestHuobi extends MarketRestAbstract
{
    /**
     * 合约 from/to 单次能取的最大根数。实测跨度到 1999 个周期（= 2000 根）还是 ok，
     * 再多一根就返回 bad-request "history kline query"。
     */
    private const SWAP_MAX_CANDLES = 2000;

    /**
     * 现货 size 的上限，同时也是现货能拿到的**历史深度**上限 ——
     * 现货接口没有 from/to（传了被静默忽略，返回的仍是最近那批），翻不了页。
     */
    private const SPOT_MAX_CANDLES = 2000;

    /**
     * 周期映射: 平台标准(秒) => 火币标准，WS 订阅 (MarketHuobi::connectHuobi) 与 REST 断档回补
     * (fetchRestKlines) 共用同一份。只列「可直接用」的周期，见 MarketHelper::klinePlan()。
     *
     * ⚠️ 没有日线（1day）：火币的日线从北京时间 00:00（UTC 16:00）切，平台的一天从 UTC 00:00 起，
     * 直接用会和其它交易所的日线错开 8 小时，主备切换时同一天出现两根。火币的日线改由 4 小时线
     * 按 UTC 汇总（MarketService::deriveKlineLive / rebuildDerivedKlines），4 小时线两边切分点重合。
     *
     * ⚠️ 日线最多约 333 根：4 小时线只保留 2000 根（kline_retention，见 MarketHelper::klineKeep()），
     *    2000 × 4 小时 ≈ 333 天，补不满日线的保留量（2000 根）。现货、合约一样，这是有意的取舍。
     */
    public const PERIOD_MAP = [
        60    => '1min',
        300   => '5min',
        900   => '15min',
        3600  => '60min',
        14400 => '4hour',
    ];


    /**
     * REST 兜底：现货/合约成交价
     * 现货: GET /market/detail/merged?symbol=btcusdt
     * 合约: GET /linear-swap-ex/market/detail/merged?contract_code=BTC-USDT
     */
    public function fetchRestLastPrice(string $symbol, int $type): ?string
    {
        $restUrls = (array)\think\facade\Config::get('market.endpoints.huobi.rest', []);
        $url = $type === MarketDataTypeEnum::SPOT->value
            ? "{$restUrls['spot']}/market/detail/merged?symbol=" . strtolower($symbol)
            : "{$restUrls['swap']}/linear-swap-ex/market/detail/merged?contract_code=" . strtoupper($symbol);

        $data = MarketHelper::httpGetJson($url, $this->timeout(3));
        $close = $data['tick']['close'] ?? null;
        return $close !== null ? (string)$close : null;
    }

    /**
     * REST 兜底：合约标记价格，取最新一根 1min 标记价格 K 线的收盘价 (与 WS mark_price.1min 频道同源)
     */
    public function fetchRestMarkPrice(string $symbol, int $type): ?string
    {
        $swapRest = (string)\think\facade\Config::get('market.endpoints.huobi.rest.swap', 'https://api.hbdm.com');
        $url = "{$swapRest}/index/market/history/linear_swap_mark_price_kline?contract_code=" . strtoupper($symbol) . '&period=1min&size=1';
        $data = MarketHelper::httpGetJson($url, $this->timeout(3));
        $close = $data['data'][0]['close'] ?? null;
        return $close !== null ? (string)$close : null;
    }

    /**
     * 资金费率：GET /linear-swap-api/v1/swap_funding_rate?contract_code=BTC-USDT
     *
     * 对火币这是**唯一**供数通道，不是兜底：火币线性合约没有公开的资金费率 WS 频道
     * (ws_index 只有指数/标记价)，只能靠 MarketFunding::tickFromRest() 周期轮询命中这里。
     * estimated_rate 是火币自报的下期预测费率，与当期 funding_rate 分开给。
     */
    public function fetchRestFundingRate(string $symbol, int $type): ?array
    {
        $swapRest = (string)\think\facade\Config::get('market.endpoints.huobi.rest.swap', 'https://api.hbdm.com');
        $url = "{$swapRest}/linear-swap-api/v1/swap_funding_rate?contract_code=" . strtoupper($symbol);
        $data = MarketHelper::httpGetJson($url, $this->timeout(3));

        $rate = $data['data']['funding_rate'] ?? null;
        if ($rate === null || !is_numeric($rate)) return null;

        // 实测 (2026-08-19 BTC-USDT)：estimated_rate 与 next_funding_time 两个字段**都返回空字符串**，
        // 真正带值的是 funding_time —— 它是"本期费率将在何时结算"(未来时刻，如 16:00:00)，
        // 语义正好对应本平台的 exchange_next_funding_ts。原先取 next_funding_time 恒得 0。
        // estimated_rate 空则退回当期费率，与 Binance 无独立预估字段时的处理一致。
        $estimated = $data['data']['estimated_rate'] ?? null;
        $nextTs    = $data['data']['next_funding_time'] ?? null;
        if (!is_numeric($nextTs)) $nextTs = $data['data']['funding_time'] ?? 0;

        return [
            'rate'            => (string)$rate,
            'estimated'       => is_numeric($estimated) ? (string)$estimated : (string)$rate,
            'next_funding_ts' => is_numeric($nextTs) ? (int)$nextTs : 0,
            // 交易所服务器时间 (响应顶层 ts)，供调用方统一 funding_rate_ts 口径用；取不到留 0
            'exchange_ts'     => isset($data['ts']) && is_numeric($data['ts']) ? (int)$data['ts'] : 0,
        ];
    }

    /**
     * K线断档回补：REST 拉取历史 K 线。两条路的取数模型完全不同，别混着看：
     *
     * 现货  GET /market/history/kline?symbol=btcusdt&period=1min&size=N
     *   只认 size，语义是"距请求这一刻最近的 N 根"。from/to 传了会被静默忽略（实测：返回的仍是
     *   最近那批，不落在请求区间内）。于是 size 必须大到让返回区间覆盖住 sinceMs，缺口才可能
     *   落进结果里 —— 一次没覆盖到就永远覆盖不到，下一轮的"现在"只会更晚。所以按缺口实际年龄
     *   反推 size，而不是直接用调用方给的 $limit。天花板 SPOT_MAX_CANDLES 既是单次上限，
     *   也是这条路能拿到的历史深度上限。
     *
     * 合约  GET /linear-swap-ex/market/history/kline?contract_code=BTC-USDT&period=1min&from=&to=
     *   认 from/to（秒级闭区间），能精确要"这一段"，于是可以像 Binance 那样分批递进往前翻，
     *   深度不受单次上限约束（实测一年前的 1min 照样取得到）。
     *   实测过的边界：跨度超过 SWAP_MAX_CANDLES 根 → bad-request；to 略超当前时间 → 正常；
     *   from 早于合约上市 → status=ok 加空数组，不是错误（调用方靠这个区分"到底了"和"取数失败"）。
     */
    public function fetchRestKlines(string $symbol, int $type, string $period, int $sinceMs, int $limit): ?array
    {
        $huobiPeriod = self::PERIOD_MAP[(int)$period] ?? null;
        if (!$huobiPeriod) return [];

        $restUrls = (array)\think\facade\Config::get('market.endpoints.huobi.rest', []);

        if ($type === MarketDataTypeEnum::SPOT->value) {
            // 现货只有 size，语义是"最近 N 根" —— from/to 传了会被静默忽略，返回的仍是最近那批。
            // 于是老缺口必须一次就覆盖到（下一轮的"现在"只会更晚，缺口不会自己进入窗口），
            // 所以按缺口实际年龄反推 size，不能小于缺口年龄（调用方一般已按 klineMaxLimit() 拿满）
            $periodMs   = (int)$period * 1000;
            $nowMs      = (int)(microtime(true) * 1000);
            $neededSize = (int)ceil(max(0, $nowMs - $sinceMs) / $periodMs) + 1;
            $size       = min(max($limit, $neededSize), self::SPOT_MAX_CANDLES);
            $url = "{$restUrls['spot']}/market/history/kline?symbol=" . strtolower($symbol)
                . '&period=' . $huobiPeriod . '&size=' . $size;
        } else {
            // 合约认 from/to（秒级闭区间），于是可以精确要"这一段"，而不是"最近 N 根"——
            // 补历史能一页页往前翻，深度不再受 2000 根的限制（实测一年前的 1min 照样取得到）。
            // 对补缺口也更省：以前补一个十天前的缺口要把最近 14400 根整个拉回来。
            // 边界（都实测过）：跨度超过 SWAP_MAX_CANDLES 根返回 bad-request；
            // to 略超当前时间没关系；from 早于合约上市返回 status=ok 加空数组，不是错误
            $size    = min(max(1, $limit), self::SWAP_MAX_CANDLES);
            $fromSec = max(0, intdiv($sinceMs, 1000));
            $toSec   = $fromSec + ($size - 1) * (int)$period;
            $url = "{$restUrls['swap']}/linear-swap-ex/market/history/kline?contract_code=" . strtoupper($symbol)
                . '&period=' . $huobiPeriod . '&from=' . $fromSec . '&to=' . $toSec;
        }

        $data = MarketHelper::httpGetJson($url, $this->timeout(5));
        // 请求本身失败（含限流冷却）→ null，让调用方重试；status 不是 ok 同理。
        // 只有交易所明确回了一个空 data 才是"这一段没有数据"
        if ($data === null) return null;
        if (($data['status'] ?? '') !== 'ok') return null;
        $rows = $data['data'] ?? null;
        if (!is_array($rows)) return null;

        $candles = [];
        foreach ($rows as $row) {
            if (!isset($row['id'])) continue;
            $candles[] = [
                'id'     => (int)$row['id'],
                'open'   => $row['open'] ?? 0,
                'high'   => $row['high'] ?? 0,
                'low'    => $row['low'] ?? 0,
                'close'  => $row['close'] ?? 0,
                'vol'    => $row['vol'] ?? 0,
                'amount' => $row['amount'] ?? 0,
            ];
        }

        usort($candles, fn($a, $b) => $a['id'] <=> $b['id']);
        return $candles;
    }

    public function klineMaxLimit(int $type): int
    {
        return $type === MarketDataTypeEnum::SPOT->value ? self::SPOT_MAX_CANDLES : self::SWAP_MAX_CANDLES;
    }

    /** 现货没有 from/to，只能拿最近 SPOT_MAX_CANDLES 根；合约按 from/to 翻页 */
    public function klinePageable(int $type): bool
    {
        return $type !== MarketDataTypeEnum::SPOT->value;
    }
}
