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

# 公共 daemon 下发函数：负责不让常驻进程攥住终端 fd（否则脚本跑完回不到提示符），
# 并按真实退出码汇报结果。详见该文件头部注释。
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/lib/run_daemon.sh" ]; then
    source "$SCRIPT_DIR/lib/run_daemon.sh"
else
    echo "[Error] 找不到 $SCRIPT_DIR/lib/run_daemon.sh，请确认 deploy/lib/ 已一并部署！"
    exit 1
fi

THINK_BIN="/home/deploy/market-relay/root/think"

echo "=========================================="
echo "   Market Data Crawler Starting...        "
echo "=========================================="

# 只有一条流程：采集 → 更新 pair → 分发。下面的进程全部常驻，
# 唯一按配置可能不起的是选源进程（market.source_select.enable，见 9b）。

# 1~4. 直连采集器（BingX 只有 -t 2）
run_daemon "火币现货采集器" $PHP_BIN "$THINK_BIN" market:huobi   restart -t 1 -d
run_daemon "火币合约采集器" $PHP_BIN "$THINK_BIN" market:huobi   restart -t 2 -d
run_daemon "币安现货采集器" $PHP_BIN "$THINK_BIN" market:binance restart -t 1 -d
run_daemon "币安合约采集器" $PHP_BIN "$THINK_BIN" market:binance restart -t 2 -d
# BingX 只采 USDT 永续（外汇、燃料油等 TradFi 合约），没有 -t 1
run_daemon "BingX 合约采集器" $PHP_BIN "$THINK_BIN" market:bingx restart -t 2 -d

# 5. 行情落库 Flusher（不随采集进程一起启停的话，行情只进 Redis 不落库 MySQL）
run_daemon "行情落库 Flusher" $PHP_BIN "$THINK_BIN" market:db_flush restart -d

# 6. 分发进程：现货、合约各一个独立进程、各一个端口（默认 8594 / 8595），一个卡住或重启不影响另一类行情
run_daemon "行情分发机-现货" $PHP_BIN "$THINK_BIN" market:dispatch restart -t 1 -d
run_daemon "行情分发机-合约" $PHP_BIN "$THINK_BIN" market:dispatch restart -t 2 -d

# 7. 价格兜底 / K 线补数，走交易所 REST，各自一个进程。
#    从采集器里拆出来是因为这两件事都是同步 MySQL 扫描 + 阻塞 curl，
#    挂在采集器上会堵住 WS 事件循环、那段时间本机行情全部停止更新。
#    2026-10 再从同一个 market:backfill 拆成两个：K 线补数一忙，价格兜底就得排队。
#    忘记启动不会有任何即时告警，但 WS 静默时价格没有兜底、K 线缺口再也没人补，
#    详见 root/app/command/MarketRestWorkerAbstract.php 类注释。
#    按交易所一家一个进程（配额可分别配），与上面采集器一样逐家列出；
#    market_data 里没有源的交易所会被命令拒绝启动，这是预期。
#
#    过渡：拆分前的 market:backfill 命令已删除，没法再用它 stop，按 pid 文件停掉还在跑的旧进程。
#    所有机器都部署过一次之后，这个循环可以删掉。
for ex in huobi binance bingx; do
    OLD_PID_FILE="/home/deploy/market-relay/root/runtime/market-relay_MarketBackfill_${ex}.pid"
    if [ -f "$OLD_PID_FILE" ]; then
        OLD_PID="$(cat "$OLD_PID_FILE")"
        kill -SIGINT "$OLD_PID" 2>/dev/null && echo "[Info] 已停止旧的行情补数器 market:backfill -e ${ex} (master=${OLD_PID})"
        rm -f "$OLD_PID_FILE"
    fi
done

run_daemon "价格兜底-火币"    $PHP_BIN "$THINK_BIN" market:price_fallback restart -e huobi   -d
run_daemon "价格兜底-币安"    $PHP_BIN "$THINK_BIN" market:price_fallback restart -e binance -d
run_daemon "价格兜底-BingX"   $PHP_BIN "$THINK_BIN" market:price_fallback restart -e bingx   -d
run_daemon "K线补数-火币"     $PHP_BIN "$THINK_BIN" market:kline_backfill restart -e huobi   -d
run_daemon "K线补数-币安"     $PHP_BIN "$THINK_BIN" market:kline_backfill restart -e binance -d
run_daemon "K线补数-BingX"    $PHP_BIN "$THINK_BIN" market:kline_backfill restart -e bingx   -d

# 8. 资金费率供数 —— 费率的唯一供数通道，不是兜底，轮询交易所 REST。
#    不跑则费率停更，超过 1 小时下游资金费结算整期跳过收费，详见 root/app/command/MarketFunding.php。
#    只有有合约源的交易所才有。
run_daemon "资金费率-火币" $PHP_BIN "$THINK_BIN" market:funding restart -e huobi   -d
run_daemon "资金费率-币安" $PHP_BIN "$THINK_BIN" market:funding restart -e binance -d
run_daemon "资金费率-BingX" $PHP_BIN "$THINK_BIN" market:funding restart -e bingx   -d

# 9. 选源进程（主备切换）
#    默认不启用（market.source_select.enable=false），会被拒绝并报 [SKIP]，这是预期；
#    在后台调好各源断流秒数后设 MARKET_SOURCE_SELECT_ENABLE=1 再重跑本脚本
run_daemon "选源进程" $PHP_BIN "$THINK_BIN" market:source_select restart -d

daemon_summary

echo ""
echo "可用 'ps aux | grep market' 核对实际进程。"

echo ""
echo "=========================================="
echo " Step 11: 核对该常驻的进程是否真的起来了"
echo "=========================================="
# 为什么要这一步：上面每条 run_daemon 只保证"指令下发成功"，不保证进程真的活着 ——
# 配置错、依赖缺失、端口占用都是"下发成功、进程没起来"，在上面的输出里看不出来。
#
# 尤其是 market:price_fallback / market:kline_backfill / market:funding：忘了启动**不会有任何即时告警**，
# 而后果是价格没有兜底、K 线缺口再也没人补、资金费率彻底断供（那是费率的唯一供数通道，不是兜底），
# 要到 8 小时后结算时才暴露。
#
# 清单由 MarketWorkers::expected() 提供，与运行期的心跳告警共用同一份 ——
# 不在本脚本里另抄一遍（抄两份必然漂移）。
sleep 5   # CommandHeartBeatService::start() 会立刻写第一次心跳，等进程 fork 完即可
if ! $PHP_BIN "$THINK_BIN" command_heartbeat:check_expire --verify; then
    echo ""
    echo "[Error] 上面标 [MISS] 的进程没有起来，行情链路是残的，请立刻处理。"
    echo "        逐个查启动日志：root/runtime/log/<worker名>.log"
    exit 1
fi
