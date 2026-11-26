<?php

namespace app\enum;

use app\enum\lib\Label;

/**
 * 统一数据状态枚举
 */
enum CommonStatusEnum: int
{
    use BaseEnum;

    #[Label('已启用')]
    case ON = 1;

    #[Label('已禁用')]
    case OFF = 2;
}
