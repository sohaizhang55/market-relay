<?php

declare(strict_types=1);

namespace app\lib;

use app\enum\MarketChannelEnum;
use app\enum\MarketExchangeEnum;

/**
 * 交易所 REST 客户端
 *
 * 这 4 个方法原本是 MarketCollectorAbstract 的 abstract 声明、实现在 MarketHuobi/MarketBinance 里。
 * 2026-08-20 抽出来独立成 lib：价格兜底在 market:price_fallback、K线补数在 market:kline_backfill、
 * 费率轮询在 market:funding（原因见 MarketRestWorkerAbstract 类注释），这几个进程都不继承采集器、
 * 拿不到 protected 方法，取数必须能独立调用。
 *
 * 周期映射 PERIOD_MAP 也一并搬到各子类：它是"交易所协议细节"，WS 订阅与 REST 回补共用同一份，
 * 放在按交易所划分的 lib 里比留在 command 里更合适——command 引用 lib 是正向依赖，反过来不是。
 */
abstract class MarketRestAbstract
{
    /**
     * 按交易所取对应实现，没有实现的交易所返回 null，调用方必须自行跳过。
     *
     * ⚠️ default 分支不能省：MarketExchangeEnum 里除了 HUOBI/BINANCE 还有 OKX 与 COINGECKO
     * (目前没有采集实现)，match 漏掉 case 又没有 default 会抛 UnhandledMatchError——
     * 而回补是按 DB 扫出来的 source_key 驱动的，market_data 里只要有一行 okx/coingecko 的配置，
     * 整个 Timer 回调就会被这个异常打断，连带后面所有源都补不了。
     */
    public static function make(MarketExchangeEnum $exchange): ?self
    {
        return match ($exchange) {
            MarketExchangeEnum::HUOBI   => new MarketRestHuobi(),
            MarketExchangeEnum::BINANCE => new MarketRestBinance(),
            MarketExchangeEnum::BINGX   => new MarketRestBingx(),
            default                     => null,
        };
    }

    /**
     * 单次 HTTP 超时的覆盖值(秒)。null = 各方法用自己的默认值。
     *
     * 存在的理由：调用方（MarketPriceFallback / MarketKlineBackfill）都在**单线程事件循环**里，
     * 一轮里连着发几次同步 curl，只有条数配额挡不住阻塞时长 —— 5 条 × 5 秒 = 25 秒。
     * 由调用方按本轮剩余预算压缩，见 TickBudget::timeout()。
     */
    protected ?int $timeoutOverride = null;

    /** 设置本次取数的超时上限(秒)。返回 $this，可链式跟在 make() 后面 */
    public function withTimeout(?int $seconds): static
    {
        $this->timeoutOverride = $seconds === null ? null : max(1, $seconds);
        return $this;
    }

    /** 取实际超时：有覆盖值就用覆盖值，否则用该方法自己的默认值 */
    protected function timeout(int $default): int
    {
        return $this->timeoutOverride === null ? $default : min($default, $this->timeoutOverride);
    }

    /**
     * REST 兜底：拉取该交易所单个 symbol 的成交价 (各交易所自行实现协议细节)
     */
    abstract public function fetchRestLastPrice(string $symbol, int $type): ?string;

    /**
     * REST 兜底：拉取该交易所单个合约 symbol 的标记价格 (现货不会被调用)
     */
    abstract public function fetchRestMarkPrice(string $symbol, int $type): ?string;

    /**
     * 拉取单个合约的资金费率，是费率唯一的取数入口（不走 WS），调度见 MarketFunding::tickFromRest()
     *
     * @return array{rate:string,estimated:string,next_funding_ts:int,exchange_ts:int}|null
     *         next_funding_ts 交易所自报的下次结算毫秒时间戳，取不到填 0
     *         exchange_ts     交易所服务器毫秒时间戳 (Huobi 响应顶层 ts / Binance premiumIndex 的 time)，
     *                         让 funding_rate_ts 统一为交易所时钟口径；取不到填 0，调用方会退回本地时间
     *         null 表示拉取失败
     */
    abstract public function fetchRestFundingRate(string $symbol, int $type): ?array;

    /**
     * K线断档回补：拉取该交易所指定周期从 $sinceMs 开始、最多 $limit 根的历史 K 线 (各交易所自行实现协议细节)
     * @param string $symbol 交易所原始 symbol
     * @param int $type MarketDataTypeEnum
     * @param string $period 平台标准周期(秒)，如 "60"
     * @param int $sinceMs 起始时间戳(毫秒)
     * @param int $limit 最多拉取根数
     * @return array 按 id(秒级ts) 升序排列的 [['id','open','high','low','close','vol','amount'], ...]，形状与 WS kline tick 一致
     */
    /**
     * 取历史 K 线。
     *
     * ⚠️ null 与 [] 语义不同，调用方必须分开处理：
     *   null = 取数失败（超时 / 限流 / 响应不是合法 JSON），**该重试**
     *   []   = 请求成功但这一段确实没有数据，**是既成事实**
     * 混成一个 [] 的话，补历史会把一次 429 当成"这个币没有更早的数据"记下来。
     */
    abstract public function fetchRestKlines(string $symbol, int $type, string $period, int $sinceMs, int $limit): ?array;

    /**
     * 单次 K 线请求最多取几根（2026-10-23 实测，见各子类）。调用方按它一次拿满；
     * 超过的话有的交易所报错、有的静默截断，BingX 合约更是回 code 0 加空数组，长得和「没有数据」一样
     */
    abstract public function klineMaxLimit(int $type): int;

    /**
     * 能否按起点翻页取 K 线。false = 只能取「距现在最近的 klineMaxLimit 根」（火币现货），
     * 再早的洞与历史永远拿不到，调用方不该为它们发请求
     */
    public function klinePageable(int $type): bool
    {
        return true;
    }

    /** 能取到的最早一根 ts(毫秒)；可翻页的返回 0，表示不限 */
    public function klineReachFromMs(int $type, int $periodSec, int $nowMs): int
    {
        if ($this->klinePageable($type)) return 0;
        $periodMs = $periodSec * 1000;
        return intdiv($nowMs, $periodMs) * $periodMs - ($this->klineMaxLimit($type) - 1) * $periodMs;
    }

    /**
     * 一次成功的 K 线取数覆盖到哪（毫秒，含）—— 这一段里交易所没给的根，就是交易所也没有的。
     *
     * 请求的窗口是 [since, since + (limit-1) 根]。币安是「从起点往后数 limit 根有数据的」，
     * 中间有空档时返回的最后一根会更晚，到那一根为止同样都覆盖了，所以取两者的大者。不超过现在
     *
     * @param array $candles fetchRestKlines() 的非 null 返回
     */
    public static function klineCoveredUntil(int $sinceMs, int $periodSec, int $limit, array $candles): int
    {
        $windowEnd = $sinceMs + (max(1, $limit) - 1) * $periodSec * 1000;
        $last = $candles === [] ? 0 : (int)(end($candles)['id'] ?? 0) * 1000;
        return min(max($windowEnd, $last), (int)(microtime(true) * 1000));
    }

    /**
     * 该源应当持续产出的数据频道，供 market:data_check 区分「故障」与「本来就没有」
     *
     * @return MarketChannelEnum[]
     */
    public static function expectedChannels(string $symbol, int $type): array
    {
        return [MarketChannelEnum::PRICE, MarketChannelEnum::KLINE, MarketChannelEnum::DEPTH, MarketChannelEnum::TRADE];
    }

    /**
     * K线断档回补覆盖的标准周期(秒)，Huobi/Binance 现有周期完全一致，未来交易所周期不同可覆盖本方法
     */
    public static function klinePeriods(): array
    {
        return MarketHelper::KLINE_PERIODS;
    }
}
