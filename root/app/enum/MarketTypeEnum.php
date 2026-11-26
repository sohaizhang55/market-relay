<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 市场玩法类型枚举 [交易对]
 */
enum MarketTypeEnum: int
{
    use BaseEnum;

    #[Label('现货交易')]
    case SPOT = 1;

    #[Label('永续合约')]
    case CONTRACT = 2;

    // #[Label('时间交易')]
    // case TIME = 3;
}
