<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 市场行情数据源类型枚举
 */
enum MarketDataTypeEnum: int
{
    use BaseEnum;

    #[Label('现货行情')]
    case SPOT = 1;

    #[Label('合约行情')]
    case SWAP = 2;
}
