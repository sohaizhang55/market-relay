-- +----------------------------------------------------------------------
-- | ThinkAdmin Database Structure Dump
-- | Generated at: 2026-11-22 23:11:45 (server 5.7.26)
-- | 已归一化为 MySQL 5.7 / 8.4 通用方言：整数不带显示宽度、不带 AUTO_INCREMENT 计数器、
-- | 显式 ROW_FORMAT=DYNAMIC（绕开 5.7 的 767 字节索引前缀限制）。
-- | 重新导出一律用 php think xdata:db，别直接贴 mysqldump 的输出——那会把服务器版本方言带进来。
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for coin_category
-- ----------------------------
DROP TABLE IF EXISTS `coin_category`;
CREATE TABLE `coin_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '类型代码 (crypto/rwa/metal/energy/index/stock…)',
  `title` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '默认标题',
  `title_langs` text COLLATE utf8mb4_unicode_ci COMMENT '多语言标题 JSON，如 {"zh_CN":"...","en_US":"..."}',
  `status` int NOT NULL DEFAULT '1' COMMENT '状态 1=ON 2=OFF；只控制前端列表是否展示，刻意不参与下单校验（下单只看 exchange_pair.status 与玩法表）',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序权重',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `create_ts` int DEFAULT '0',
  `update_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `update_ts` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_category_code` (`code`),
  KEY `idx_category_status_sort` (`status`,`sort`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='产品类型表';

-- ----------------------------
-- Table structure for dispatch_subscriber
-- ----------------------------
DROP TABLE IF EXISTS `dispatch_subscriber`;
CREATE TABLE `dispatch_subscriber` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '订阅方名称，监控页与日志里显示',
  `token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '鉴权 token，订阅方放在 X-Inner-Token 头里',
  `status` int DEFAULT '1' COMMENT '状态 @see app\\enum\\CommonStatusEnum (1:启用, 2:停用)',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '备注',
  `last_ws_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '最后一次 WS 连接的来源 IP',
  `last_ws_at` datetime DEFAULT NULL COMMENT '最后一次 WS 连接时间',
  `last_ws_ts` int DEFAULT '0' COMMENT '最后一次 WS 连接秒级时间戳',
  `last_api_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '最后一次 inner_api 请求的来源 IP',
  `last_api_at` datetime DEFAULT NULL COMMENT '最后一次 inner_api 请求时间',
  `last_api_ts` int DEFAULT '0' COMMENT '最后一次 inner_api 请求秒级时间戳',
  `create_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `update_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '更新时间',
  `create_ts` int DEFAULT '0' COMMENT '创建秒级时间戳',
  `update_ts` int DEFAULT '0' COMMENT '更新秒级时间戳',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dispatch_subscriber_token` (`token`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='行情订阅方（分发鉴权 token）';

-- ----------------------------
-- Table structure for exchange_pair
-- ----------------------------
DROP TABLE IF EXISTS `exchange_pair`;
CREATE TABLE `exchange_pair` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `symbol` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '展示名称 (例 BTC/USDT)',
  `symbol_code` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '平台内部代码 (例 btcusdt)',
  `type` int DEFAULT '1' COMMENT '交易类型 (1:现货, 2:合约)',
  `base_coin` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '基础币种 (例 BTC)',
  `quote_coin` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '计价币种 (例 USDT)',
  `last_price` decimal(30,10) DEFAULT '0.0000000000' COMMENT '最新成交价',
  `mark_price` decimal(30,10) NOT NULL DEFAULT '0.0000000000' COMMENT '标记价格 (暂等于 last_price，独立存字段方便以后单独演进算法，供强平判断使用)',
  `mark_ts` bigint NOT NULL DEFAULT '0' COMMENT '标记价格原始毫秒级时间戳，独立于 market_ts 用于判断 mark_price 是否过期',
  `funding_rate` decimal(10,6) NOT NULL DEFAULT '0.000000' COMMENT '当前资金费率 (行情模块同步写入)',
  `estimated_funding_rate` decimal(10,6) NOT NULL DEFAULT '0.000000' COMMENT '预估下期资金费率',
  `funding_rate_ts` bigint NOT NULL DEFAULT '0' COMMENT '资金费率毫秒级时间戳，结算前据此判断费率是否已过期 (单位对齐 mark_ts)',
  `open` decimal(30,10) DEFAULT '0.0000000000' COMMENT '24h开盘价',
  `change` decimal(12,4) DEFAULT '0.0000' COMMENT '24h涨跌幅% (随 last_price/open 每次行情落库同步计算)',
  `high` decimal(30,10) DEFAULT '0.0000000000' COMMENT '24h最高价',
  `low` decimal(30,10) DEFAULT '0.0000000000' COMMENT '24h最低价',
  `close` decimal(30,10) DEFAULT '0.0000000000' COMMENT '24h收盘价',
  `amount` decimal(30,10) DEFAULT '0.0000000000' COMMENT '24h成交额',
  `vol` decimal(30,10) DEFAULT '0.0000000000' COMMENT '24h成交量',
  `market_ts` bigint DEFAULT '0' COMMENT '行情原始时间戳 (毫秒)',
  `precision_price` int DEFAULT '2' COMMENT '价格精度',
  `precision_amount` int DEFAULT '4' COMMENT '数量精度',
  `status` int DEFAULT '1' COMMENT '状态 (1:开启-ON, 2:禁用-OFF)',
  `sort` int DEFAULT '0' COMMENT '排序权重',
  `create_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建日期',
  `update_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '更新日期',
  `create_ts` int DEFAULT '0' COMMENT '创建秒级时间戳',
  `update_ts` int DEFAULT '0' COMMENT '更新秒级时间戳',
  `source_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '行情源唯一键 (对应 market_data.source_key)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_symbol_type` (`symbol_code`,`type`),
  KEY `source_key` (`source_key`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='交易对配置表';

-- ----------------------------
-- Table structure for exchange_pair_kline
-- ----------------------------
DROP TABLE IF EXISTS `exchange_pair_kline`;
CREATE TABLE `exchange_pair_kline` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `period` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '1m' COMMENT 'K线周期 (1m,5m,1h,1d等)',
  `open` decimal(30,10) DEFAULT '0.0000000000' COMMENT '开盘价',
  `high` decimal(30,10) DEFAULT '0.0000000000' COMMENT '最高价',
  `low` decimal(30,10) DEFAULT '0.0000000000' COMMENT '最低价',
  `close` decimal(30,10) DEFAULT '0.0000000000' COMMENT '收盘价',
  `vol` decimal(30,10) DEFAULT '0.0000000000' COMMENT '成交量',
  `amount` decimal(30,10) DEFAULT '0.0000000000' COMMENT '成交额',
  `ts` bigint DEFAULT '0' COMMENT '起始时间戳 (毫秒)',
  `pair_id` int DEFAULT '0' COMMENT '交易对ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_pair_period_ts_unique` (`pair_id`,`period`,`ts`),
  KEY `idx_kline_ts` (`ts`),
  KEY `idx_pair_id` (`pair_id`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='行情K线数据表';

-- ----------------------------
-- Table structure for exchange_pair_source
-- ----------------------------
DROP TABLE IF EXISTS `exchange_pair_source`;
CREATE TABLE `exchange_pair_source` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pair_id` int unsigned NOT NULL COMMENT 'exchange_pair.id（只在本机用，不出机器）',
  `source_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '数据源，对应 market_data.source_key',
  `priority` int NOT NULL DEFAULT '1' COMMENT '优先级 1=主源，越大越靠后；生效源 = 按此排序第一个健康的源',
  `status` int NOT NULL DEFAULT '1' COMMENT '状态 1=启用 2=停用，见 CommonStatusEnum；停用的源不参与选源',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `create_ts` int DEFAULT '0',
  `update_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `update_ts` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair_source` (`pair_id`,`source_key`),
  KEY `idx_pair_source_source_key` (`source_key`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='交易对数据源绑定(主备切换)';

-- ----------------------------
-- Table structure for market_calendar_holiday
-- ----------------------------
DROP TABLE IF EXISTS `market_calendar_holiday`;
CREATE TABLE `market_calendar_holiday` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `calendar_code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '日历归属 ISO 10383 MIC，取值见 MarketCalendarEnum',
  `holiday_date` date NOT NULL COMMENT '交易所本地自然日 (不是 UTC 日)',
  `close_type` int NOT NULL DEFAULT '1' COMMENT '休市类型 1=全日休市 2=时段调整(裁剪,只能变短) 3=时段覆盖(整段替换,可变长)，见 MarketHolidayTypeEnum',
  `open_time` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '时段调整日开盘本地时间 HH:MM，空=不改开盘',
  `close_time` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '时段调整日收盘本地时间 HH:MM，空=不改收盘',
  `title` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '假日名称，如 Independence Day',
  `source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' COMMENT '数据来源 exchange_calendars=脚本导入 manual=人工录入；导入命令只覆盖前者',
  `status` int NOT NULL DEFAULT '1' COMMENT '状态 1=启用 2=停用；停用的规则不参与休市判定',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `create_ts` int DEFAULT '0',
  `update_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `update_ts` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_holiday_calendar_date` (`calendar_code`,`holiday_date`),
  KEY `idx_holiday_date` (`holiday_date`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='节假日休市日历';

-- ----------------------------
-- Table structure for market_calendar_session
-- ----------------------------
DROP TABLE IF EXISTS `market_calendar_session`;
CREATE TABLE `market_calendar_session` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `calendar_code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '日历归属 ISO 10383 MIC，取值见 MarketCalendarEnum',
  `open_week_day` int NOT NULL COMMENT '开盘星期 1-7 (ISO,1=周一)，见 CommonWeekDayEnum',
  `open_time` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '开盘本地时间 HH:MM (两位补零)',
  `close_week_day` int NOT NULL COMMENT '收盘星期 1-7；与开盘不同即跨天，如黄金周日17:00->周一17:00',
  `close_time` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '收盘本地时间 HH:MM；区间左闭右开 [open,close)',
  `source` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual' COMMENT '数据来源 exchange_calendars=脚本导入 manual=人工录入；导入命令只覆盖前者',
  `status` int NOT NULL DEFAULT '1' COMMENT '状态 1=启用 2=停用；停用的时段不参与休市判定',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备注',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `create_ts` int DEFAULT '0',
  `update_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `update_ts` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_session_calendar_week` (`calendar_code`,`open_week_day`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='开市时段表(周内固定规则)';

-- ----------------------------
-- Table structure for market_data
-- ----------------------------
DROP TABLE IF EXISTS `market_data`;
CREATE TABLE `market_data` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `exchange` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT 'huobi' COMMENT '交易所代码 (如 huobi)',
  `symbol` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '币种代码 (如 xrpusdt)',
  `type` int DEFAULT '1' COMMENT '行情类型 (1:现货, 2:合约)',
  `open` decimal(30,10) NOT NULL DEFAULT '0.0000000000' COMMENT '24h开盘价',
  `close` decimal(30,10) NOT NULL DEFAULT '0.0000000000' COMMENT '当前价 (24h收盘价)',
  `low` decimal(30,10) NOT NULL DEFAULT '0.0000000000' COMMENT '24h最低价',
  `high` decimal(30,10) NOT NULL DEFAULT '0.0000000000' COMMENT '24h最高价',
  `amount` decimal(30,10) NOT NULL DEFAULT '0.0000000000' COMMENT '24h成交额(USDT)',
  `vol` decimal(30,10) NOT NULL DEFAULT '0.0000000000' COMMENT '24h成交量',
  `count` int DEFAULT '0' COMMENT '24h成交笔数',
  `update_ts` bigint DEFAULT '0' COMMENT '火币原始毫秒级时间戳',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `create_ts` int DEFAULT '0',
  `update_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `source_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '数据源唯一键 (PROVIDER_SYMBOL_TYPE)',
  `calendar_code` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '该源跟哪套交易作息走 ISO 10383 MIC，空=24/7 无休市，取值见 MarketCalendarEnum',
  `category_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'crypto' COMMENT '行情源品类 → coin_category.code',
  `ticker_stale_sec` int NOT NULL DEFAULT '30' COMMENT 'ticker 断流秒数：超过这么久没收到 WS 价格帧判该源不健康，0=不判',
  `depth_stale_sec` int NOT NULL DEFAULT '15' COMMENT '深度断流秒数：超过这么久没收到 WS 深度帧判该源不健康，0=不判',
  `mark_price` decimal(30,10) NOT NULL DEFAULT '0.0000000000' COMMENT '标记价格 (暂等于成交价 close，独立存字段方便以后单独演进算法)',
  `mark_ts` bigint NOT NULL DEFAULT '0' COMMENT '标记价格原始毫秒级时间戳',
  `funding_rate` decimal(10,6) NOT NULL DEFAULT '0.000000' COMMENT '当前资金费率 (交易所原始值)',
  `estimated_funding_rate` decimal(10,6) NOT NULL DEFAULT '0.000000' COMMENT '预估下期资金费率',
  `funding_rate_ts` bigint NOT NULL DEFAULT '0' COMMENT '资金费率采集毫秒级时间戳，用于新鲜度校验与 REST 兜底触发判断',
  `exchange_next_funding_ts` bigint NOT NULL DEFAULT '0' COMMENT '交易所自报的下次结算毫秒时间戳，仅记录备用，不参与本平台结算窗口计算',
  PRIMARY KEY (`id`),
  UNIQUE KEY `source_key` (`source_key`),
  UNIQUE KEY `idx_exchange_symbol_type` (`exchange`,`symbol`,`type`),
  KEY `idx_market_data_calendar_code` (`calendar_code`),
  KEY `idx_market_data_category_code` (`category_code`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='币种行情数据表';

-- ----------------------------
-- Table structure for market_source_kline
-- ----------------------------
DROP TABLE IF EXISTS `market_source_kline`;
CREATE TABLE `market_source_kline` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '行情源唯一键 (PROVIDER_SYMBOL_TYPE)',
  `symbol` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '币种代码 (例 btcusdt)',
  `exchange` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '交易所代码 (例 huobi)',
  `type` int DEFAULT '1' COMMENT '行情类型 (1:现货, 2:合约)',
  `period` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'K 线周期 (1min, 5min, etc.)',
  `open` decimal(30,10) DEFAULT '0.0000000000' COMMENT '开盘价',
  `high` decimal(30,10) DEFAULT '0.0000000000' COMMENT '最高价',
  `low` decimal(30,10) DEFAULT '0.0000000000' COMMENT '最低价',
  `close` decimal(30,10) DEFAULT '0.0000000000' COMMENT '收盘价',
  `vol` decimal(30,10) DEFAULT '0.0000000000' COMMENT '成交量',
  `amount` decimal(30,10) DEFAULT '0.0000000000' COMMENT '成交额',
  `ts` bigint DEFAULT NULL COMMENT 'K 线起始时间戳（毫秒）',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `create_ts` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_kline_source_lookup` (`source_key`,`period`,`ts`),
  KEY `idx_msk_ts` (`ts`),
  KEY `idx_msk_period_ts` (`period`,`ts`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='原始交易所K线数据表';

-- ----------------------------
-- Table structure for system_auth
-- ----------------------------
DROP TABLE IF EXISTS `system_auth`;
CREATE TABLE `system_auth` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(100) DEFAULT '' COMMENT '权限名称',
  `utype` varchar(50) DEFAULT '' COMMENT '身份权限',
  `desc` varchar(500) DEFAULT '' COMMENT '备注说明',
  `sort` bigint DEFAULT '0' COMMENT '排序权重',
  `status` int DEFAULT '1' COMMENT '权限状态(1使用,0禁用)',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_a805_sa_sort` (`sort`),
  KEY `idx_a805_sa_title` (`title`),
  KEY `idx_a805_sa_status` (`status`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-权限';

-- ----------------------------
-- Table structure for system_auth_node
-- ----------------------------
DROP TABLE IF EXISTS `system_auth_node`;
CREATE TABLE `system_auth_node` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `auth` bigint DEFAULT '0' COMMENT '角色',
  `node` varchar(200) DEFAULT '' COMMENT '节点',
  PRIMARY KEY (`id`),
  KEY `idx_435f_san_auth` (`auth`),
  KEY `idx_435f_san_node` (`node`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-授权';

-- ----------------------------
-- Table structure for system_base
-- ----------------------------
DROP TABLE IF EXISTS `system_base`;
CREATE TABLE `system_base` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) DEFAULT '' COMMENT '数据类型',
  `code` varchar(100) DEFAULT '' COMMENT '数据代码',
  `name` varchar(500) DEFAULT '' COMMENT '数据名称',
  `content` text COMMENT '数据内容',
  `sort` bigint DEFAULT '0' COMMENT '排序权重',
  `status` int DEFAULT '1' COMMENT '数据状态(0禁用,1启动)',
  `deleted` int DEFAULT '0' COMMENT '删除状态(0正常,1已删)',
  `deleted_at` varchar(20) DEFAULT '' COMMENT '删除时间',
  `deleted_by` bigint DEFAULT '0' COMMENT '删除用户',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_15ec_sb_type` (`type`),
  KEY `idx_15ec_sb_code` (`code`),
  KEY `idx_15ec_sb_sort` (`sort`),
  KEY `idx_15ec_sb_status` (`status`),
  KEY `idx_15ec_sb_deleted` (`deleted`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-字典';

-- ----------------------------
-- Table structure for system_config
-- ----------------------------
DROP TABLE IF EXISTS `system_config`;
CREATE TABLE `system_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) DEFAULT '' COMMENT '配置分类',
  `name` varchar(100) DEFAULT '' COMMENT '配置名称',
  `value` varchar(2048) DEFAULT '' COMMENT '配置内容',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_system_config_unique_type_name` (`type`,`name`),
  KEY `idx_2c6a_sc_type` (`type`),
  KEY `idx_2c6a_sc_name` (`name`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-配置';

-- ----------------------------
-- Table structure for system_data
-- ----------------------------
DROP TABLE IF EXISTS `system_data`;
CREATE TABLE `system_data` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT '' COMMENT '配置名',
  `value` text COMMENT '配置值',
  `create_time` datetime DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_5ae1_sd_name` (`name`),
  KEY `idx_5ae1_sd_create_time` (`create_time`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-数据';

-- ----------------------------
-- Table structure for system_file
-- ----------------------------
DROP TABLE IF EXISTS `system_file`;
CREATE TABLE `system_file` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) DEFAULT '' COMMENT '上传类型',
  `hash` varchar(32) DEFAULT '' COMMENT '文件哈希',
  `tags` varchar(50) DEFAULT '' COMMENT '文件标签',
  `name` varchar(180) DEFAULT '' COMMENT '文件名称',
  `xext` varchar(100) DEFAULT '' COMMENT '文件后缀',
  `xurl` varchar(500) DEFAULT '' COMMENT '访问链接',
  `xkey` varchar(500) DEFAULT '' COMMENT '文件路径',
  `mime` varchar(100) DEFAULT '' COMMENT '文件类型',
  `size` bigint DEFAULT '0' COMMENT '文件大小',
  `uuid` bigint DEFAULT '0' COMMENT '用户编号',
  `unid` bigint DEFAULT '0' COMMENT '会员编号',
  `isfast` int DEFAULT '0' COMMENT '是否秒传',
  `issafe` int DEFAULT '0' COMMENT '安全模式',
  `status` int DEFAULT '1' COMMENT '上传状态(1悬空,2落地)',
  `create_at` datetime DEFAULT NULL COMMENT '创建时间',
  `update_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_c455_sf_type` (`type`),
  KEY `idx_c455_sf_hash` (`hash`),
  KEY `idx_c455_sf_uuid` (`uuid`),
  KEY `idx_c455_sf_xext` (`xext`),
  KEY `idx_c455_sf_unid` (`unid`),
  KEY `idx_c455_sf_tags` (`tags`),
  KEY `idx_c455_sf_name` (`name`),
  KEY `idx_c455_sf_status` (`status`),
  KEY `idx_c455_sf_issafe` (`issafe`),
  KEY `idx_c455_sf_isfast` (`isfast`),
  KEY `idx_c455_sf_create_at` (`create_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-文件';

-- ----------------------------
-- Table structure for system_menu
-- ----------------------------
DROP TABLE IF EXISTS `system_menu`;
CREATE TABLE `system_menu` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pid` bigint DEFAULT '0' COMMENT '上级ID',
  `title` varchar(100) DEFAULT '' COMMENT '菜单名称',
  `icon` varchar(100) DEFAULT '' COMMENT '菜单图标',
  `node` varchar(100) DEFAULT '' COMMENT '节点代码',
  `url` varchar(400) DEFAULT '' COMMENT '链接节点',
  `params` varchar(500) DEFAULT '' COMMENT '链接参数',
  `target` varchar(20) DEFAULT '_self' COMMENT '打开方式',
  `sort` bigint DEFAULT '0' COMMENT '排序权重',
  `status` int DEFAULT '1' COMMENT '状态(0:禁用,1:启用)',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_09ec_sm_pid` (`pid`),
  KEY `idx_09ec_sm_sort` (`sort`),
  KEY `idx_09ec_sm_status` (`status`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-菜单';

-- ----------------------------
-- Table structure for system_notification
-- ----------------------------
DROP TABLE IF EXISTS `system_notification`;
CREATE TABLE `system_notification` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint unsigned NOT NULL COMMENT '接收管理员 ID',
  `from_id` int DEFAULT '0' COMMENT '发送人ID(0为系统)',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'info' COMMENT '类型(info,success,warning,danger)',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '通知标题',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '通知内容',
  `is_read` int DEFAULT '0' COMMENT '阅读状态(0未读,1已读)',
  `create_ts` int DEFAULT '0' COMMENT '创建时间戳',
  `read_ts` int DEFAULT '0' COMMENT '阅读时间戳',
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`,`is_read`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统通知表';

-- ----------------------------
-- Table structure for system_oplog
-- ----------------------------
DROP TABLE IF EXISTS `system_oplog`;
CREATE TABLE `system_oplog` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `node` varchar(200) NOT NULL DEFAULT '' COMMENT '当前操作节点',
  `geoip` varchar(15) NOT NULL DEFAULT '' COMMENT '操作者IP地址',
  `action` varchar(200) NOT NULL DEFAULT '' COMMENT '操作行为名称',
  `content` text COMMENT '操作内容',
  `username` varchar(50) NOT NULL DEFAULT '' COMMENT '操作人用户名',
  `create_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_7cab_so_create_at` (`create_at`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-日志';

-- ----------------------------
-- Table structure for system_queue
-- ----------------------------
DROP TABLE IF EXISTS `system_queue`;
CREATE TABLE `system_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL DEFAULT '' COMMENT '任务编号',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '任务名称',
  `command` varchar(500) DEFAULT '' COMMENT '执行指令',
  `exec_pid` bigint DEFAULT '0' COMMENT '执行进程',
  `exec_data` text COMMENT '执行参数',
  `exec_time` bigint DEFAULT '0' COMMENT '执行时间',
  `exec_desc` varchar(500) DEFAULT '' COMMENT '执行描述',
  `enter_time` decimal(20,4) DEFAULT '0.0000' COMMENT '开始时间',
  `outer_time` decimal(20,4) DEFAULT '0.0000' COMMENT '结束时间',
  `loops_time` bigint DEFAULT '0' COMMENT '循环时间',
  `attempts` bigint DEFAULT '0' COMMENT '执行次数',
  `message` text COMMENT '最新消息',
  `rscript` int DEFAULT '1' COMMENT '任务类型(0单例,1多例)',
  `status` int DEFAULT '1' COMMENT '任务状态(1新任务,2处理中,3成功,4失败)',
  `create_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_2849_sq_code` (`code`),
  KEY `idx_2849_sq_title` (`title`),
  KEY `idx_2849_sq_status` (`status`),
  KEY `idx_2849_sq_rscript` (`rscript`),
  KEY `idx_2849_sq_create_at` (`create_at`),
  KEY `idx_2849_sq_exec_time` (`exec_time`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-任务';

-- ----------------------------
-- Table structure for system_user
-- ----------------------------
DROP TABLE IF EXISTS `system_user`;
CREATE TABLE `system_user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `usertype` varchar(20) DEFAULT '' COMMENT '用户类型',
  `username` varchar(50) DEFAULT '' COMMENT '用户账号',
  `password` varchar(32) DEFAULT '' COMMENT '用户密码',
  `nickname` varchar(50) DEFAULT '' COMMENT '用户昵称',
  `headimg` varchar(255) DEFAULT '' COMMENT '头像地址',
  `authorize` varchar(255) DEFAULT '' COMMENT '权限授权',
  `contact_qq` varchar(20) DEFAULT '' COMMENT '联系QQ',
  `contact_mail` varchar(20) DEFAULT '' COMMENT '联系邮箱',
  `contact_phone` varchar(20) DEFAULT '' COMMENT '联系手机',
  `login_ip` varchar(255) DEFAULT '' COMMENT '登录地址',
  `login_at` varchar(20) DEFAULT '' COMMENT '登录时间',
  `login_num` bigint DEFAULT '0' COMMENT '登录次数',
  `describe` varchar(255) DEFAULT '' COMMENT '备注说明',
  `sort` bigint DEFAULT '0' COMMENT '排序权重',
  `status` int DEFAULT '1' COMMENT '状态(0禁用,1启用)',
  `is_deleted` int DEFAULT '0' COMMENT '删除(1删除,0未删)',
  `create_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `google_auth_secret` varchar(255) DEFAULT NULL COMMENT 'Google 2FA 密钥',
  PRIMARY KEY (`id`),
  KEY `idx_ddf2_su_sort` (`sort`),
  KEY `idx_ddf2_su_status` (`status`),
  KEY `idx_ddf2_su_username` (`username`),
  KEY `idx_ddf2_su_is_deleted` (`is_deleted`)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统-用户';

SET FOREIGN_KEY_CHECKS = 1;
