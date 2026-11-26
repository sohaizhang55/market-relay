-- exchange_pair 交易对默认数据（由 seeds/InitExchangePair.php 导入）
-- source_key 对应 market_data.source_key，决定行情来源；行情由 MarketService 按 source_key 同步到对应 pair
-- type: 1=现货(SPOT)  2=永续合约(CONTRACT)
-- precision_price: 价格小数位  precision_amount: 数量小数位
--
-- ⚠️ mode=produce（Server A）不生成 pair 层（MarketService::sourceOnly()），
--    导入后这批行的价格只会在 mode=direct / dispatch 的机器上更新。

-- 不显式指定 id：INSERT IGNORE 的去重判断依赖 idx_symbol_type (symbol_code, type) 唯一键，
-- 与 id 无关；写死 id 在已运行过一段时间、id 序号已跟别的交易对错位的库上重跑，
-- 反而可能因为主键冲突被误判为"已存在"而漏插入真正的新交易对。
INSERT IGNORE INTO `exchange_pair`
  (`symbol`, `symbol_code`, `type`, `base_coin`, `quote_coin`, `source_key`,
   `precision_price`, `precision_amount`, `sort`, `status`, `create_at`, `update_at`)
VALUES
-- 现货 (type=1) — 18 对
('BTC/USDT',  'btcusdt',  1, 'BTC',  'USDT', 'BINANCE_btcusdt_1',  2, 4, 180, 1, NOW(), NOW()),
('ETH/USDT',  'ethusdt',  1, 'ETH',  'USDT', 'BINANCE_ethusdt_1',  2, 4, 170, 1, NOW(), NOW()),
('SOL/USDT',  'solusdt',  1, 'SOL',  'USDT', 'BINANCE_solusdt_1',  2, 2, 160, 1, NOW(), NOW()),
('BNB/USDT',  'bnbusdt',  1, 'BNB',  'USDT', 'BINANCE_bnbusdt_1',  2, 3, 150, 1, NOW(), NOW()),
('XRP/USDT',  'xrpusdt',  1, 'XRP',  'USDT', 'BINANCE_xrpusdt_1',  4, 1, 140, 1, NOW(), NOW()),
('DOGE/USDT', 'dogeusdt', 1, 'DOGE', 'USDT', 'BINANCE_dogeusdt_1', 5, 0, 130, 1, NOW(), NOW()),
('LTC/USDT',  'ltcusdt',  1, 'LTC',  'USDT', 'BINANCE_ltcusdt_1',  2, 3, 120, 1, NOW(), NOW()),
('ADA/USDT',  'adausdt',  1, 'ADA',  'USDT', 'BINANCE_adausdt_1',  5, 0, 110, 1, NOW(), NOW()),
('AVAX/USDT', 'avaxusdt', 1, 'AVAX', 'USDT', 'BINANCE_avaxusdt_1', 3, 2, 100, 1, NOW(), NOW()),
('DOT/USDT',  'dotusdt',  1, 'DOT',  'USDT', 'BINANCE_dotusdt_1',  4, 1,  90, 1, NOW(), NOW()),
('LINK/USDT', 'linkusdt', 1, 'LINK', 'USDT', 'BINANCE_linkusdt_1', 3, 2,  80, 1, NOW(), NOW()),
('UNI/USDT',  'uniusdt',  1, 'UNI',  'USDT', 'BINANCE_uniusdt_1',  3, 2,  70, 1, NOW(), NOW()),
('ATOM/USDT', 'atomusdt', 1, 'ATOM', 'USDT', 'BINANCE_atomusdt_1', 3, 2,  60, 1, NOW(), NOW()),
('TRX/USDT',  'trxusdt',  1, 'TRX',  'USDT', 'BINANCE_trxusdt_1',  5, 0,  50, 1, NOW(), NOW()),
('SHIB/USDT', 'shibusdt', 1, 'SHIB', 'USDT', 'BINANCE_shibusdt_1', 8, 0,  40, 1, NOW(), NOW()),
('OP/USDT',   'opusdt',   1, 'OP',   'USDT', 'BINANCE_opusdt_1',   4, 1,  30, 1, NOW(), NOW()),
('ARB/USDT',  'arbusdt',  1, 'ARB',  'USDT', 'BINANCE_arbusdt_1',  4, 1,  20, 1, NOW(), NOW()),
('FIL/USDT',  'filusdt',  1, 'FIL',  'USDT', 'BINANCE_filusdt_1',  4, 2,  10, 1, NOW(), NOW()),
-- 永续合约 (type=2) — 7 对
('BTC/USDT',  'btcusdt',  2, 'BTC',  'USDT', 'HUOBI_btcusdt_2',  1, 3, 70, 1, NOW(), NOW()),
('ETH/USDT',  'ethusdt',  2, 'ETH',  'USDT', 'HUOBI_ethusdt_2',  2, 2, 60, 1, NOW(), NOW()),
('SOL/USDT',  'solusdt',  2, 'SOL',  'USDT', 'HUOBI_solusdt_2',  3, 1, 50, 1, NOW(), NOW()),
('BNB/USDT',  'bnbusdt',  2, 'BNB',  'USDT', 'HUOBI_bnbusdt_2',  2, 2, 40, 1, NOW(), NOW()),
('XRP/USDT',  'xrpusdt',  2, 'XRP',  'USDT', 'HUOBI_xrpusdt_2',  4, 0, 30, 1, NOW(), NOW()),
('DOGE/USDT', 'dogeusdt', 2, 'DOGE', 'USDT', 'HUOBI_dogeusdt_2', 5, 0, 20, 1, NOW(), NOW()),
('LTC/USDT',  'ltcusdt',  2, 'LTC',  'USDT', 'HUOBI_ltcusdt_2',  2, 2, 10, 1, NOW(), NOW());

-- 第二次扩充（2026-08-28）：只含 24/7 加密标的
INSERT IGNORE INTO `exchange_pair`
  (`symbol`, `symbol_code`, `type`, `base_coin`, `quote_coin`, `source_key`,
   `precision_price`, `precision_amount`, `sort`, `status`, `create_at`, `update_at`)
VALUES
-- 现货 (type=1) — 25 对；PAXG 现货仅 Binance
('ZEC/USDT', 'zecusdt', 1, 'ZEC', 'USDT', 'BINANCE_zecusdt_1', 2, 3, 0, 1, NOW(), NOW()),
('PENGU/USDT', 'penguusdt', 1, 'PENGU', 'USDT', 'BINANCE_penguusdt_1', 6, 0, 0, 1, NOW(), NOW()),
('PUMP/USDT', 'pumpusdt', 1, 'PUMP', 'USDT', 'BINANCE_pumpusdt_1', 6, 0, 0, 1, NOW(), NOW()),
('SUI/USDT', 'suiusdt', 1, 'SUI', 'USDT', 'BINANCE_suiusdt_1', 4, 1, 0, 1, NOW(), NOW()),
('XLM/USDT', 'xlmusdt', 1, 'XLM', 'USDT', 'BINANCE_xlmusdt_1', 4, 0, 0, 1, NOW(), NOW()),
('HBAR/USDT', 'hbarusdt', 1, 'HBAR', 'USDT', 'BINANCE_hbarusdt_1', 5, 0, 0, 1, NOW(), NOW()),
('BCH/USDT', 'bchusdt', 1, 'BCH', 'USDT', 'BINANCE_bchusdt_1', 1, 3, 0, 1, NOW(), NOW()),
('PEPE/USDT', 'pepeusdt', 1, 'PEPE', 'USDT', 'BINANCE_pepeusdt_1', 8, 0, 0, 1, NOW(), NOW()),
('NEAR/USDT', 'nearusdt', 1, 'NEAR', 'USDT', 'BINANCE_nearusdt_1', 3, 1, 0, 1, NOW(), NOW()),
('ICP/USDT', 'icpusdt', 1, 'ICP', 'USDT', 'BINANCE_icpusdt_1', 3, 2, 0, 1, NOW(), NOW()),
('APT/USDT', 'aptusdt', 1, 'APT', 'USDT', 'BINANCE_aptusdt_1', 3, 2, 0, 1, NOW(), NOW()),
('ENA/USDT', 'enausdt', 1, 'ENA', 'USDT', 'BINANCE_enausdt_1', 4, 2, 0, 1, NOW(), NOW()),
('ALGO/USDT', 'algousdt', 1, 'ALGO', 'USDT', 'BINANCE_algousdt_1', 4, 0, 0, 1, NOW(), NOW()),
('TIA/USDT', 'tiausdt', 1, 'TIA', 'USDT', 'BINANCE_tiausdt_1', 4, 2, 0, 1, NOW(), NOW()),
('JUP/USDT', 'jupusdt', 1, 'JUP', 'USDT', 'BINANCE_jupusdt_1', 4, 1, 0, 1, NOW(), NOW()),
('WLD/USDT', 'wldusdt', 1, 'WLD', 'USDT', 'BINANCE_wldusdt_1', 4, 1, 0, 1, NOW(), NOW()),
('ONDO/USDT', 'ondousdt', 1, 'ONDO', 'USDT', 'BINANCE_ondousdt_1', 4, 1, 0, 1, NOW(), NOW()),
('SYRUP/USDT', 'syrupusdt', 1, 'SYRUP', 'USDT', 'BINANCE_syrupusdt_1', 4, 1, 0, 1, NOW(), NOW()),
('RSR/USDT', 'rsrusdt', 1, 'RSR', 'USDT', 'BINANCE_rsrusdt_1', 6, 1, 0, 1, NOW(), NOW()),
('PLUME/USDT', 'plumeusdt', 1, 'PLUME', 'USDT', 'BINANCE_plumeusdt_1', 5, 0, 0, 1, NOW(), NOW()),
('CFG/USDT', 'cfgusdt', 1, 'CFG', 'USDT', 'BINANCE_cfgusdt_1', 4, 1, 0, 1, NOW(), NOW()),
('DUSK/USDT', 'duskusdt', 1, 'DUSK', 'USDT', 'BINANCE_duskusdt_1', 4, 0, 0, 1, NOW(), NOW()),
('MANTRA/USDT', 'mantrausdt', 1, 'MANTRA', 'USDT', 'BINANCE_mantrausdt_1', 5, 0, 0, 1, NOW(), NOW()),
('XAUT/USDT', 'xautusdt', 1, 'XAUT', 'USDT', 'BINANCE_xautusdt_1', 2, 4, 0, 1, NOW(), NOW()),
('PAXG/USDT', 'paxgusdt', 1, 'PAXG', 'USDT', 'BINANCE_paxgusdt_1', 2, 4, 0, 1, NOW(), NOW()),
-- 永续合约 (type=2) — 24 对；SYRUP/RSR/PLUME/CFG/DUSK/MANTRA Huobi 无永续，合约单源 BINANCE
('ZEC/USDT', 'zecusdt', 2, 'ZEC', 'USDT', 'HUOBI_zecusdt_2', 2, 2, 0, 1, NOW(), NOW()),
('PENGU/USDT', 'penguusdt', 2, 'PENGU', 'USDT', 'HUOBI_penguusdt_2', 6, 0, 0, 1, NOW(), NOW()),
('PUMP/USDT', 'pumpusdt', 2, 'PUMP', 'USDT', 'HUOBI_pumpusdt_2', 6, 0, 0, 1, NOW(), NOW()),
('SUI/USDT', 'suiusdt', 2, 'SUI', 'USDT', 'HUOBI_suiusdt_2', 4, 0, 0, 1, NOW(), NOW()),
('XLM/USDT', 'xlmusdt', 2, 'XLM', 'USDT', 'HUOBI_xlmusdt_2', 5, 0, 0, 1, NOW(), NOW()),
('HBAR/USDT', 'hbarusdt', 2, 'HBAR', 'USDT', 'HUOBI_hbarusdt_2', 5, 0, 0, 1, NOW(), NOW()),
('BCH/USDT', 'bchusdt', 2, 'BCH', 'USDT', 'HUOBI_bchusdt_2', 2, 2, 0, 1, NOW(), NOW()),
('NEAR/USDT', 'nearusdt', 2, 'NEAR', 'USDT', 'HUOBI_nearusdt_2', 3, 0, 0, 1, NOW(), NOW()),
('ICP/USDT', 'icpusdt', 2, 'ICP', 'USDT', 'HUOBI_icpusdt_2', 3, 1, 0, 1, NOW(), NOW()),
('APT/USDT', 'aptusdt', 2, 'APT', 'USDT', 'HUOBI_aptusdt_2', 4, 1, 0, 1, NOW(), NOW()),
('ENA/USDT', 'enausdt', 2, 'ENA', 'USDT', 'HUOBI_enausdt_2', 5, 0, 0, 1, NOW(), NOW()),
('ALGO/USDT', 'algousdt', 2, 'ALGO', 'USDT', 'HUOBI_algousdt_2', 5, 0, 0, 1, NOW(), NOW()),
('TIA/USDT', 'tiausdt', 2, 'TIA', 'USDT', 'HUOBI_tiausdt_2', 4, 1, 0, 1, NOW(), NOW()),
('JUP/USDT', 'jupusdt', 2, 'JUP', 'USDT', 'HUOBI_jupusdt_2', 4, 0, 0, 1, NOW(), NOW()),
('WLD/USDT', 'wldusdt', 2, 'WLD', 'USDT', 'HUOBI_wldusdt_2', 4, 0, 0, 1, NOW(), NOW()),
('ONDO/USDT', 'ondousdt', 2, 'ONDO', 'USDT', 'HUOBI_ondousdt_2', 4, 0, 0, 1, NOW(), NOW()),
('SYRUP/USDT', 'syrupusdt', 2, 'SYRUP', 'USDT', 'BINANCE_syrupusdt_2', 5, 0, 0, 1, NOW(), NOW()),
('RSR/USDT', 'rsrusdt', 2, 'RSR', 'USDT', 'BINANCE_rsrusdt_2', 6, 0, 0, 1, NOW(), NOW()),
('PLUME/USDT', 'plumeusdt', 2, 'PLUME', 'USDT', 'BINANCE_plumeusdt_2', 5, 0, 0, 1, NOW(), NOW()),
('CFG/USDT', 'cfgusdt', 2, 'CFG', 'USDT', 'BINANCE_cfgusdt_2', 4, 0, 0, 1, NOW(), NOW()),
('DUSK/USDT', 'duskusdt', 2, 'DUSK', 'USDT', 'BINANCE_duskusdt_2', 5, 0, 0, 1, NOW(), NOW()),
('MANTRA/USDT', 'mantrausdt', 2, 'MANTRA', 'USDT', 'BINANCE_mantrausdt_2', 6, 0, 0, 1, NOW(), NOW()),
('XAUT/USDT', 'xautusdt', 2, 'XAUT', 'USDT', 'HUOBI_xautusdt_2', 2, 3, 0, 1, NOW(), NOW()),
('PAXG/USDT', 'paxgusdt', 2, 'PAXG', 'USDT', 'HUOBI_paxgusdt_2', 2, 3, 0, 1, NOW(), NOW());

-- 外汇与燃料油（2026-10-20，BingX 永续源）：按现货口径提供（type 1），价格精度取 BingX 合约的 pricePrecision。
-- pair 编码见「采集源接入方案」的品种编码一节；取暖油沿用 BingX 报价单位（见 market_data.sql 同段注释）。
INSERT IGNORE INTO `exchange_pair`
  (`symbol`, `symbol_code`, `type`, `base_coin`, `quote_coin`, `source_key`,
   `precision_price`, `precision_amount`, `sort`, `status`, `create_at`, `update_at`)
VALUES
('EUR/USD', 'eurusd', 1, 'EUR', 'USD', 'BINGX_ncfxeur2usdusdt_2', 5, 2, 0, 1, NOW(), NOW()),
('EUR/GBP', 'eurgbp', 1, 'EUR', 'GBP', 'BINGX_ncfxeur2gbpusdt_2', 5, 2, 0, 1, NOW(), NOW()),
('EUR/CHF', 'eurchf', 1, 'EUR', 'CHF', 'BINGX_ncfxeur2chfusdt_2', 5, 2, 0, 1, NOW(), NOW()),
('USD/SGD', 'usdsgd', 1, 'USD', 'SGD', 'BINGX_ncfxusdsgd2usdusdt_2', 5, 2, 0, 1, NOW(), NOW()),
('GBP/USD', 'gbpusd', 1, 'GBP', 'USD', 'BINGX_ncfxgbp2usdusdt_2', 5, 2, 0, 1, NOW(), NOW()),
('USD/JPY', 'usdjpy', 1, 'USD', 'JPY', 'BINGX_ncfxusd2jpyusdt_2', 3, 2, 0, 1, NOW(), NOW()),
('AUD/USD', 'audusd', 1, 'AUD', 'USD', 'BINGX_ncfxaud2usdusdt_2', 5, 2, 0, 1, NOW(), NOW()),
('USD/CHF', 'usdchf', 1, 'USD', 'CHF', 'BINGX_ncfxusd2chfusdt_2', 5, 2, 0, 1, NOW(), NOW()),
('USD/CAD', 'usdcad', 1, 'USD', 'CAD', 'BINGX_ncfxusd2cadusdt_2', 5, 2, 0, 1, NOW(), NOW()),
('HEATINGOIL/USD', 'heatingoil', 1, 'HEATINGOIL', 'USD', 'BINGX_nccoheatingoil2usdusdt_2', 1, 2, 0, 1, NOW(), NOW());

-- 去掉 AllTick 后由币安 / 火币接手的品种（2026-10-20）：编码见「采集源接入方案」的品种编码一节，类型统一 1。
-- 数字货币精度取主源现货的 tickSize / stepSize（币安经 data-api.binance.vision、火币经 v1/common/symbols）；
-- 金属能源源是币安永续，按现货口径提供，价格精度取 1m K 线实际用到的小数位，数量精度取成交量小数位。
INSERT IGNORE INTO `exchange_pair`
  (`symbol`, `symbol_code`, `type`, `base_coin`, `quote_coin`, `source_key`,
   `precision_price`, `precision_amount`, `sort`, `status`, `create_at`, `update_at`)
VALUES
('ETC/USDT',   'etcusdt',   1, 'ETC',   'USDT', 'BINANCE_etcusdt_1',    2, 2, 0, 1, NOW(), NOW()),
('VET/USDT',   'vetusdt',   1, 'VET',   'USDT', 'BINANCE_vetusdt_1',    6, 1, 0, 1, NOW(), NOW()),
('XTZ/USDT',   'xtzusdt',   1, 'XTZ',   'USDT', 'BINANCE_xtzusdt_1',    4, 1, 0, 1, NOW(), NOW()),
('QTUM/USDT',  'qtumusdt',  1, 'QTUM',  'USDT', 'BINANCE_qtumusdt_1',   3, 1, 0, 1, NOW(), NOW()),
('HTX/USDT',   'htxusdt',   1, 'HTX',   'USDT', 'HUOBI_htxusdt_1',      9, 2, 0, 1, NOW(), NOW()),
('WAVES/USDT', 'wavesusdt', 1, 'WAVES', 'USDT', 'HUOBI_wavesusdt_1',    4, 2, 0, 1, NOW(), NOW()),
('XAU/USD',    'xauusd',    1, 'XAU',   'USD',  'BINANCE_xauusdt_2',    2, 3, 0, 1, NOW(), NOW()),
('XAG/USD',    'xagusd',    1, 'XAG',   'USD',  'BINANCE_xagusdt_2',    2, 3, 0, 1, NOW(), NOW()),
('XPT/USD',    'xptusd',    1, 'XPT',   'USD',  'BINANCE_xptusdt_2',    2, 3, 0, 1, NOW(), NOW()),
('XPD/USD',    'xpdusd',    1, 'XPD',   'USD',  'BINANCE_xpdusdt_2',    2, 3, 0, 1, NOW(), NOW()),
('WTI/USD',    'wtiusd',    1, 'WTI',   'USD',  'BINANCE_clusdt_2',     2, 2, 0, 1, NOW(), NOW()),
('NGAS/USD',   'ngasusd',   1, 'NGAS',  'USD',  'BINANCE_natgasusdt_2', 3, 1, 0, 1, NOW(), NOW());

-- ═══════════════════════════════════════════════════════════════════════════
-- 数据源绑定 exchange_pair_source（主备切换，2026-10-21）
--
-- 绑定是配置（人在后台改），exchange_pair.source_key 是状态（当前生效源，选源进程维护；
-- 主备切换上线前恒等于主源）。上面各段照写主源，下面按规则生成绑定，与迁移
-- 20261021120000_create_exchange_pair_source 是同一组语句：
--   主源  priority=1，取 exchange_pair.source_key
--   备源  priority=2，火币 / 币安之间同 symbol 同类型自动配对（换交易所前缀；对方在 market_data 里有才配）
-- 依赖 market_data 已导入（deploy/init.sh 里 InitMarketData 先于本文件）。唯一键 (pair_id, source_key) 挡重复，重跑安全。
-- 交易日历只挂在源上（market_data.calendar_code），pair 的开休市跟当前生效源走。
INSERT IGNORE INTO `exchange_pair_source` (`pair_id`, `source_key`, `priority`, `status`, `create_ts`, `update_ts`)
SELECT `id`, `source_key`, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM `exchange_pair`
WHERE `source_key` IS NOT NULL AND `source_key` <> '';

INSERT IGNORE INTO `exchange_pair_source` (`pair_id`, `source_key`, `priority`, `status`, `create_ts`, `update_ts`)
SELECT p.`id`, m.`source_key`, 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `exchange_pair` p JOIN `market_data` m ON m.`source_key` = CONCAT('HUOBI_', SUBSTRING(p.`source_key`, 9))
WHERE p.`source_key` LIKE 'BINANCE\_%';

INSERT IGNORE INTO `exchange_pair_source` (`pair_id`, `source_key`, `priority`, `status`, `create_ts`, `update_ts`)
SELECT p.`id`, m.`source_key`, 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `exchange_pair` p JOIN `market_data` m ON m.`source_key` = CONCAT('BINANCE_', SUBSTRING(p.`source_key`, 7))
WHERE p.`source_key` LIKE 'HUOBI\_%';
