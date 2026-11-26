<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 行情分发频道，值即分发帧里的 _channel 字段
 *
 * PRICE       最新价
 * KLINE       K线
 * DEPTH       深度
 * TRADE       成交
 * MARK_PRICE  标记价格
 *
 * 兜底 无：不认识的频道 tryFrom() 得 null，由调用方忽略
 *
 * 影响面
 *   配置  无
 *   消费  MarketPublisher（发帧）· MarketMetrics / MarketPipeline（按频道埋点与诊断）
 *   校验  订阅方收帧时的 tryFrom
 *   落地  分发帧 _channel 字段 · Redis market:metrics:group:* 的 field 前缀
 *   前端  无
 */
enum MarketChannelEnum: string
{
    use BaseEnum;

    #[Label('最新价')]
    case PRICE = 'market:raw:price';

    #[Label('K线')]
    case KLINE = 'market:raw:kline';

    #[Label('深度')]
    case DEPTH = 'market:raw:depth';

    #[Label('成交')]
    case TRADE = 'market:raw:trade';

    #[Label('标记价格')]
    case MARK_PRICE = 'market:raw:mark_price';

    /** 把任意频道字符串缩成短名；没有冒号时原样返回（统计里会出现非枚举值的伪频道名） */
    public static function shorten(string $channel): string
    {
        $pos = strrpos($channel, ':');
        return $pos === false ? $channel : substr($channel, $pos + 1);
    }
}
