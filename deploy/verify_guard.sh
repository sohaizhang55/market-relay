#!/usr/bin/env bash
# =============================================================================
#  单例守卫验证（自带清理与还原，可重复跑）
#      bash /tmp/verify_guard.sh
#  用 market:kline_backfill -e huobi 做靶子：停几秒只是 K 线补数晚一轮，无业务影响。
# =============================================================================
set -uo pipefail

W=market_relay_MarketKlineBackfill_huobi

# 仓库根：向上找含 root/think 的那一层。与 market_health.sh / market_collect.sh 同款。
# 上一版用的是 $HOME/market-relay —— 在真机上 git -C 直接报 not a git repository，
# 而「部署校验」这一段恰恰是全脚本最不能出错的地方（前两轮就栽在测了旧代码上）
D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [ "$D" != "/" ] && [ ! -f "$D/root/think" ]; do D="$(dirname "$D")"; done
[ -f "$D/root/think" ] || { echo "[Error] 找不到 root/think，请把本脚本放在仓库的 deploy/ 下"; exit 1; }
cd "$D/root" || exit 1
PIDF="$D/root/runtime/${W}.pid"
PHP="$(command -v php8.3 || command -v php)"

env_get(){ grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d ' "'; }
RH=$(env_get CACHE_REDIS_HOST); RH=${RH:-127.0.0.1}
RP=$(env_get CACHE_REDIS_PORT); RP=${RP:-6379}
RN=$(env_get CACHE_REDIS_SELECT); RN=${RN:-0}
PW=$(env_get CACHE_REDIS_PASSWORD); [ -n "$PW" ] && export REDISCLI_AUTH="$PW"; unset PW
R(){ redis-cli -h "$RH" -p "$RP" -n "$RN" "$@" 2>/dev/null; }
RK=$(env_get CACHE_PREFIX)   # Redis key 前缀（config/cache.php redis.prefix）

# worker 数 / master 列表（master 才是 pid 文件里那个）
n_workers(){ ps -eo args | grep "worker process  ${W}" | grep -v grep | wc -l | tr -d ' '; }
masters(){ ps -eo pid,ppid,args | grep "worker process  ${W}" | grep -v grep | awk '{print $2}'; }

echo "════════ ① 部署校验 ════════"
#
# ⚠️ 这里**只问运行时，不 grep 源码**。
#
# 生产上的 PHP 源码是 phpBolt 加密的（deploy/encode/encode_all.php：源码目录 → 加密
# → 输出到 ../pub），文件头长这样：
#     <?php bolt_decrypt(__FILE__,PHP_BOLT_KEY);return 0;
#     ##!!!##LS2HiIaPhJWIS5aXlYyGl4KXnJOIlmBUTF4...
# 于是 `grep -c guardSingleton xxx.php` **必然返回 0**，哪怕代码跑得好好的。
# 上一版正是这么写的，真机上恒定报「0 处 = 没部署」，而守卫其实生效着——
# 一个会说谎的检查比没有检查更糟，它会让人把好的当成坏的。
# 同理 `[ -f SingletonGuardTrait.php ]` 也没意义：文件名存在证明不了内容是新的。
#
# 唯一可信的判据是**运行中的进程留下的痕迹**：heartbeat:inst:* 这个 key
# 只有新版心跳才会写，旧代码根本不认识它。
printf '  仓库根        : %s\n' "$D"
printf '  实例注册表    : %s 个 key（0 = 进程还是旧代码起的）\n' \
    "$(R --scan --pattern "${RK}heartbeat:inst:*" | wc -l | tr -d ' ')"
printf '  本命令实例数  : %s（应为 1）\n' "$(R HLEN "${RK}heartbeat:inst:${W}" | tr -d ' ')"
echo "  （前者为 0：先 bash deploy/restart_market.sh；仍为 0 则是 git pull 之后没跑加密步骤）"

echo
echo "════════ ② 起始状态（有重复先清掉）════════"
echo "  worker 数: $(n_workers)"
KEEP=$(cat "$PIDF" 2>/dev/null || echo 0)
echo "  pid 文件 : ${KEEP}"
for m in $(masters); do
    if [ "$m" != "$KEEP" ]; then
        echo "  清理孤儿 master=$m"
        kill -SIGINT "$m" 2>/dev/null
    fi
done
sleep 3
echo "  清理后 worker 数: $(n_workers)   （应为 1）"
[ "$(n_workers)" = "1" ] || { echo "  [中止] 起始状态不是 1 个，先人工理干净再跑"; exit 1; }

# ⚠️ 杀孤儿会把 pid 文件一起带走：Worker.php:1929 在 master 退出时
# `@unlink(static::$pidFile)`，**不检查这个 pid 文件是不是自己的**，
# 而同名命令的所有实例共用同一个路径。于是清理完幸存者反而变成了新的孤儿——
# 这正是「一旦有了重复就会自我放大」的机制。这里按幸存 master 补回来。
if [ ! -f "$PIDF" ]; then
    SURV=$(masters | head -1)
    if [ -n "$SURV" ]; then
        echo "$SURV" > "$PIDF"
        echo "  pid 文件被孤儿退出时删掉了，已按幸存 master=$SURV 补回"
    fi
fi

echo
echo "════════ ③ 造孤儿：把 pid 文件挪走 ════════"
mv "$PIDF" /tmp/vg_bk.pid && echo "  已挪走（进程还在跑，但 pid 文件指不到它）"

echo
echo "════════ ④ 再 start —— 守卫该在这里拦住 ════════"
"$PHP" think market:kline_backfill start -e huobi -d >/tmp/vg_out.txt 2>&1
RC=$?
sed 's/^/    | /' /tmp/vg_out.txt
N_AFTER=$(n_workers)
echo "  退出码   : ${RC}      （期望 4）"
echo "  worker 数: ${N_AFTER}      （期望 1）"

echo
echo "════════ ⑤ 判活侧 ════════"
"$PHP" think command_heartbeat:check_expire --verify; VRC=$?
echo "  退出码: ${VRC}"

echo
echo "════════ ⑥ 还原 ════════"
[ -f /tmp/vg_bk.pid ] && mv /tmp/vg_bk.pid "$PIDF" && echo "  pid 文件已还原"
KEEP=$(cat "$PIDF" 2>/dev/null || echo 0)
for m in $(masters); do
    if [ "$m" != "$KEEP" ]; then echo "  清掉测试产生的 master=$m"; kill -SIGINT "$m" 2>/dev/null; fi
done
sleep 3
# 同上：清理动作可能把 pid 文件带走，进而让下一轮把幸存者也当孤儿。
# 收尾必须保证服务是起着的 —— 补数器停着会让 K 线缺口没人补
if [ "$(n_workers)" = "0" ]; then
    echo "  服务被收尾清空了，重新拉起"
    "$PHP" think market:kline_backfill start -e huobi -d >/dev/null 2>&1
    sleep 2
elif [ ! -f "$PIDF" ]; then
    SURV=$(masters | head -1)
    [ -n "$SURV" ] && { echo "$SURV" > "$PIDF"; echo "  补回 pid 文件 master=$SURV"; }
fi
echo "  最终 worker 数: $(n_workers)   （应为 1）"
echo "  pid 文件      : $(cat "$PIDF" 2>/dev/null || echo 缺失)   （应等于上面那个 master）"

echo
echo "════════ 结论 ════════"
if [ "$RC" = "4" ] && [ "$N_AFTER" = "1" ]; then
    echo "  ✅ 守卫生效：第二次 start 被拒（退出码 4），没有产生重复"
elif [ "$RC" = "0" ] && [ "$N_AFTER" = "2" ]; then
    echo "  ❌ 守卫未生效：仍然叠出了第二个。把下面这段贴回来："
    echo "     -- ppid 取值 --"
    for m in $(masters); do echo "     master=$m"; done
    P1=$(ps -eo pid,args | grep "worker process  ${W}" | grep -v grep | head -1 | awk '{print $1}')
    [ -n "$P1" ] && { echo "     worker=$P1 的 /proc stat:"; cat "/proc/$P1/stat" 2>/dev/null | sed 's/^/       /'; }
    echo "     -- 注册表 --"
    R HGETALL "${RK}heartbeat:inst:${W}" | sed 's/^/       /'
else
    echo "  ⚠️ 结果不在预期内：退出码=${RC} worker数=${N_AFTER}，把上面全部输出贴回来"
fi
