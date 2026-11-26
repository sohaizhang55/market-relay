<?php

declare(strict_types=1);

namespace app\lib;

/**
 * 平台数值精度常量
 *
 * 所有 bcmath 运算的 scale 参数一律取这里的常量，禁止在业务代码里散写字面量。
 * 要整站调精度只改本文件，改完全仓的 bcadd/bcsub/bcmul/bcdiv/bccomp 同步生效。
 *
 * 用法
 *   bcadd($a, $b, NumberConst::SCALE)          落库 / 对外精度
 *   bcdiv($a, $b, NumberConst::SCALE_CALC)     不落库的中间量
 *
 * 全仓一律显式传 scale，不做函数封装：钱的代码读一行就该知道精度是多少，
 * 不用跳去看封装实现。外部数据里的科学计数法在入口归一一次即可
 * (行情侧用 MarketHelper::safeNumStr)，不在每个运算点重复防御。
 *
 * 影响面
 *   落地  金额 / 数量 / 价格列均为 decimal(20,8)，SCALE 必须与之一致，
 *         调大了写库会被 MySQL 截断，调小了会凭空丢钱
 *   消费  全仓 bcadd/bcsub/bcmul/bcdiv/bccomp 的第三个实参
 */
class NumberConst
{
    /**
     * 默认精度：金额 / 数量 / 价格的落库与对外精度，对齐 decimal(20,8)。
     * 拿不准用哪个时就用它。
     */
    public const SCALE = 8;

    /**
     * 高精度：只用于不落库的中间计算，避免连乘连除逐步丢位
     * (如按 U 折算币数、保证金 / 手续费的中间量)。
     * 结果最终仍要按 SCALE 收敛后再写库或返回前端。
     */
    public const SCALE_CALC = 18;
}
