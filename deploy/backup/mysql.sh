#!/bin/bash
#
# bash /home/deploy/market-relay/deploy/backup/mysql.sh
#
# 每一步都判退出码，失败汇总后以非 0 退出，并尽最大努力发一条告警。
# **失败时不覆盖上一份好备份** —— 那是最后一道防线。
#
# 数据库名与账号取自 root/.env 的 DB_MYSQL_*（不在脚本里写死账号密码）。
# 异地副本走 rsync daemon，密码文件 deploy/backup/backup.pas 需在服务器上手工放置（已 gitignore）。
# 目标由环境变量 RSYNC_DEST（如 user@host::module）与 RSYNC_PORT（默认 873）指定。

set -o pipefail

PROJECT=/home/deploy/market-relay
ENV_FILE=${PROJECT}/root/.env

PROBLEMS=()
fail() { PROBLEMS+=("$1"); echo "[FAIL] $1" >&2; }

# 读 root/.env 的 KEY=VALUE（取最后一次赋值，去掉两端引号）
env_get() {
    grep -E "^[[:space:]]*$1[[:space:]]*=" "${ENV_FILE}" 2>/dev/null | tail -1 \
        | sed -E "s/^[^=]*=[[:space:]]*//; s/[[:space:]]*$//; s/^[\"']//; s/[\"']$//"
}

DB_NAME=$(env_get DB_MYSQL_DATABASE)
DB_USER=$(env_get DB_MYSQL_USERNAME)
DB_HOST=$(env_get DB_MYSQL_HOST)
DB_PORT=$(env_get DB_MYSQL_PORT)
# 走环境变量而不是 -p 参数：命令行里的密码会被同机任何人在 ps 里看到
export MYSQL_PWD=$(env_get DB_MYSQL_PASSWORD)

if [ -z "${DB_NAME}" ] || [ -z "${DB_USER}" ]; then
    MSG="数据库备份失败（$(hostname)）：${ENV_FILE} 里读不到 DB_MYSQL_DATABASE / DB_MYSQL_USERNAME"
    echo "[FAIL] ${MSG}" >&2
    php ${PROJECT}/root/think alert:notify "${MSG}" --level=error >/dev/null 2>&1 || true
    exit 1
fi

#  范围是 [0, 32767]
# man bash， 搜索 RANDOM 可以看到说明 随机延迟 20分钟内
delay=`expr 10000 \* $RANDOM / 32767 \* 1200 / 10000`
echo $delay

sleep $delay

localip=`ip addr|grep -w inet|grep -v 127.0.0.1|head -1|awk '{print $2}'|cut -d '/'  -f 1 | grep -vE '^(127|10|172\.(1[6-9]|2[0-9]|3[0-1])|192\.168)\.'`
if [ -z "$localip" ]; then
    echo "localip 通过 ip addr 获取失败，尝试通过 ifconfig.me 获取"
    localip=$(curl -s --connect-timeout 5 -m 10 ifconfig.me)
fi
if [ -z "$localip" ]; then
    echo "localip 通过 ifconfig.me 获取失败，尝试通过 icanhazip.com 获取"
    localip=$(curl -s --connect-timeout 5 -m 10 icanhazip.com)
fi
if [ -z "$localip" ]; then
    echo "localip 通过 icanhazip.com 获取失败，尝试通过 ipinfo.io 获取"
    localip=$(curl -s --connect-timeout 5 -m 10 ipinfo.io/ip)
fi
if [ -z "$localip" ]; then
    echo "localip 获取失败，设置默认值 127.0.0.1"
    localip="127.0.0.1"
fi

DATE=`date +%Y%m%d%H%M`
project_old="market-relay.sql"
key_old="key.php"

project_dump="market_relay_${localip}_${DATE}.sql"
key_copy="key_${localip}_${DATE}.php"

cd ${PROJECT}/deploy/backup/ || { echo "[FAIL] 进不去 ${PROJECT}/deploy/backup" >&2; exit 1; }
# 先 dump 到临时名，确认无误再改成正式名 —— 中途失败时不留下一个看起来像成功的文件
dump_db() {
    local db="$1" out="$2"
    shift 2
    mysqldump -h"${DB_HOST:-127.0.0.1}" -P"${DB_PORT:-3306}" -u"${DB_USER}" --single-transaction "$@" "$db" > "${out}.part"
    # 退出码要单独存下来：写在 `if ! cmd; then fail "$?"` 里的话，$? 已经是 if 判断本身的结果了
    local dump_rc=$?
    if [ "$dump_rc" -ne 0 ]; then
        fail "${db} mysqldump 失败（退出码 ${dump_rc}），本次没有产出可用备份"
        rm -f "${out}.part"
    # 空文件与截断文件都要挡：mysqldump 中途断开时前半段是合法 SQL，只是不完整。
    # 完整的 dump 一定以 "Dump completed" 结尾，这是它唯一可靠的完成标记
    elif [ ! -s "${out}.part" ]; then
        fail "${db} mysqldump 产出的是空文件"
        rm -f "${out}.part"
    elif ! tail -c 200 "${out}.part" | grep -q "Dump completed"; then
        fail "${db} dump 文件末尾没有 'Dump completed' —— 是截断的，不能当备份用"
        rm -f "${out}.part"
    else
        mv "${out}.part" "${out}"
    fi
}
# --master-data / --flush-logs 需要 RELOAD、REPLICATION CLIENT 权限（install.sh 会授予）
dump_db "${DB_NAME}" "${project_dump}" --master-data --flush-logs

cp ${PROJECT}/root/config/key.php ${key_copy} || fail "复制 key.php 失败"

# dump 没出来就别往下走了：后面每一步都建立在它存在的前提上
if [ ! -f "${project_dump}" ]; then
    echo "[ABORT] 没有可用的 dump，跳过上传与轮换（**保留上一份好备份**）" >&2
    printf '%s\n' "${PROBLEMS[@]}" >&2
    MSG="数据库备份失败（$(hostname)）：$(printf '%s；' "${PROBLEMS[@]}")"
    php ${PROJECT}/root/think alert:notify "${MSG}" --level=error >/dev/null 2>&1 || true
    exit 1
fi

compare_size() {
    local new="$1" old="$2"
    [ -f "${new}" ] && [ -f "${old}" ] || return 0
    echo "compair dump file size: ${new}"
    local dump_size old_size
    dump_size=$(stat -c %s "${new}" 2>/dev/null || stat -f %z "${new}")
    old_size=$(stat -c %s "${old}"   2>/dev/null || stat -f %z "${old}")
    echo "   本次 ${dump_size} 字节，上次 ${old_size} 字节"
    # 缩水 10% 以上才报：库本来就会因为清理任务而小幅缩小，
    # 阈值太紧会天天误报，而一个天天误报的检查等于没有检查
    if [ "${dump_size}" -lt $(( old_size * 9 / 10 )) ]; then
        fail "${new} 比上一份小了 10% 以上（${old_size} -> ${dump_size} 字节）—— 可能是导出中途出了问题"
    fi
}
compare_size "${project_dump}" "${project_old}"

echo "start rsync..."

if [ -f backup.pas ]; then
    chmod 600 backup.pas
    push() {
        local what="$1"
        rsync -avr --port="${RSYNC_PORT:-873}" --password-file=backup.pas "$what" "${RSYNC_DEST:-backup@backup.example.com::backup}/market-relay/${HOSTNAME}/" \
            || fail "rsync 上传失败：${what}（异地副本没有更新）"
    }
    push "${project_dump}"
    [ -d "${PROJECT}/root/public/upload" ] && push "${PROJECT}/root/public/upload"
    push "${key_copy}"
else
    fail "缺少 deploy/backup/backup.pas，异地副本没有上传（本地备份已生成）"
fi

# ⚠️ 轮换只在**这一份确实可用**时才做。
# 一份坏 dump 不能把上一份好的覆盖掉 —— 连着失败两次，本地就一份能用的都没有了。
if [ ${#PROBLEMS[@]} -eq 0 ]; then
    mv "${project_dump}" "${project_old}"
    mv "${key_copy}"     "${key_old}"
else
    echo "[KEEP] 本次有失败，保留上一份 ${project_old} 不覆盖" >&2
fi

# ── 结果 ────────────────────────────────────────────────────────────────────
# 失败必须自己想办法说出去：一条告警。发不出去也不能让脚本本身报错。
if [ ${#PROBLEMS[@]} -gt 0 ]; then
    MSG="数据库备份失败（$(hostname)）：$(printf '%s；' "${PROBLEMS[@]}")"
    echo "[FAIL] ${MSG}" >&2
    php ${PROJECT}/root/think alert:notify "${MSG}" --level=error >/dev/null 2>&1 || true
    exit 1
fi

echo "[OK] 备份完成并已上传"
exit 0
