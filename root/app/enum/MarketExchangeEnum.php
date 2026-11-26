<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 市场行情供应商枚举
 *
 * 含两类：加密交易所直连，以及第三方聚合商（报价非交易所官方 feed，其静默不可作休市判据）。
 */
enum MarketExchangeEnum: string
{
    use BaseEnum;


    #[Label('火币 Huobi')]
    case HUOBI = 'huobi';

    #[Label('欧易 OKX')]
    case OKX = 'okx';

    #[Label('币安 Binance')]
    case BINANCE = 'binance';

    #[Label('CoinGecko')]
    case COINGECKO = 'coingecko';

    /**
     * 加密交易所，这里只用它的 TradFi USDT 永续（NC 前缀合约，如外汇 NCFXEUR2USD-USDT、取暖油）。
     * 是外汇与燃料油唯一达到可用成交额的交易所来源，见「行情采集源覆盖对照」
     */
    #[Label('BingX')]
    case BINGX = 'bingx';
}
