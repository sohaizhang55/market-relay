#!/usr/bin/env bash
# =============================================================================
#  行情链路体检（只读，不改任何东西）—— A/B/C 三种角色通用，直接跑即可
#
#      bash deploy/market_health.sh
#
#  为什么要有这个脚本，而不是每次现敲命令：
#
#  上线那天靠手敲命令验收，连着踩了两个坑，两个都是「命令跑了、不报错、
#  但什么也没做」——
#
#    · 清 Redis 热层残留时用了 `redis-cli -n 0`，而那台机器的 Redis 在 6380。
#      连的是另一个实例，扫出 0 个 key，看起来一切正常，实际一条没清。
#      直到 DropBbo 迁移跑完，落库才因为 Unknown column 'bbo_bid' 整批失败。
#    · 找日志时按 `runtime/log/{日期}/` 找，而实际路径是
#      `runtime/log/{日期}_{业务名}.log`，于是「日志是空的」被误读成「没数据」。
#
#  所以这里把两件事钉死：
#    1. Redis 连接参数**只从 root/.env 取**，不带任何默认端口的猜测；
#    2. 每一项都打印**数量**而不只是有没有报错——「清了 0 个」和「清了 44 个」
#       必须一眼能分开。
#
#  退出码：0 = 全部正常；1 = 有需要处理的项（末尾会列出来）
# =============================================================================
set -uo pipefail

# ── 定位仓库根（含 root/think 的那一层）────────────────────────────────────
D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [ "$D" != "/" ] && [ ! -f "$D/root/think" ]; do D="$(dirname "$D")"; done
[ -f "$D/root/think" ] || { echo "[Error] 找不到 root/think，请把本脚本放在仓库的 deploy/ 下"; exit 1; }
cd "$D"

# PHP 解释器：优先用 deploy/config.env 里 restart_market.sh 同款的 PHP_BIN
[ -f "$D/deploy/config.env" ] && source "$D/deploy/config.env"
PHP="${PHP_BIN:-$(command -v php8.3 || command -v php)}"
LOG="$D/root/runtime/log"
TODAY="$(date +%Y%m%d)"
PROBLEMS=()

echo "=========================================="
echo "  行情链路体检  $(date '+%F %T')"
echo "=========================================="
echo "仓库: $D"
echo "PHP : $PHP"

# ── Redis：参数取自 root/.env，取不到则回落到应用同一套默认值 ─────────────
#
# 原注释写的是「只从 root/.env 取，不带任何默认端口的猜测」，与代码不符 ——
# 代码一直有 ${RH:-127.0.0.1} 这类回落。回落本身是对的：config/cache.php:72-74
# 的默认值就是 127.0.0.1/6379/0，删掉反而会在 .env 没配时连不上。
# 错的是看的人分不清眼前这个端口是配出来的还是回落出来的 —— 当年连错 6380
# 实例正是栽在这一点上。所以保留回落，但把来源标出来。
env_get() { grep -E "^$1=" root/.env 2>/dev/null | head -1 | cut -d= -f2- | tr -d ' "'; }
RDEF=""
RH="$(env_get CACHE_REDIS_HOST)";   [ -n "$RH" ] || { RH=127.0.0.1; RDEF="$RDEF host"; }
RP="$(env_get CACHE_REDIS_PORT)";   [ -n "$RP" ] || { RP=6379;      RDEF="$RDEF port"; }
RD="$(env_get CACHE_REDIS_SELECT)"; [ -n "$RD" ] || { RD=0;         RDEF="$RDEF db"; }
R() { redis-cli -h "$RH" -p "$RP" -n "$RD" "$@" 2>/dev/null; }
# Redis key 前缀（config/cache.php redis.prefix），字面量 key 与 --pattern 都要带上；scan 出来的 key 已是全名
RK="$(env_get CACHE_PREFIX)"
PONG="$(R ping)"
echo "Redis: $RH:$RP db=$RD -> ${PONG:-连不上}"
[ -n "$RDEF" ] && echo "   注意：${RDEF# } 取的是默认值（root/.env 里没有对应的 CACHE_REDIS_* 行），与 config/cache.php 的默认一致"
[ "$PONG" = "PONG" ] || PROBLEMS+=("Redis 连不上（参数来源见上一行标注，确认 CACHE_REDIS_* 与实例是否一致）")

# ── ① 按 mode 该常驻的进程 ─────────────────────────────────────────────────
echo
echo "── ① 按 mode 该常驻的进程 ─────────────────────────────"
if $PHP root/think command_heartbeat:check_expire --verify 2>&1 | sed 's/^/   /'; then :; else
    # --verify 现在有两种失败：[MISS]=没在跑，[DUP]=跑了好几套。
    # 两者处置方向相反，措辞不能只写其中一种——现网出过 4 个合约撮合引擎
    # 同时撮同一个订单簿，而当时这里只会说「没有心跳」
    PROBLEMS+=("① 有进程没起来（[MISS]）或起了多套（[DUP]）—— 看上面那几行标记")
fi

# ── ② 热层 bbo 残留 ────────────────────────────────────────────────────────
echo
echo "── ② 热层 bbo 残留（DropBbo 迁移的前提，必须是 0）──────"
BBO=0
while read -r k; do
    [ -z "$k" ] && continue
    R HKEYS "$k" | grep -q '^bbo' && BBO=$((BBO+1))
done < <(R --scan --pattern "${RK}market:hot:md:*")
echo "   残留 key: $BBO"
if [ "$BBO" -gt 0 ]; then
    PROBLEMS+=("热层还有 $BBO 个 key 带 bbo 字段 —— 跑过 DropBbo 的机器上这会让落库整批失败")
    echo "   清理："
    echo "     redis-cli -h $RH -p $RP -n $RD --scan --pattern '${RK}market:hot:md:*' | while read -r k; do"
    echo "       redis-cli -h $RH -p $RP -n $RD HDEL \"\$k\" bbo_bid bbo_ask bbo_ts >/dev/null; done"
fi

# ── ③ 迁移 ─────────────────────────────────────────────────────────────────
echo
echo "── ③ 迁移 ─────────────────────────────────────────────"
DOWN="$($PHP root/think migrate:status 2>/dev/null | grep -cE '^\s+down')"
echo "   待跑: ${DOWN:-?}"
[ "${DOWN:-0}" -gt 0 ] && PROBLEMS+=("还有 ${DOWN} 条迁移没跑（php think migrate:run）")

# ── ③b 活库结构 ────────────────────────────────────────────────────────────
#
# ③ 只回答"迁移记录在不在"，回答不了"库真的长这样"。两者能不一致：
# 手工改过表、迁移在别的库跑的、或者迁移跑了但被后来的改动覆盖回去。
# 而 bbo 列这一条是硬约束 —— 热层已清、库还留着列，或者反过来，
# 都会让落库整批失败（现网发生过约 15 分钟）。
#
# 用 php think 而不是 mysql 命令行：凭据由应用从 root/.env 读，
# 不必在这个脚本里出现明文密码。
echo
echo "── ③b 活库结构（bbo 列 / 精度 / 列注释）──────────────"
if $PHP root/think market:schema_check 2>&1 | sed 's/^/   /'; then :; else
    PROBLEMS+=("活库的 market_data 结构不符合链路依赖（见 ③b 的 [FAIL]）")
fi

# ── ③c 配置漂移 ────────────────────────────────────────────────────────────
#
# config/market.php 是 gitignore 的：每台机器一份、拷贝一次之后再也不会自动跟进。
# 仓库里改了默认值，已经在跑的机器**永远停在老默认上**，而 git pull 一声不吭。
# 本机有意不同的旋钮（端口、对端地址、密钥）在那一行上方写
#   // @config-drift-ok: MARKET_XXX —— 理由
# 即可豁免。
echo
echo "── ③c 配置漂移（本机 config/market.php vs sample）────"
if $PHP root/think market:config_check 2>&1 | sed 's/^/   /'; then :; else
    PROBLEMS+=("market:config_check 报告问题（见 ③c 的 [DRIFT] 或标 x 的行）")
fi

# ── ③f 两层 K 线表对账 ─────────────────────────────────────────────────────
#
# App 端 K 线图读的是 exchange_pair_kline（受控层），而补数写的是 market_source_kline
# （源层）。两层之间有一条同步链路，它断掉时源层一切正常、图表却不动 ——
# 从行情侧的任何指标都看不出来。
#
# 只做高水位比对（各一条聚合查询，索引覆盖扫描），不逐行对。
echo
echo "── ③f 两层 K 线表对账（受控层跟不跟得上源层）────────"
if $PHP root/think market:kline_reconcile --limit=5 2>&1 | sed 's/^/   /'; then :; else
    PROBLEMS+=("受控层 K 线跟不上源层（见 ③f）—— App 端 K 线图读的正是受控层")
fi

# ── ③g 休市日历覆盖 ────────────────────────────────────────────────────────
#
# 假日数据是一年一续的（导出脚本默认只出今明两年）。它到期那天不会有任何报错 ——
# 表里依然有几十条假日、后台依然查得到行，只是全部落在过去了，tradfi 标的从此
# 节假日照常开盘。这个失效会在某个跨年的凌晨自己发生，没有任何人为动作可以关联，
# 数量上也完全看不出来（条数一条没少），只能靠定期查到期日发现。
#
# 用 --dry-run：只读不写库，符合本脚本「不改任何东西」的前提，体检照跑。
echo
echo "── ③g 休市日历覆盖（假日数据还剩多久到期）──────────"
if $PHP root/think market:import_calendar --dry-run 2>&1 | sed 's/^/   /'; then :; else
    PROBLEMS+=("休市日历有问题（见 ③g）—— 数据到期或缺失时，tradfi 标的会节假日照常开盘")
fi

# ── ④ 行情新鲜度（读热层，不需要 MySQL 凭据）───────────────────────────────
echo
echo "── ④ 行情新鲜度（热层 update_ts，采样 ≤200 个源）──────"
NOW_MS=$(( $(date +%s) * 1000 ))
CNT=0; STALE=0; WORST=0; WORST_KEY=""; NOTS=0
while read -r k; do
    [ -z "$k" ] && continue
    CNT=$((CNT+1)); [ "$CNT" -gt 200 ] && break
    ts="$(R HGET "$k" update_ts)"
    # update_ts 是**毫秒**时间戳，真实值一定 >= 1e12（2001 年之后）。
    # 小于它的（0 / 1 / 占位值）代表「从来没收到过数据」——刚配好的新交易对、
    # 后台加了但采集器还没订阅上、或历史测试残留。那是待接入，不是陈旧。
    # 不区分的话 (now-1)/1000 会算出十几亿秒的年龄，把整份报告的可信度毁掉。
    case "$ts" in ''|*[!0-9]*) continue;; esac
    [ "$ts" -lt 1000000000000 ] && { NOTS=$((NOTS+1)); continue; }
    age=$(( (NOW_MS - ts) / 1000 ))
    if [ "$age" -gt "$WORST" ]; then WORST="$age"; WORST_KEY="${k##*:}"; fi
    [ "$age" -gt 300 ] && STALE=$((STALE+1))
done < <(R --scan --pattern "${RK}market:hot:md:*")
echo "   采样 $CNT 个源，最陈旧 ${WORST}s (${WORST_KEY:-无})，超过 5 分钟的 $STALE 个"
[ "$NOTS" -gt 0 ] && echo "   另有 $NOTS 个源从未收到过数据（update_ts 不是有效毫秒时间戳），新配的交易对属正常"
[ "$STALE" -gt 0 ] && PROBLEMS+=("有 $STALE 个行情源超过 5 分钟没更新（先看 ① 的采集器/订阅器是否在跑）")

# ── ⑤ 分发 / 订阅 stat ─────────────────────────────────────────────────────
echo
echo "── ⑤ 分发 / 订阅 stat（各取最后 1 行）────────────────"
for b in market_dispatch_stat market_subscribe_stat; do
    f="$LOG/${TODAY}_${b}.log"
    if [ -f "$f" ]; then
        echo "   [$b]"
        # 按 stat 行的特征取，不能盲取最后一行 ——
        # 同一个频道里还有启动日志、看门狗告警等，盲取会拿到一条没有 subs=/frames= 的行，
        # 看的人以为进程没在上报（这个坑在生产上兑现过一次）
        line=$(grep -E 'popped=|frames=' "$f" | tail -1)
        if [ -n "$line" ]; then echo "$line" | sed 's/^/     /'
        else echo "     （今天还没有 stat 行 —— 进程刚起来不到一个上报周期，或它根本没在上报）"; fi
    else
        echo "   [$b] 本机无此日志（角色不匹配则正常）"
    fi
done
echo "   分发机看 subs=（订阅机台数）与 kicked(hb=)（应恒为 0）；订阅机看 frames= 与 busy="

# ── ⑤b 采集器 stat ─────────────────────────────────────────────────────────
# 采集器不写「订阅成功」这类日志 —— 分片下发、Subscribed N channels、
# WebSocket Closed 全在 `if (!Worker::$daemonize)` 里，生产 daemon 模式下都丢了。
# 这条 stat 是唯一的替代证据。读它要先知道两件事，否则会得出反的结论：
#
#   · age() 只覆盖 price / depth / mark_price 三个频道，kline 和 trade 是
#     touchAge() 明确排除的（蜡烛起始时刻、每笔成交自身时间，采进来是无意义的大数）。
#     所以 age() 里**本来就不会有 kline** —— 别拿它判断 K 线连接死活。
#     币安现货的 partial depth 不带时间戳，同理它的 age() 只有 price。
#   · busy 的 msg:* 分桶才是按 WS 连接分的（桶名 = 连接名），且每 10 秒清零。
#     K 线在币安是独立一条连接，被踢掉就只有它掉，价格流照常、热层照常新鲜，
#     ④ 完全看不出来 —— 要看的是 msg:Spot-Kline / msg:Swap-Kline 还在不在。
#     注意占比不足 1% 的桶会被 loopBusySnapshot() 隐藏，所以缺桶不做自动判定。
echo
echo "── ⑤b 采集器 stat（每 worker 最后 1 行）──────────────"
f="$LOG/${TODAY}_market_collect_stat.log"
if [ -f "$f" ]; then
    FOUND=0
    for w in market_relay_Crawler_binance_Spot market_relay_Crawler_binance_Swap \
             market_relay_Crawler_huobi_Spot market_relay_Crawler_huobi_Swap \
             market_relay_Crawler_bingx_Swap; do
        # 同频道里还有 trade_window_overflow 那种非 stat 行，按 busy= 认，理由同 ⑤
        line=$(grep -F "[$w]" "$f" 2>/dev/null | grep -E 'busy=' | tail -1)
        [ -n "$line" ] || continue
        FOUND=$((FOUND+1))
        echo "$line" | sed 's/^/     /'
        # 唯一能自动判的：一个频道都没采到 = 这个 worker 的连接全断了
        # BingX 外汇 / 取暖油休市时本就没有任何帧，age(-) 不算问题
        [ "$w" = market_relay_Crawler_bingx_Swap ] && continue
        case "$line" in *'age(-)'*) PROBLEMS+=("$w 上报窗口内一帧都没收到（age(-)）—— 连接断了或订阅没建起来");; esac
    done
    [ "$FOUND" -eq 0 ] && echo "   （今天还没有 stat 行 —— 进程刚起不到一个上报周期，或它根本没在上报）"
else
    echo "   本机无 market_collect_stat 日志（非采集角色则正常）"
fi
echo "   人工看两处：busy 的 msg:* 分桶 = 各 WS 连接（币安应有 Spot / Spot-Kline / Swap-Public / Swap-Kline，缺哪条就是哪条停了）；"
echo "   age() 的 max 持续 >60000ms 是积压。age() 不含 kline/trade 属设计，symbols= 只是配置面源数、不代表订阅成功"

# ── ⑥ 最近的错误 ───────────────────────────────────────────────────────────
echo
echo "── ⑥ 今日错误计数 ─────────────────────────────────────"
ERRS=0
for b in cli market_subscribe market_dispatch market_db_flush_stat market_rest_fallback; do
    # 日志超过 2MB 会被 File 驱动改名切走，切片形如 {日期}_{业务}-{时间戳}.log。
    # 只读未切片的那一个会漏掉当天绝大部分内容 —— 08-31 那天 509 条错误里
    # 有 508 条在切片文件中，体检报的 502 让人误以为是零散小毛病，
    # 实际是一次集中在两小时内的爆发。
    # 不能图省事写 ${b}*.log：market_subscribe 会把 market_subscribe_stat 一起吃进来。
    files=()
    for g in "$LOG/${TODAY}_${b}.log" "$LOG/${TODAY}_${b}"-*.log; do
        [ -f "$g" ] && files+=("$g")
    done
    [ "${#files[@]}" -gt 0 ] || continue
    n="$(cat "${files[@]}" | grep -ciE '"type":"error"|Unknown column|heartbeat timeout' || true)"
    n="${n:-0}"
    printf '   %-24s %s 条' "$b" "$n"
    [ "${#files[@]}" -gt 1 ] && printf '（跨 %d 个文件，含切片）' "${#files[@]}"
    printf '\n'
    if [ "$n" -gt 0 ]; then
        ERRS=$((ERRS+n))
        cat "${files[@]}" | grep -iE '"type":"error"|Unknown column|heartbeat timeout' | tail -2 | sed 's/^/     /'
    fi
done
[ "$ERRS" -gt 0 ] && PROBLEMS+=("今日日志里有 $ERRS 条错误，见 ⑥")

# ── ⑥b MySQL 连接中断 ──────────────────────────────────────────────────────
#
# 单独拎出来，不跟 ⑥ 的总数混在一起。理由是它的**第一处置步骤很特别**，
# 而只看到「今日有 24 万条错误」的人不会想到那一步。
#
# 现网这条链跑过一次：常驻进程被叠成了好几套（restart 没杀干净旧的）→ 33 秒后
# `2006 MySQL server has gone away` 开始爆发 → 连续 1.5 小时、24.6 万条 →
# 清掉重复实例的那一刻停止，此后再没出现过。
#
# 而当时 MySQL 服务端一切正常：没重启、Aborted_clients=0、连接数 67/151、
# wait_timeout 8 小时、max_allowed_packet 64MB。也就是说**服务端什么都没看到**，
# 断的是客户端到服务端那一段（MySQL 在 Docker 里，走 127.0.0.1 的发布端口，
# 中间隔着 docker-proxy）。
#
# 所以看到这条时，第一步不是去查 MySQL，是**数实例**。
echo
echo "── ⑥b MySQL 连接中断（稳态必须为 0）────────────────────"
GONE=0
f="$LOG/${TODAY}_cli.log"
if [ -f "$f" ]; then
    GONE="$(grep -c 'server has gone away' "$f" 2>/dev/null || true)"; GONE="${GONE:-0}"
fi
echo "   今日 'server has gone away'：${GONE} 条"
if [ "$GONE" -gt 0 ]; then
    echo "   最近两条："
    grep 'server has gone away' "$f" | tail -2 | cut -c1-200 | sed 's/^/     /'
    echo "   ⚠️ 第一步先数实例，不是先查 MySQL："
    echo "      ps -eo args | grep 'worker process  market_relay_' | awk '{print \$(NF-1)}' | sort | uniq -c"
    echo "      除 Gateway/BusinessWorker（配置就是 4）外，其余每个都必须是 1"
    PROBLEMS+=("有 ${GONE} 条 MySQL 连接中断（⑥b）—— 先数常驻进程实例数，多套并行是已知诱因")
fi

# ── ⑥c 日志文件数 ─────────────────────────────────────────────────────────
#
# ThinkPHP File 驱动的 max_files 是「文件数超了就 unlink 掉 glob 排最前面的那个」，
# 静默删除、不写任何提示。而 business_log() 动态注册的通道并不继承
# config/log.php 里 channels.file 的配置（每个通道各自独立），用的是驱动默认值
# —— file_size 2MB（所以才有那么多切片）、max_files 0；真正在删文件的是默认
# file 通道的 max_files，它 glob 的是整个目录，切片文件同样占配额。
# 两者叠加的结果：切片越多、保留天数越短，而且没有任何征兆。
echo
echo "── ⑥c 日志文件数（逼近 max_files 会静默删旧日志）────"
LOGN="$(ls "$LOG"/*.log 2>/dev/null | wc -l | tr -d ' ')"
MAXF="$(grep -oE "'max_files'[^0-9]*[0-9]+" root/config/log.php 2>/dev/null | grep -oE '[0-9]+$' | head -1)"
MAXF="${MAXF:-0}"
echo "   现有 ${LOGN} 个 .log，config/log.php 的 max_files=${MAXF}"
echo "   保留的日期: $(ls "$LOG"/*.log 2>/dev/null | sed 's#.*/##' | grep -oE '^[0-9]{8}' | sort -u | tr '\n' ' ')"
if [ "$MAXF" -gt 0 ] && [ "$LOGN" -ge "$MAXF" ]; then
    echo "   最早的 3 个（下次写日志就会删掉排最前面的）："
    ls "$LOG"/*.log 2>/dev/null | head -3 | sed 's#.*/#     #'
    PROBLEMS+=("日志文件数 ${LOGN} 已达 max_files=${MAXF} —— 每写一次日志就静默删掉一个最旧文件，能回溯的窗口正在缩短")
fi

# ── 汇总 ───────────────────────────────────────────────────────────────────
echo
echo "=========================================="
if [ ${#PROBLEMS[@]} -eq 0 ]; then
    echo "  [OK] 全部正常"
    echo "=========================================="
    exit 0
fi
echo "  [需要处理] ${#PROBLEMS[@]} 项"
for p in "${PROBLEMS[@]}"; do echo "   · $p"; done
echo "=========================================="
exit 1
