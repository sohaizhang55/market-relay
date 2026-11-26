-- type 1:现货  2:合约
-- source_key 格式: UPPER(EXCHANGE) + '_' + LOWER(SYMBOL_CLEAN) + '_' + TYPE
-- SYMBOL_CLEAN: 移除所有横杠、下划线、斜杠
-- 示例: HUOBI_btcusdt_1, BINANCE_btcusdt_2
-- category_code: 行情源的品类，取值见 coin_category.code，默认 crypto。
--   火币/币安上的代币（XAUT、TSLAX 也是）一律 crypto；
--   币安、BingX 的 TradFi 永续（XAUUSDT、CLUSDT 等直接跟踪商品价格，不是代币）按商品记 metal/energy/forex。
--   与 coin_list.category_code 口径不同：那边按代币挂钩的资产分类。

-- INSERT IGNORE 而不是 REPLACE，且不写死 id：
--   · REPLACE 是 DELETE+INSERT，未列出的列会全部回落默认值 —— open/close/high/low/vol/amount/
--     update_ts/mark_price/mark_ts/funding_rate/funding_rate_ts 一律归零。在跑着的库上重跑
--     等于把全部实时行情清零：getPrice() 按 stale_seconds 全线返回 null，下单/强平/结算连锁拒绝，
--     funding_rate_ts 归零还会让 contract:funding 按 3600s 新鲜度判定整期跳过收费。
--   · 写死 id 在 id 序号已错位的库上会误伤别的行。
-- 去重靠 idx_exchange_symbol_type (exchange,symbol,type) 与 source_key 两个唯一键，与 id 无关。
-- 空库上 INSERT IGNORE 与 REPLACE 等价，所以装机行为不变，但存量库重跑从此是安全的。
INSERT IGNORE INTO `market_data` (`exchange`, `symbol`, `type`, `source_key`, `create_at`, `update_at`) VALUES
-- 火币现货 Top 20 热门 (Type 1)
('huobi', 'btcusdt',   1, 'HUOBI_btcusdt_1',   NOW(), NOW()),
('huobi', 'ethusdt',   1, 'HUOBI_ethusdt_1',   NOW(), NOW()),
('huobi', 'solusdt',   1, 'HUOBI_solusdt_1',   NOW(), NOW()),
('huobi', 'bnbusdt',   1, 'HUOBI_bnbusdt_1',   NOW(), NOW()),
('huobi', 'dogeusdt',  1, 'HUOBI_dogeusdt_1',  NOW(), NOW()),
('huobi', 'xrpusdt',   1, 'HUOBI_xrpusdt_1',   NOW(), NOW()),
('huobi', 'ltcusdt',   1, 'HUOBI_ltcusdt_1',   NOW(), NOW()),
('huobi', 'adausdt',   1, 'HUOBI_adausdt_1',   NOW(), NOW()),
('huobi', 'avaxusdt',  1, 'HUOBI_avaxusdt_1',  NOW(), NOW()),
('huobi', 'dotusdt',   1, 'HUOBI_dotusdt_1',   NOW(), NOW()),
('huobi', 'linkusdt',  1, 'HUOBI_linkusdt_1',  NOW(), NOW()),
('huobi', 'uniusdt',   1, 'HUOBI_uniusdt_1',   NOW(), NOW()),
('huobi', 'atomusdt',  1, 'HUOBI_atomusdt_1',  NOW(), NOW()),
('huobi', 'trxusdt',   1, 'HUOBI_trxusdt_1',   NOW(), NOW()),
('huobi', 'shibusdt',  1, 'HUOBI_shibusdt_1',  NOW(), NOW()),
('huobi', 'pepeusdt',  1, 'HUOBI_pepeusdt_1',  NOW(), NOW()),
('huobi', 'opusdt',    1, 'HUOBI_opusdt_1',    NOW(), NOW()),
('huobi', 'arbusdt',   1, 'HUOBI_arbusdt_1',   NOW(), NOW()),
('huobi', 'filusdt',   1, 'HUOBI_filusdt_1',   NOW(), NOW()),

-- 火币合约 (Type 2)
('huobi', 'BTC-USDT', 2, 'HUOBI_btcusdt_2', NOW(), NOW()),
('huobi', 'ETH-USDT', 2, 'HUOBI_ethusdt_2', NOW(), NOW()),
('huobi', 'SOL-USDT', 2, 'HUOBI_solusdt_2', NOW(), NOW()),
('huobi', 'BNB-USDT', 2, 'HUOBI_bnbusdt_2', NOW(), NOW()),
('huobi', 'DOGE-USDT', 2, 'HUOBI_dogeusdt_2', NOW(), NOW()),
('huobi', 'XRP-USDT', 2, 'HUOBI_xrpusdt_2', NOW(), NOW()),
('huobi', 'LTC-USDT', 2, 'HUOBI_ltcusdt_2', NOW(), NOW()),

-- 币安现货 Top 20 热门 (Type 1)
('binance', 'btcusdt',   1, 'BINANCE_btcusdt_1',   NOW(), NOW()),
('binance', 'ethusdt',   1, 'BINANCE_ethusdt_1',   NOW(), NOW()),
('binance', 'solusdt',   1, 'BINANCE_solusdt_1',   NOW(), NOW()),
('binance', 'bnbusdt',   1, 'BINANCE_bnbusdt_1',   NOW(), NOW()),
('binance', 'dogeusdt',  1, 'BINANCE_dogeusdt_1',  NOW(), NOW()),
('binance', 'xrpusdt',   1, 'BINANCE_xrpusdt_1',   NOW(), NOW()),
('binance', 'ltcusdt',   1, 'BINANCE_ltcusdt_1',   NOW(), NOW()),
('binance', 'adausdt',   1, 'BINANCE_adausdt_1',   NOW(), NOW()),
('binance', 'avaxusdt',  1, 'BINANCE_avaxusdt_1',  NOW(), NOW()),
('binance', 'dotusdt',   1, 'BINANCE_dotusdt_1',   NOW(), NOW()),
('binance', 'linkusdt',  1, 'BINANCE_linkusdt_1',  NOW(), NOW()),
('binance', 'uniusdt',   1, 'BINANCE_uniusdt_1',   NOW(), NOW()),
('binance', 'atomusdt',  1, 'BINANCE_atomusdt_1',  NOW(), NOW()),
('binance', 'trxusdt',   1, 'BINANCE_trxusdt_1',   NOW(), NOW()),
('binance', 'shibusdt',  1, 'BINANCE_shibusdt_1',  NOW(), NOW()),
('binance', 'opusdt',    1, 'BINANCE_opusdt_1',    NOW(), NOW()),
('binance', 'arbusdt',   1, 'BINANCE_arbusdt_1',   NOW(), NOW()),
('binance', 'filusdt',   1, 'BINANCE_filusdt_1',   NOW(), NOW()),

-- 币安合约 (Type 2)
('binance', 'btcusdt',  2, 'BINANCE_btcusdt_2',  NOW(), NOW()),
('binance', 'ethusdt',  2, 'BINANCE_ethusdt_2',  NOW(), NOW()),
('binance', 'solusdt',  2, 'BINANCE_solusdt_2',  NOW(), NOW()),
('binance', 'bnbusdt',  2, 'BINANCE_bnbusdt_2',  NOW(), NOW()),
('binance', 'dogeusdt', 2, 'BINANCE_dogeusdt_2', NOW(), NOW()),
('binance', 'xrpusdt',  2, 'BINANCE_xrpusdt_2',  NOW(), NOW()),
('binance', 'ltcusdt',  2, 'BINANCE_ltcusdt_2',  NOW(), NOW());

-- 第二次扩充（2026-08-28）：100 行，只含 24/7 加密标的；PEPE 的火币现货行已存在，靠唯一键跳过
-- coin_list 里另有 34 个传统市场标的（coin_list.sql 里 status=2 那批）刻意不给源：采集面完全由本表决定，
-- 不给源，币安/火币采集器与 dispatcher 就不会碰它们。那批的源随第二阶段与交易对一起补。
-- 与上半段同一套写法（INSERT IGNORE、不写死 id），整份文件重跑安全。
INSERT IGNORE INTO `market_data` (`exchange`, `symbol`, `type`, `source_key`, `create_at`, `update_at`) VALUES
-- 火币现货 (Type 1)
('huobi', 'zecusdt', 1, 'HUOBI_zecusdt_1', NOW(), NOW()),
('huobi', 'penguusdt', 1, 'HUOBI_penguusdt_1', NOW(), NOW()),
('huobi', 'pumpusdt', 1, 'HUOBI_pumpusdt_1', NOW(), NOW()),
('huobi', 'suiusdt', 1, 'HUOBI_suiusdt_1', NOW(), NOW()),
('huobi', 'xlmusdt', 1, 'HUOBI_xlmusdt_1', NOW(), NOW()),
('huobi', 'hbarusdt', 1, 'HUOBI_hbarusdt_1', NOW(), NOW()),
('huobi', 'bchusdt', 1, 'HUOBI_bchusdt_1', NOW(), NOW()),
('huobi', 'nearusdt', 1, 'HUOBI_nearusdt_1', NOW(), NOW()),
('huobi', 'icpusdt', 1, 'HUOBI_icpusdt_1', NOW(), NOW()),
('huobi', 'aptusdt', 1, 'HUOBI_aptusdt_1', NOW(), NOW()),
('huobi', 'enausdt', 1, 'HUOBI_enausdt_1', NOW(), NOW()),
('huobi', 'algousdt', 1, 'HUOBI_algousdt_1', NOW(), NOW()),
('huobi', 'tiausdt', 1, 'HUOBI_tiausdt_1', NOW(), NOW()),
('huobi', 'jupusdt', 1, 'HUOBI_jupusdt_1', NOW(), NOW()),
('huobi', 'wldusdt', 1, 'HUOBI_wldusdt_1', NOW(), NOW()),
('huobi', 'ondousdt', 1, 'HUOBI_ondousdt_1', NOW(), NOW()),
('huobi', 'syrupusdt', 1, 'HUOBI_syrupusdt_1', NOW(), NOW()),
('huobi', 'rsrusdt', 1, 'HUOBI_rsrusdt_1', NOW(), NOW()),
('huobi', 'plumeusdt', 1, 'HUOBI_plumeusdt_1', NOW(), NOW()),
('huobi', 'cfgusdt', 1, 'HUOBI_cfgusdt_1', NOW(), NOW()),
('huobi', 'duskusdt', 1, 'HUOBI_duskusdt_1', NOW(), NOW()),
('huobi', 'mantrausdt', 1, 'HUOBI_mantrausdt_1', NOW(), NOW()),
('huobi', 'xautusdt', 1, 'HUOBI_xautusdt_1', NOW(), NOW()),
-- 火币合约 (Type 2)
('huobi', 'ZEC-USDT', 2, 'HUOBI_zecusdt_2', NOW(), NOW()),
('huobi', 'PENGU-USDT', 2, 'HUOBI_penguusdt_2', NOW(), NOW()),
('huobi', 'PUMP-USDT', 2, 'HUOBI_pumpusdt_2', NOW(), NOW()),
('huobi', 'SUI-USDT', 2, 'HUOBI_suiusdt_2', NOW(), NOW()),
('huobi', 'XLM-USDT', 2, 'HUOBI_xlmusdt_2', NOW(), NOW()),
('huobi', 'HBAR-USDT', 2, 'HUOBI_hbarusdt_2', NOW(), NOW()),
('huobi', 'BCH-USDT', 2, 'HUOBI_bchusdt_2', NOW(), NOW()),
('huobi', 'NEAR-USDT', 2, 'HUOBI_nearusdt_2', NOW(), NOW()),
('huobi', 'ICP-USDT', 2, 'HUOBI_icpusdt_2', NOW(), NOW()),
('huobi', 'APT-USDT', 2, 'HUOBI_aptusdt_2', NOW(), NOW()),
('huobi', 'ENA-USDT', 2, 'HUOBI_enausdt_2', NOW(), NOW()),
('huobi', 'ALGO-USDT', 2, 'HUOBI_algousdt_2', NOW(), NOW()),
('huobi', 'TIA-USDT', 2, 'HUOBI_tiausdt_2', NOW(), NOW()),
('huobi', 'JUP-USDT', 2, 'HUOBI_jupusdt_2', NOW(), NOW()),
('huobi', 'WLD-USDT', 2, 'HUOBI_wldusdt_2', NOW(), NOW()),
('huobi', 'ONDO-USDT', 2, 'HUOBI_ondousdt_2', NOW(), NOW()),
('huobi', 'XAUT-USDT', 2, 'HUOBI_xautusdt_2', NOW(), NOW()),
('huobi', 'USOIL-USDT', 2, 'HUOBI_usoilusdt_2', NOW(), NOW()),
('huobi', 'BRENTOIL-USDT', 2, 'HUOBI_brentoilusdt_2', NOW(), NOW()),
('huobi', 'PAXG-USDT', 2, 'HUOBI_paxgusdt_2', NOW(), NOW()),
('huobi', 'TSLAX-USDT', 2, 'HUOBI_tslaxusdt_2', NOW(), NOW()),
('huobi', 'AMZNX-USDT', 2, 'HUOBI_amznxusdt_2', NOW(), NOW()),
('huobi', 'INTCX-USDT', 2, 'HUOBI_intcxusdt_2', NOW(), NOW()),
('huobi', 'MSTRX-USDT', 2, 'HUOBI_mstrxusdt_2', NOW(), NOW()),
('huobi', 'COINX-USDT', 2, 'HUOBI_coinxusdt_2', NOW(), NOW()),
('huobi', 'HOODX-USDT', 2, 'HUOBI_hoodxusdt_2', NOW(), NOW()),
('huobi', 'CRCLX-USDT', 2, 'HUOBI_crclxusdt_2', NOW(), NOW()),
('huobi', 'PLTRX-USDT', 2, 'HUOBI_pltrxusdt_2', NOW(), NOW()),
-- 币安现货 (Type 1)
('binance', 'zecusdt', 1, 'BINANCE_zecusdt_1', NOW(), NOW()),
('binance', 'penguusdt', 1, 'BINANCE_penguusdt_1', NOW(), NOW()),
('binance', 'pumpusdt', 1, 'BINANCE_pumpusdt_1', NOW(), NOW()),
('binance', 'suiusdt', 1, 'BINANCE_suiusdt_1', NOW(), NOW()),
('binance', 'xlmusdt', 1, 'BINANCE_xlmusdt_1', NOW(), NOW()),
('binance', 'hbarusdt', 1, 'BINANCE_hbarusdt_1', NOW(), NOW()),
('binance', 'bchusdt', 1, 'BINANCE_bchusdt_1', NOW(), NOW()),
('binance', 'pepeusdt', 1, 'BINANCE_pepeusdt_1', NOW(), NOW()),
('binance', 'nearusdt', 1, 'BINANCE_nearusdt_1', NOW(), NOW()),
('binance', 'icpusdt', 1, 'BINANCE_icpusdt_1', NOW(), NOW()),
('binance', 'aptusdt', 1, 'BINANCE_aptusdt_1', NOW(), NOW()),
('binance', 'enausdt', 1, 'BINANCE_enausdt_1', NOW(), NOW()),
('binance', 'algousdt', 1, 'BINANCE_algousdt_1', NOW(), NOW()),
('binance', 'tiausdt', 1, 'BINANCE_tiausdt_1', NOW(), NOW()),
('binance', 'jupusdt', 1, 'BINANCE_jupusdt_1', NOW(), NOW()),
('binance', 'wldusdt', 1, 'BINANCE_wldusdt_1', NOW(), NOW()),
('binance', 'ondousdt', 1, 'BINANCE_ondousdt_1', NOW(), NOW()),
('binance', 'syrupusdt', 1, 'BINANCE_syrupusdt_1', NOW(), NOW()),
('binance', 'rsrusdt', 1, 'BINANCE_rsrusdt_1', NOW(), NOW()),
('binance', 'plumeusdt', 1, 'BINANCE_plumeusdt_1', NOW(), NOW()),
('binance', 'cfgusdt', 1, 'BINANCE_cfgusdt_1', NOW(), NOW()),
('binance', 'duskusdt', 1, 'BINANCE_duskusdt_1', NOW(), NOW()),
('binance', 'mantrausdt', 1, 'BINANCE_mantrausdt_1', NOW(), NOW()),
('binance', 'xautusdt', 1, 'BINANCE_xautusdt_1', NOW(), NOW()),
('binance', 'paxgusdt', 1, 'BINANCE_paxgusdt_1', NOW(), NOW()),
-- 币安合约 (Type 2)
('binance', 'zecusdt', 2, 'BINANCE_zecusdt_2', NOW(), NOW()),
('binance', 'penguusdt', 2, 'BINANCE_penguusdt_2', NOW(), NOW()),
('binance', 'pumpusdt', 2, 'BINANCE_pumpusdt_2', NOW(), NOW()),
('binance', 'suiusdt', 2, 'BINANCE_suiusdt_2', NOW(), NOW()),
('binance', 'xlmusdt', 2, 'BINANCE_xlmusdt_2', NOW(), NOW()),
('binance', 'hbarusdt', 2, 'BINANCE_hbarusdt_2', NOW(), NOW()),
('binance', 'bchusdt', 2, 'BINANCE_bchusdt_2', NOW(), NOW()),
('binance', 'nearusdt', 2, 'BINANCE_nearusdt_2', NOW(), NOW()),
('binance', 'icpusdt', 2, 'BINANCE_icpusdt_2', NOW(), NOW()),
('binance', 'aptusdt', 2, 'BINANCE_aptusdt_2', NOW(), NOW()),
('binance', 'enausdt', 2, 'BINANCE_enausdt_2', NOW(), NOW()),
('binance', 'algousdt', 2, 'BINANCE_algousdt_2', NOW(), NOW()),
('binance', 'tiausdt', 2, 'BINANCE_tiausdt_2', NOW(), NOW()),
('binance', 'jupusdt', 2, 'BINANCE_jupusdt_2', NOW(), NOW()),
('binance', 'wldusdt', 2, 'BINANCE_wldusdt_2', NOW(), NOW()),
('binance', 'ondousdt', 2, 'BINANCE_ondousdt_2', NOW(), NOW()),
('binance', 'syrupusdt', 2, 'BINANCE_syrupusdt_2', NOW(), NOW()),
('binance', 'rsrusdt', 2, 'BINANCE_rsrusdt_2', NOW(), NOW()),
('binance', 'plumeusdt', 2, 'BINANCE_plumeusdt_2', NOW(), NOW()),
('binance', 'cfgusdt', 2, 'BINANCE_cfgusdt_2', NOW(), NOW()),
('binance', 'duskusdt', 2, 'BINANCE_duskusdt_2', NOW(), NOW()),
('binance', 'mantrausdt', 2, 'BINANCE_mantrausdt_2', NOW(), NOW()),
('binance', 'xautusdt', 2, 'BINANCE_xautusdt_2', NOW(), NOW()),
('binance', 'paxgusdt', 2, 'BINANCE_paxgusdt_2', NOW(), NOW());

-- ═══════════════════════════════════════════════════════════════════════════
-- BingX USDT 永续：外汇与燃料油的唯一可用交易所来源（见「行情采集源覆盖对照」，2026-10-20 接入测试）
-- symbol 照抄 BingX 合约代码（NC 前缀 TradFi 合约）；USDSGD 的代码格式与其他不同（NCFXUSDSGD2USD），实测报价为正向 USDSGD。
-- calendar_code 用 BingX 手写日历（MarketCalendarEnum 的 BingX 组）：外汇 BINGX_FX、取暖油 BINGX_ENERGY。
-- 【不要】改成空串 —— 空串等于 24/7，周末会判开市，订阅方会按冻结价格放行下单。
-- 存量库靠迁移 20261021100000_update_bingx_calendar_code 补（本段是 INSERT IGNORE，改不到已有行）。
-- 取暖油报价为常见报价（美元 / 加仑）的 10,000 倍，原样采集，单位是否换算待定。
INSERT IGNORE INTO `market_data` (`exchange`, `symbol`, `type`, `source_key`, `calendar_code`, `category_code`, `create_at`, `update_at`) VALUES
('bingx', 'NCFXEUR2USD-USDT', 2, 'BINGX_ncfxeur2usdusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCFXEUR2GBP-USDT', 2, 'BINGX_ncfxeur2gbpusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCFXEUR2CHF-USDT', 2, 'BINGX_ncfxeur2chfusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCFXUSDSGD2USD-USDT', 2, 'BINGX_ncfxusdsgd2usdusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCFXGBP2USD-USDT', 2, 'BINGX_ncfxgbp2usdusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCFXUSD2JPY-USDT', 2, 'BINGX_ncfxusd2jpyusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCFXAUD2USD-USDT', 2, 'BINGX_ncfxaud2usdusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCFXUSD2CHF-USDT', 2, 'BINGX_ncfxusd2chfusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCFXUSD2CAD-USDT', 2, 'BINGX_ncfxusd2cadusdt_2', 'BINGX_FX', 'forex', NOW(), NOW()),
('bingx', 'NCCOHEATINGOIL2USD-USDT', 2, 'BINGX_nccoheatingoil2usdusdt_2', 'BINGX_ENERGY', 'energy', NOW(), NOW());

-- ═══════════════════════════════════════════════════════════════════════════
-- 去掉 AllTick 后由币安 / 火币接手的品种（2026-10-20，见「采集源接入方案」品种明细）
--
-- 数字货币按现货采（服务器原来取的就是火币现货）：etc、vet、xtz、qtum 币安成交最大；
-- htx（原 HT，火币平台币）只有火币有；waves 币安已下架，用火币。
--
-- 金属能源用币安 USDT 永续（TradFi 合约，symbol 经 data.binance.vision 核对存在）：
-- 实测周六、周日照常成交（2026-10-17/18 日 K 有量），所以 calendar_code 留空 = 24/7 是对的，
-- 与 BingX 的 TradFi 合约（有周末休市，见上段）不同。
INSERT IGNORE INTO `market_data` (`exchange`, `symbol`, `type`, `source_key`, `calendar_code`, `category_code`, `create_at`, `update_at`) VALUES
('binance', 'etcusdt',    1, 'BINANCE_etcusdt_1',    '', 'crypto', NOW(), NOW()),
('binance', 'vetusdt',    1, 'BINANCE_vetusdt_1',    '', 'crypto', NOW(), NOW()),
('binance', 'xtzusdt',    1, 'BINANCE_xtzusdt_1',    '', 'crypto', NOW(), NOW()),
('binance', 'qtumusdt',   1, 'BINANCE_qtumusdt_1',   '', 'crypto', NOW(), NOW()),
('huobi',   'htxusdt',    1, 'HUOBI_htxusdt_1',      '', 'crypto', NOW(), NOW()),
('huobi',   'wavesusdt',  1, 'HUOBI_wavesusdt_1',    '', 'crypto', NOW(), NOW()),
-- 备源（主备切换用，exchange_pair.backup_source_key）：火币 vet 2.2M、xtz 4.7M；etc、qtum 火币成交太小不作备源
('huobi',   'vetusdt',    1, 'HUOBI_vetusdt_1',      '', 'crypto', NOW(), NOW()),
('huobi',   'xtzusdt',    1, 'HUOBI_xtzusdt_1',      '', 'crypto', NOW(), NOW()),
('binance', 'xauusdt',    2, 'BINANCE_xauusdt_2',    '', 'metal',  NOW(), NOW()),
('binance', 'xagusdt',    2, 'BINANCE_xagusdt_2',    '', 'metal',  NOW(), NOW()),
('binance', 'xptusdt',    2, 'BINANCE_xptusdt_2',    '', 'metal',  NOW(), NOW()),
('binance', 'xpdusdt',    2, 'BINANCE_xpdusdt_2',    '', 'metal',  NOW(), NOW()),
('binance', 'clusdt',     2, 'BINANCE_clusdt_2',     '', 'energy', NOW(), NOW()),
('binance', 'natgasusdt', 2, 'BINANCE_natgasusdt_2', '', 'energy', NOW(), NOW());
