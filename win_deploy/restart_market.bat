@echo off
:: Set UTF-8 encoding (though comments are now English)
chcp 65001 > nul

:: Get root directory
set BASE_DIR=%~dp0
cd /d "%BASE_DIR%..\root"

echo ==========================================
echo    Windows: Market Data Crawler Restart
echo ==========================================

:: 1. Force close all windows with "market_relay-" prefix.
::    NOT "Market-*": another project on the same machine may use that prefix, and its restart_market.bat
::    kills "Market-*" -- a shared prefix means either script wipes out the other project's processes.
echo Step 1: Stopping existing market windows...
taskkill /f /fi "WINDOWTITLE eq market_relay-*" >nul 2>&1
timeout /t 2 /nobreak >nul

:: 2. Start Huobi Tasks
echo Step 2: Starting Huobi Collectors...
start "market_relay-Huobi-Spot" php think market:huobi start -t 1
start "market_relay-Huobi-Swap" php think market:huobi start -t 2

:: 3. Start Binance / BingX Tasks
echo Step 3: Starting Binance / BingX Collectors...
start "market_relay-Binance-Spot" php think market:binance start -t 1
start "market_relay-Binance-Swap" php think market:binance start -t 2
:: BingX: USDT perpetuals only (forex / heating oil TradFi contracts), so -t 2 only
start "market_relay-Bingx-Swap" php think market:bingx start -t 2

:: 4. Start DB Flusher (must run alongside the collectors, otherwise market data only lands in Redis, never MySQL)
echo Step 4: Starting Market DB Flusher...
start "market_relay-DbFlush" php think market:db_flush start

:: 5. Start Dispatchers
::    Spot and contract each run as an independent process on its own port (default 8594 / 8595),
::    so one blocking or restarting never stalls the other.
echo Step 5: Starting Market Dispatchers - spot + contract...
start "market_relay-Dispatch-Spot" php think market:dispatch start -t 1
start "market_relay-Dispatch-Contract" php think market:dispatch start -t 2

:: 7. Start Price Fallback / Kline Backfill Workers.
::    One process per exchange for each (-e is required), both via exchange REST.
::    Without them stale prices are never refreshed and kline gaps are never backfilled.
echo Step 7: Starting Price Fallback / Kline Backfill Workers...
start "market_relay-PriceFallback-Huobi" php think market:price_fallback start -e huobi
start "market_relay-PriceFallback-Binance" php think market:price_fallback start -e binance
start "market_relay-PriceFallback-Bingx" php think market:price_fallback start -e bingx
start "market_relay-KlineBackfill-Huobi" php think market:kline_backfill start -e huobi
start "market_relay-KlineBackfill-Binance" php think market:kline_backfill start -e binance
start "market_relay-KlineBackfill-Bingx" php think market:kline_backfill start -e bingx

:: 7b. Start Funding Rate Workers -- the ONLY funding rate supply, not a fallback.
::     One process per exchange that has swap sources.
::     Without it funding rates stop updating and downstream funding settlement skips after 1 hour.
echo Step 7b: Starting Funding Rate Workers...
start "market_relay-Funding-Huobi" php think market:funding start -e huobi
start "market_relay-Funding-Binance" php think market:funding start -e binance
start "market_relay-Funding-Bingx" php think market:funding start -e bingx

:: 7c. Start Source Selector (failover between bound sources).
::     Disabled by default (market.source_select.enable=false): the window just exits. Tune the per-source
::     stale seconds in the admin first, then set MARKET_SOURCE_SELECT_ENABLE=1.
echo Step 7c: Starting Market Source Selector...
start "market_relay-SourceSelect" php think market:source_select start

echo ==========================================
echo All market windows have been restarted.
echo ==========================================
REM 核对该常驻的进程是否真起来了（清单与运行期告警共用 MarketWorkers::expected）
REM market:price_fallback / kline_backfill / funding 忘了启动不会有任何即时告警，但价格没有兜底、K线缺口没人补、资金费率断供
timeout /t 5 /nobreak >nul
php think command_heartbeat:check_expire --verify

pause
