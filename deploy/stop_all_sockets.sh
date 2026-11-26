#!/bin/bash

# 获取当前脚本所在的绝对路径，确保能精准定位到同目录下的 config.env
CONFIG_FILE="/home/deploy/market-relay/deploy/config.env"

# 判断配置文件是否存在，存在则引入
if [ -f "$CONFIG_FILE" ]; then
    # 使用 source 引入，里面的变量会瞬间注入到当前脚本的生命周期里
    source "$CONFIG_FILE"
    echo "[Success] 成功引入配置文件，当前锁定 PHP: $PHP_BIN"
else
    # 兜底防御：如果配置不小心被删了，给出报错并退出，防止盲目往下跑
    echo "[Error] 找不到配置文件: $CONFIG_FILE，请检查！"
    exit 1
fi

# ======================================================================
#  Stop All Daemon and Socket Processes for market-relay
# ======================================================================

THINK_BIN="/home/deploy/market-relay/root/think"

echo "=========================================="
# 清单与 restart_market.sh 启动的常驻进程一一对应；那边增删进程，这里要同步。
# market-relay 不启动 Workerman Gateway（见 install.sh 防火墙段），也没有撮合 / 结算引擎，无需停止。

# 1. 停止行情数据采集器（直连/数据源方）
echo "Step 1: Stopping Market Data Collectors..."
$PHP_BIN "$THINK_BIN" market:huobi stop -t 1
$PHP_BIN "$THINK_BIN" market:huobi stop -t 2
$PHP_BIN "$THINK_BIN" market:binance stop -t 1
$PHP_BIN "$THINK_BIN" market:binance stop -t 2
$PHP_BIN "$THINK_BIN" market:bingx stop -t 2
$PHP_BIN "$THINK_BIN" market:db_flush stop

# 2. 停止分发 / 回补器 / 费率进程
echo "Step 2: Stopping Market Dispatcher / Price Fallback / Kline Backfill / Funding..."
$PHP_BIN "$THINK_BIN" market:dispatch stop -t 1
$PHP_BIN "$THINK_BIN" market:dispatch stop -t 2
$PHP_BIN "$THINK_BIN" market:price_fallback stop -e huobi
$PHP_BIN "$THINK_BIN" market:price_fallback stop -e binance
$PHP_BIN "$THINK_BIN" market:price_fallback stop -e bingx
$PHP_BIN "$THINK_BIN" market:kline_backfill stop -e huobi
$PHP_BIN "$THINK_BIN" market:kline_backfill stop -e binance
$PHP_BIN "$THINK_BIN" market:kline_backfill stop -e bingx
$PHP_BIN "$THINK_BIN" market:funding stop -e huobi
$PHP_BIN "$THINK_BIN" market:funding stop -e binance
$PHP_BIN "$THINK_BIN" market:funding stop -e bingx
$PHP_BIN "$THINK_BIN" market:source_select stop

echo "=========================================="
echo "All market daemons have been stopped."
echo "You can now safely perform database migrations."
echo "=========================================="
