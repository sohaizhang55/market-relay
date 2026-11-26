<?php

declare(strict_types=1);
/**
 * +----------------------------------------------------------------------
 * | ThinkAdmin Plugin for ThinkAdmin
 * +----------------------------------------------------------------------
 * | 版权所有 2014~2026 ThinkAdmin [ thinkadmin.top ]
 * +----------------------------------------------------------------------
 * | 官方网站: https://thinkadmin.top
 * +----------------------------------------------------------------------
 * | 开源协议 ( https://mit-license.org )
 * | 免责声明 ( https://thinkadmin.top/disclaimer )
 * | 会员特权 ( https://thinkadmin.top/vip-introduce )
 * +----------------------------------------------------------------------
 * | gitee 代码仓库：https://gitee.com/zoujingli/ThinkAdmin
 * | github 代码仓库：https://github.com/zoujingli/ThinkAdmin
 * +----------------------------------------------------------------------
 */

return [
    'commands' => [
        'market:huobi'      => \app\command\MarketHuobi::class,
        'market:binance'    => \app\command\MarketBinance::class,
        'market:bingx'      => \app\command\MarketBingx::class,
        'market:db_flush'   => \app\command\MarketDbFlusher::class,
        // 回补通道自检：InnerAuth 四条拒绝路径都返回 403，裸 curl 分辨不出是哪一种
        'market:inner_check' => \app\command\MarketInnerApiCheck::class,
        // 价格兜底与 K 线补数原本同在 market:backfill，2026-10 拆开，见 MarketRestWorkerAbstract
        'market:price_fallback' => \app\command\MarketPriceFallback::class,
        'market:kline_backfill' => \app\command\MarketKlineBackfill::class,
        'market:funding'    => \app\command\MarketFunding::class,
        'market:source_select' => \app\command\MarketSourceSelect::class,
        'market:source_select_notify' => \app\command\MarketSourceSelectNotify::class,
        'xadmin:task'       => \app\command\Task::class,
        'xadmin:notify_admins' => \app\command\NotifyAdminsJob::class,
        'xdata:db'          => \app\command\DbStructure::class,
        \app\command\MarketSchemaCheck::class,
        \app\command\MarketKlineReconcile::class,
        \app\command\MarketConfigCheck::class,
        \app\command\MarketSessionCheck::class,
        \app\command\MarketDataCheck::class,
        \app\command\AlertNotify::class,
        'market:price_check_expire' => \app\command\MarketPriceCheckExpire::class,
        // 节假日休市日历导入：幂等，数据由 tools/export_exchange_calendars.py 产出
        'market:import_calendar'    => \app\command\MarketImportCalendar::class,
        'command_heartbeat:check_expire'    => \app\command\CommandHeartbeatCheckExpire::class,
        'market:dispatch'           => \app\command\MarketDispatcher::class,
        'market:subscriber_sync'    => \app\command\DispatchSubscriberSync::class,
        'market:kline_clean'         => \app\command\MarketKlineClean::class,
    ],
];
