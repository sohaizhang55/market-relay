<?php

use think\migration\Migrator;

/**
 * market_data 里 alltick 的 symbol 从小写改为 AllTick 原始 code
 *
 * 原始 code 大小写敏感，采集器改为直接拿 symbol 订阅，不再经 MarketRestAlltick::CODE_MAP 还原。
 * 种子是 INSERT IGNORE，存量行不会被新种子改写，只能靠本迁移。
 * source_key 按小写生成，改前改后一致，交易对绑定与 market_source_kline 不受影响。
 */
class UpdateAlltickSymbolToRawCode extends Migrator
{
    private const CODES = [
        '.spx.us'   => '.SPX.US',
        '.dji.us'   => '.DJI.US',
        '.ixic.us'  => '.IXIC.US',
        'hsi.hk'    => 'HSI.HK',
        'hscei.hk'  => 'HSCEI.HK',
        'hstech.hk' => 'HSTECH.HK',
        'gold'      => 'GOLD',
        'silver'    => 'Silver',
        'platinum'  => 'Platinum',
        'palladium' => 'Palladium',
        'copper'    => 'COPPER',
        'usoil'     => 'USOIL',
        'ukoil'     => 'UKOIL',
        'ngas'      => 'NGAS',
    ];

    public function up()
    {
        // 列是 _ci 排序规则，WHERE 不区分大小写，重跑也安全
        foreach (self::CODES as $lower => $code) {
            $this->execute(sprintf(
                "UPDATE `market_data` SET `symbol` = '%s' WHERE `exchange` = 'alltick' AND `symbol` = '%s'",
                $code, $lower
            ));
        }
    }

    public function down()
    {
        $this->execute("UPDATE `market_data` SET `symbol` = LOWER(`symbol`) WHERE `exchange` = 'alltick'");
    }
}
