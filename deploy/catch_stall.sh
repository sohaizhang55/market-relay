#!/usr/bin/env bash
# =============================================================================
#  抓 MySQL 写入停顿的现行（只读，跑 3 分钟）
#      bash catch_stall.sh 2>&1 | tee /tmp/stall.txt
#
#  已知：K 线落库与 db_flush（不同的表）会在同一个 ~25 秒窗口里一起崩，每几分钟一次。
#  两者现在同在 MarketDbFlusher 一个事件循环里，一起停本身已证明不了是机器级停顿，
#  要靠下面 InnoDB 计数与块设备两列来分辨。
#  MySQL 配置已排除（buffer_pool 1152M / io_capacity 10000 / flush_neighbors 0）。
#
#  本脚本每 5 秒采一次，把三路视角摆在同一行上：
#    · InnoDB 自己的等待计数（增量）—— 刷写跟不上 / redo buffer 太小
#    · 块设备的写延迟与队列深度   —— 盘本身掉速
#    · 同期 MarketDbFlusher 的 K 线落库耗时 —— 用来确认这一秒确实在停顿
#  三者对齐才分得清「谁在等谁」。
# =============================================================================
set -uo pipefail

D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while [ "$D" != "/" ] && [ ! -f "$D/root/think" ]; do D="$(dirname "$D")"; done
[ -f "$D/root/think" ] || { echo "[Error] 找不到 root/think"; exit 1; }
cd "$D/root" || exit 1
LOG="$D/root/runtime/log/$(date +%Y%m%d)_market_db_flush_stat.log"

# MySQL 凭据只从 root/.env 取，密码不进命令行
MYCNF=$(mktemp); chmod 600 "$MYCNF"; trap 'rm -f "$MYCNF"' EXIT
env_get(){ grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | tr -d ' "'; }
{ echo '[client]'
  for kv in "host DB_MYSQL_HOST" "port DB_MYSQL_PORT" "user DB_MYSQL_USERNAME" "password DB_MYSQL_PASSWORD"; do
      set -- $kv; v=$(env_get "$2"); [ -n "$v" ] && printf '%s=%s\n' "$1" "$v"
  done; } > "$MYCNF"
Q(){ mysql --defaults-extra-file="$MYCNF" -N -B -e "$1" 2>/dev/null; }

command -v mysql   >/dev/null || { echo "[Error] 没有 mysql 客户端"; exit 1; }
command -v iostat  >/dev/null || echo "[warn] 没有 iostat，磁盘那几列会是空的（apt install sysstat）"

# 采一次 InnoDB 的等待计数。
#
# ⚠️ 必须**按名字**取，不能按位置。SHOW GLOBAL STATUS 是按变量名**字母序**返回的，
# 跟 IN(...) 里写的顺序无关。上一版按位置读，于是 Innodb_data_fsyncs 被贴上了
# 「logw+」的标签、Innodb_log_waits 根本没显示出来 —— 差一点据此报出
# 「redo log buffer 太小」这个完全错误的方向。
# 一个会给自己贴错标签的观测，比没有观测更糟。
snap(){
    Q "SHOW GLOBAL STATUS WHERE Variable_name IN
       ('Innodb_buffer_pool_wait_free','Innodb_log_waits','Innodb_data_fsyncs')" \
    | awk '{v[$1]=$2} END{printf "%d %d %d",
             v["Innodb_buffer_pool_wait_free"], v["Innodb_log_waits"], v["Innodb_data_fsyncs"]}'
}

echo "开始采样，每 5 秒一次，共 3 分钟。停顿是每几分钟一次、约 25 秒，耐心等。"
echo
printf '%-9s | %8s %6s %6s | %8s %7s %6s | %s\n' \
       时刻 'poolwait+' 'logw+' 'fsync+' 'w_await' 'aqu-sz' '%util' 'K 线落库 avg ms'
printf '%.0s─' {1..104}; echo

read -r P0 L0 F0 <<<"$(snap)"
for i in $(seq 1 36); do
    sleep 5
    read -r P1 L1 F1 <<<"$(snap)"

    # 磁盘：取写延迟最高的那个块设备（三盘镜像，看最慢的那块）
    if command -v iostat >/dev/null; then
        # ⚠️ iostat 的**第一份报告是自开机以来的累计均值**，必须丢掉，只取第二份。
        # 上一版没跳，于是几乎每一行都在重复打同一个开机均值（28.9/14.6/56），
        # 真实的瞬时尖峰反而被埋掉了
        IO=$(iostat -x 1 2 | awk '
                /Device/ {rpt++; next}
                rpt>=2 && /^(sd|nvme|dm-)/ {print $12, $22, $23}
             ' | sort -rn | head -1)
        WA=$(echo "$IO" | awk '{printf "%.1f", $1}'); AQ=$(echo "$IO" | awk '{printf "%.1f", $2}')
        UT=$(echo "$IO" | awk '{printf "%.0f", $3}')
    else WA='-'; AQ='-'; UT='-'; fi

    # 先取最后一条 stat 再抽：本窗没落 K 线时那一行没有 ms(avg=)，直接全文 grep 会拿到更早的旧值
    KF=$(grep 'dirty(' "$LOG" 2>/dev/null | tail -1 | grep -oE 'kline\([^)]*ms\(avg=[0-9.]+' | grep -oE '[0-9.]+$')
    printf '%-9s | %8s %6s %6s | %8s %7s %6s | %s\n' \
        "$(date +%H:%M:%S)" "$((P1-P0))" "$((L1-L0))" "$((F1-F0))" "$WA" "$AQ" "$UT" "${KF:--}"
    P0=$P1; L0=$L1; F0=$F1
done

echo
echo "判读："
echo "  poolwait+ 持续 >0        → InnoDB 刷写跟不上，缓冲池没有空闲页（调 io_capacity_max / 增大 pool）"
echo "  logw+     持续 >0        → redo log buffer 太小（innodb_log_buffer_size）"
echo "  三个计数都是 0，只有 w_await/aqu-sz 跳 → 盘本身掉速（消费级 SSD 的 SLC 缓存被写满是典型）"
echo "  K 线落库 avg 跳高的那几行，对照左边哪一列同时跳，就是它"
