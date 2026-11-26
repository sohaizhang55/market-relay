<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 行情源此刻的健康状态 —— 主备切换选源的输入
 *
 * OK      交易时段内，价格帧、深度帧都没超过该源的断流秒数（market_data.ticker_stale_sec / depth_stale_sec，0 = 那一路不判）
 * DOWN    交易时段内，任一路超过断流秒数，或从没收到过帧
 * CLOSED  该源自己的交易日历此刻休市：没有帧是计划内的，不算故障、不告警，但也不能拿来当备源
 *
 * 兜底 DOWN：判定所需的数据缺失（Redis 里没有该源的帧时间）一律按断流处理 ——
 * 误判成 OK 会让交易对停在一个已经断流的源上，误判成 DOWN 最多是多切一次。
 *
 * 只按单个源（交易所 × 币种）判断，不做交易所级别的判断：整家交易所断了，它的每个源各自判 DOWN。
 *
 * 影响面
 *   配置  market_data.ticker_stale_sec / depth_stale_sec（后台「原始行情」页可改）
 *   消费  market:source_select 选源 · SourceSelector::decide()
 *   校验  无 —— 只由选源进程按 WS 新鲜度算出，不接受外部输入
 *   落地  无 —— 每秒现算，不落库
 *   前端  无
 */
enum MarketSourceHealthEnum: int
{
    use BaseEnum;

    #[Label('正常')]
    case OK = 1;

    #[Label('断流')]
    case DOWN = 2;

    #[Label('休市')]
    case CLOSED = 3;

    /** 数据缺失时的兜底，见类注释 */
    public const FALLBACK = self::DOWN;
}
