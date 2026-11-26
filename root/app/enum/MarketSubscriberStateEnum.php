<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 分发机视角下单个订阅方连接的状态（遵循项目 1=ON / 2=OFF 的状态取值约定）
 *
 * LAGGING 的判定来源是 Workerman 的 onBufferFull 回调，即该连接的发送缓冲已达上限。
 * 处于该状态时分发机**主动跳过**它，而不是继续往满缓冲里塞：
 * 后者除了必然被丢弃之外，还要白白付出 json_encode、对数 MB 字符串取 strlen、
 * 以及走一遍 onError 回调的开销。
 */
enum MarketSubscriberStateEnum: int
{
    use BaseEnum;

    #[Label('正常')]
    case ACTIVE = 1;

    #[Label('积压中')]
    case LAGGING = 2;
}
