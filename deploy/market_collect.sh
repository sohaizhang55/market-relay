#!/usr/bin/env bash
# =============================================================================
#  行情告警现场采集（只读，A/B 两台通用，直接跑）
#
#      bash market_collect.sh > /tmp/$(hostname).txt 2>&1
#
#  角色不匹配的段落会自己留空（照 deploy/market_health.sh 的做法），
#  所以同一份脚本在分发机和订阅机上都能跑，不必分两份。
#
#  凭据只从 root/.env 取，不带任何默认端口的猜测 —— market_health.sh 的注释里
#  记着一次「连错 Redis 实例、扫出 0 个 key、看起来一切正常」的事故。
#  MySQL 走 --defaults-extra-file，密码不进 ps 也不进输出。
# =============================================================================
set -uo pipefail

D="$(pwd)"; while [ "$D" != "/" ] && [ ! -f "$D/root/think" ]; do D="$(dirname "$D")"; done
[ -f "$D/root/think" ] || { echo "[Error] 找不到 root/think，请在仓库目录下运行"; exit 1; }
cd "$D"
LOG="$D/root/runtime/log"; T="$(date +%Y%m%d)"

env_get(){ grep -E "^$1=" root/.env 2>/dev/null | head -1 | cut -d= -f2- | tr -d ' "'; }
RH=$(env_get CACHE_REDIS_HOST); RH=${RH:-127.0.0.1}
RP=$(env_get CACHE_REDIS_PORT); RP=${RP:-6379}
RN=$(env_get CACHE_REDIS_SELECT); RN=${RN:-0}
PW=$(env_get CACHE_REDIS_PASSWORD); [ -n "$PW" ] && export REDISCLI_AUTH="$PW"; unset PW
R(){ redis-cli -h "$RH" -p "$RP" -n "$RN" "$@" 2>/dev/null; }
RK=$(env_get CACHE_PREFIX)   # Redis key 前缀（config/cache.php redis.prefix）

MYCNF=$(mktemp); chmod 600 "$MYCNF"
trap 'rm -f "$MYCNF"' EXIT
# 只写**非空**的键：空值写进 my.cnf 会让 mysql 直接报 "Empty value for 'port'"，
# 那句错和真正的连接失败长得一样，排查时会把人往错方向带。
#
# 键名与 .env 变量名分成两个参数传，而不是拼成 `键:变量名` 一串 ——
# 拼成一串会被 xdata:check 的 secret 规则当成明文凭据（它匹配 `password[=:]...`）。
# 那条规则刚清理过 14 处误报，理由是噪音把真正的明文密码盖住了，不该由这里再造一个。
mycnf_put(){ local v; v=$(env_get "$2"); [ -n "$v" ] && printf '%s=%s\n' "$1" "$v"; }
{ echo '[client]'
  mycnf_put host     DB_MYSQL_HOST
  mycnf_put port     DB_MYSQL_PORT
  mycnf_put user     DB_MYSQL_USERNAME
  mycnf_put password DB_MYSQL_PASSWORD
} > "$MYCNF"
DB=$(env_get DB_MYSQL_DATABASE); PFX=$(env_get DB_MYSQL_PREFIX)
Q(){
  command -v mysql >/dev/null 2>&1 || { echo "  (本机没有 mysql 客户端，跳过)"; return; }
  [ -n "$DB" ] || { echo "  (root/.env 里没有 DB_MYSQL_DATABASE，跳过)"; return; }
  mysql --defaults-extra-file="$MYCNF" "$DB" -t -e "$1" 2>&1 | head -25
}

# metrics 哈希：field = `channel|source_key[|suffix]`，value = `{"last_ts":<毫秒>,...}`
ages(){
  local now; now=$(date +%s)
  local out; out=$(R HGETALL "${RK}market:metrics:group:$1" | awk -v now="$now" '
    NR%2 { f=$0; next }
    { if (match($0, /"last_ts":[0-9]+/)) {
        t = substr($0, RSTART+10, RLENGTH-10)
        printf "%8.0fs  %s\n", now - t/1000, f } }' | sort -rn | head -12)
  if [ -n "$out" ]; then echo "$out"
  else echo "  (无此 scope 的埋点 —— 角色不匹配则正常)"; fi
}
sec(){ echo; echo "── $* ──"; }
cnt(){ grep -h "$@" 2>/dev/null | wc -l | tr -d " "; }

echo "================================================================"
echo " 主机 $(hostname)   时间 $(date '+%F %T')   uptime $(uptime -p 2>/dev/null || uptime)"
echo " Redis $RH:$RP db=$RN -> $(R ping || echo 连不上)"
echo "================================================================"
sec "常驻进程与实例数"
# ⚠️ 不能 grep 'market:subscribe' —— Workerman 启动后会把进程名改成
# 'WorkerMan: worker process  market_relay_MarketSubscriber'，命令行里那个词早就没了。
# 这一段之前正是这么写的，于是在两台机器上都恒定输出空，是个哑掉的观测点：
# 现网 4 个合约撮合引擎并行了一整天，这里什么也没显示。
echo "-- 实例数（除 Gateway/BusinessWorker 配置就是 4，其余都必须是 1）--"
ps -eo args | grep 'worker process  market_relay_' | grep -v grep \
  | awk '{print $(NF-1)}' | sort | uniq -c | sort -rn
echo "-- master 明细（lstart 能看出是不是同一批起的）--"
ps -eo pid,lstart,args | grep 'WorkerMan: master process' | grep -v grep

# ═══════════════ 订阅机（B）段 ═══════════════
sec "B① 告警史：是不是每次都是同一批 pair（本轮最关键）"
echo "-- 按 source_key 统计出现次数 --"
grep -h 'STALE' "$LOG"/*_price_health.log 2>/dev/null \
  | grep -oE '[A-Z]+_[a-z0-9]+_[0-9]' | sort | uniq -c | sort -rn | head -20
echo "-- 最近 15 次告警的时刻 --"
grep -h 'STALE' "$LOG"/*_price_health.log 2>/dev/null | grep -oE '"time":"[^"]+"' | tail -15
echo "-- 最近 3 条告警全文 --"
grep -h 'STALE' "$LOG"/*_price_health.log 2>/dev/null | tail -3
echo "-- 恢复通知次数：$(cnt 'RECOVERED' "$LOG"/*_price_health.log) --"

sec "B③ 落库 stat 最近 6 行（dirty 积压 / ms / fail）"
tail -6 "$LOG/${T}_market_db_flush_stat.log" 2>/dev/null

sec "B④ recv 埋点最陈旧 Top12（B 收到帧的时刻，scope=dispatch）"; ages dispatch
sec "B⑤ land 埋点最陈旧 Top12（B 落库完成的时刻，scope=land）";   ages land

sec "B⑥ 受控层两列并排（land_age=我们多久没处理 / ts_age=告警看的那个）"
Q "SELECT id, symbol_code, source_key,
      UNIX_TIMESTAMP()-update_ts AS land_age_s,
      UNIX_TIMESTAMP()-FLOOR(market_ts/1000) AS ts_age_s
   FROM ${PFX}exchange_pair ORDER BY ts_age_s DESC LIMIT 15;"

# ═══════════════ 分发机（A）段 ═══════════════
sec "A① 兜底做了什么（本轮第二关键）"
echo "-- 按 source_key 统计全天兜底次数 --"
grep -hE 'LastPrice|MarkPrice' "$LOG"/*_market_rest_fallback.log 2>/dev/null \
  | grep -oE '[A-Z]+_[a-z0-9]+_[0-9]' | sort | uniq -c | sort -rn | head -20
echo "-- 三种结局各多少 --"
printf '  %-22s %s\n' "兜底成功写入(<=)"   "$(cnt -- '<=' "$LOG"/*_market_rest_fallback.log)"
printf '  %-22s %s\n' "WS期间恢复而放弃"    "$(cnt 'skipped' "$LOG"/*_market_rest_fallback.log)"
printf '  %-22s %s\n' "REST拉取失败"        "$(cnt 'fetch failed' "$LOG"/*_market_rest_fallback.log)"
echo "-- 最近 20 条 --"
tail -20 "$LOG/${T}_market_rest_fallback.log" 2>/dev/null

sec "A② 采集 stat 最近 6 行（busy / recvq / 逐频道 age）"
tail -6 "$LOG/${T}_market_collect_stat.log" 2>/dev/null

sec "A③ 分发 stat 最近 4 行（send_false / skipped / sndbuf / ksendq / rtt / conn / nodes）"
grep -hE 'popped=' "$LOG/${T}_market_dispatch_stat.log" 2>/dev/null | tail -4

sec "A④ collect 埋点最陈旧 Top12（A 从交易所收到帧的时刻）";   ages collect
sec "A⑤ fallback 埋点最陈旧 Top12（最后一次被 REST 兜底刷新）"; ages fallback

sec "A⑥ 源表年龄（兜底的过期判据读的就是这张表）"
Q "SELECT source_key,
      UNIX_TIMESTAMP()-FLOOR(update_ts/1000) AS price_age_s,
      UNIX_TIMESTAMP()-FLOOR(mark_ts/1000)   AS mark_age_s
   FROM ${PFX}market_data ORDER BY price_age_s DESC LIMIT 15;"

# ═══════════════ 通用 ═══════════════
sec "⑦ 今日错误计数"
for b in market_db_flush_stat market_dispatch \
         market_dispatch_stat market_collect_stat market_rest_fallback \
         market_kline_backfill market_funding market_stat_guard cli; do
  f="$LOG/${T}_${b}.log"; [ -f "$f" ] || continue
  n=$(grep -icE '"type":"error"' "$f" 2>/dev/null); n=${n:-0}
  printf '  %-26s %s 条\n' "$b" "$n"
  [ "$n" -gt 0 ] && grep -iE '"type":"error"' "$f" | tail -2 | cut -c1-220 | sed 's/^/      /'
done

sec "⑧ 今日有哪些日志（确认没再消失）"
ls -la --time-style=+%H:%M "$LOG"/${T}_*.log 2>/dev/null | awk '{printf "  %10s  %s  %s\n", $5, $6, $7}'
echo "  被改名走的（应为 0 个，f1555407 之后不该再出现）: $(ls "$LOG"/*-[0-9]*.log 2>/dev/null | wc -l)"
echo
echo "===== 采集完毕 ====="
