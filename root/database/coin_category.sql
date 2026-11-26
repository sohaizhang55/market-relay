-- coin_category 产品类型默认数据（code 为 coin_list.category_code 的外键字符串；status 1=ON 2=OFF）
INSERT IGNORE INTO `coin_category` (`code`, `title`, `title_langs`, `status`, `sort`) VALUES
('crypto', '加密货币', '{"zh_CN": "加密货币", "en_US": "Crypto"}', 1, 60),
('rwa', 'RWA 代币', '{"zh_CN": "RWA 代币", "en_US": "RWA"}', 1, 50),
('metal', '贵金属', '{"zh_CN": "贵金属", "en_US": "Metals"}', 1, 40),
('energy', '能源', '{"zh_CN": "能源", "en_US": "Energy"}', 1, 30),
('index', '指数 ETF', '{"zh_CN": "指数 ETF", "en_US": "Index ETF"}', 1, 20),
('stock', '股票', '{"zh_CN": "股票", "en_US": "Stocks"}', 1, 10),
('forex', '外汇', '{"zh_CN": "外汇", "en_US": "Forex"}', 1, 35);
