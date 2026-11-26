#!/bin/bash
#
# Go 分发机测试服试跑 —— 影子模式与 PHP 分发并行：只读影子脏集合、只监听旁路端口，不动现有订阅方
#
# 前提  项目已在服务器上，Linux 版二进制 go/market_dispatch_go 已上传（编译见 go/dispatch_commands.txt 第六节）
# 身份  用 deploy 运行，不要用 root：日志、pid 文件与采集进程都归 deploy
# 依赖  root/.env 的 CACHE_REDIS_* · CACHE_PREFIX · MARKET_DISPATCH_*_PORT · deploy/restart_market.sh
# 产物  go/.env · go/logs/dispatch_{1,2}.log · go/logs/dispatch_{1,2}.pid
#
# 用法:
#   bash go/install.sh setup        # 赋执行权限、自检、生成 go/.env 并从 root/.env 同步 Redis 连接
#   bash go/install.sh start        # 打开影子脏集合（首次会重启全部 PHP 行情进程），启动现货 + 合约 Go 分发
#   bash go/install.sh status       # 进程、端口、日志尾
#   bash go/install.sh compare 2    # Go 与 PHP 同时 watch 20 秒并排对比；1 现货 / 2 合约，默认 2
#   bash go/install.sh stop         # 只停 Go 分发，影子脏集合保持打开
#   bash go/install.sh cleanup      # 停 Go 分发、关影子脏集合、重启 PHP 行情进程，回到测试前状态
#   sudo bash go/install.sh firewall-open    # 开放外部访问：监听 0.0.0.0，按 DISPATCH_ALLOW_IPS 放行 iptables（root）
#   sudo bash go/install.sh firewall-close   # 收回：删除本脚本加的 iptables 规则，监听改回 127.0.0.1（root）
#
# 防火墙  端口 18594 / 18595 只在试跑期间按需开放，刻意不写进 deploy/install.sh 第 6 段：
#         正式切换后 Go 改用 PHP 原端口 8594 / 8595（已放行），旁路端口应关闭
#
# 退出码  0 = 成功；1 = 失败（原因见输出）

set -uo pipefail

# 与 deploy/*.sh 一致写死部署路径：不随脚本位置或执行目录变化
PROJECT_DIR="/home/deploy/market-relay"
GO_DIR="$PROJECT_DIR/go"
ROOT_ENV="$PROJECT_DIR/root/.env"
GO_ENV="$GO_DIR/.env"
BIN="$GO_DIR/market_dispatch_go"
LOG_DIR="$GO_DIR/logs"
RESTART_MARKET="$PROJECT_DIR/deploy/restart_market.sh"
SHADOW_KEY="MARKET_DISPATCH_SHADOW_DIRTY"
RULES_FILE="/etc/iptables/rules.v4"
# 本脚本加的 iptables 规则都带这个注释，firewall-close 按它精确删除，不碰其他项目的规则
FW_TAG="market_relay_go_dispatch"

fail() { echo "[FAIL] $*" >&2; exit 1; }
ok()   { echo "[OK] $*"; }
info() { echo "[Info] $*"; }

# 读 KEY=VALUE 文件里某个键的值，去掉两边引号；没有这一行时输出默认值
env_get() {
    local file="$1" key="$2" def="${3:-}" line
    line="$(grep -E "^${key}=" "$file" 2>/dev/null | tail -n 1)"
    if [ -z "$line" ]; then
        echo "$def"
        return
    fi
    line="${line#*=}"
    line="${line%\"}"; line="${line#\"}"
    line="${line%\'}"; line="${line#\'}"
    echo "$line"
}

# 设置 KEY=VALUE：有这一行就替换，没有就追加。用 awk + ENVIRON 传值，密码里的 / & 等字符不会被当成 sed 语法
env_set() {
    local file="$1" key="$2" val="$3" tmp
    tmp="$(mktemp)" || fail "mktemp 失败"
    K="$key" V="$val" awk '
        BEGIN { k = ENVIRON["K"]; v = ENVIRON["V"] }
        index($0, k "=") == 1 { if (!done) print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }
    ' "$file" > "$tmp" && cat "$tmp" > "$file"
    rm -f "$tmp"
}

pid_file() { echo "$LOG_DIR/dispatch_$1.pid"; }
log_file() { echo "$LOG_DIR/dispatch_$1.log"; }
type_name() { [ "$1" = 1 ] && echo "现货" || echo "合约"; }

# 某类 Go 分发的 pid，进程不在时输出空
running_pid() {
    local pf pid
    pf="$(pid_file "$1")"
    [ -f "$pf" ] || return 0
    pid="$(cat "$pf")"
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        echo "$pid"
    fi
}

go_port() {
    if [ "$1" = 1 ]; then env_get "$GO_ENV" DISPATCH_SPOT_PORT 18594; else env_get "$GO_ENV" DISPATCH_SWAP_PORT 18595; fi
}

php_port() {
    if [ "$1" = 1 ]; then env_get "$ROOT_ENV" MARKET_DISPATCH_SPOT_PORT 8594; else env_get "$ROOT_ENV" MARKET_DISPATCH_CONTRACT_PORT 8595; fi
}

restart_php_market() {
    info "重启 PHP 行情进程，行情会中断几秒：bash deploy/restart_market.sh"
    bash "$RESTART_MARKET" || fail "restart_market.sh 执行失败，见上方输出"
}

check_common() {
    # root 跑会让 go/.env、go/logs/ 归 root，之后 deploy 写不了；误用 root 时什么都不改直接退出
    [ "$(id -u)" -ne 0 ] || fail "不要用 root 运行 $1，请改用：sudo -u deploy bash $GO_DIR/install.sh $1"
    [ -f "$ROOT_ENV" ] || fail "找不到 $ROOT_ENV，项目还没装好？"
    mkdir -p "$LOG_DIR" || fail "无法创建 $LOG_DIR"
}

check_ready() {
    [ -x "$BIN" ] || fail "$BIN 不存在或没有执行权限，先跑：bash go/install.sh setup"
    [ -f "$GO_ENV" ] || fail "找不到 $GO_ENV，先跑：bash go/install.sh setup"
}

do_setup() {
    [ -f "$BIN" ] || fail "找不到 $BIN，先在本机编译 Linux 版并上传（go/dispatch_commands.txt 第六节）"
    chmod +x "$BIN" || fail "chmod 失败"

    # 不带参数运行会打印用法并以 2 退出；能打印出「用法」说明二进制能在本机执行。
    # 先存进变量再判断：直接接管道的话，pipefail 会把正常的退出码 2 当成失败
    local usage
    usage="$("$BIN" 2>&1)"
    case "$usage" in
        *用法*) ;;
        *) fail "$BIN 无法运行（输出：${usage:-无}）。确认编译时设了 GOOS=linux GOARCH=amd64，且上传后文件完整" ;;
    esac
    ok "二进制可执行"

    if [ ! -f "$GO_ENV" ]; then
        cp "$GO_DIR/.env.example" "$GO_ENV" || fail "无法从 .env.example 生成 .env"
        ok "已从 .env.example 生成 go/.env"
    fi

    # Redis 连接每次都从 root/.env 重新同步：两边读写的必须是同一个库、同一个前缀，写错不报错只会读到别的键
    env_set "$GO_ENV" REDIS_HOST     "$(env_get "$ROOT_ENV" CACHE_REDIS_HOST 127.0.0.1)"
    env_set "$GO_ENV" REDIS_PORT     "$(env_get "$ROOT_ENV" CACHE_REDIS_PORT 6379)"
    env_set "$GO_ENV" REDIS_PASSWORD "$(env_get "$ROOT_ENV" CACHE_REDIS_PASSWORD "")"
    env_set "$GO_ENV" REDIS_DB       "$(env_get "$ROOT_ENV" CACHE_REDIS_SELECT 0)"
    env_set "$GO_ENV" REDIS_PREFIX   "$(env_get "$ROOT_ENV" CACHE_PREFIX market_relay_)"
    ok "已从 root/.env 同步 Redis 连接：$(env_get "$GO_ENV" REDIS_HOST):$(env_get "$GO_ENV" REDIS_PORT)" \
       "库 $(env_get "$GO_ENV" REDIS_DB) 前缀 $(env_get "$GO_ENV" REDIS_PREFIX)" \
       "密码 $([ -n "$(env_get "$GO_ENV" REDIS_PASSWORD)" ] && echo 已设置 || echo 无)"
    echo "下一步：bash go/install.sh start"
}

do_start() {
    check_ready

    if [ "$(env_get "$ROOT_ENV" "$SHADOW_KEY")" = "1" ]; then
        ok "影子脏集合已打开（root/.env $SHADOW_KEY=1），不重启 PHP 行情进程"
    else
        env_set "$ROOT_ENV" "$SHADOW_KEY" 1
        ok "已在 root/.env 打开 $SHADOW_KEY=1"
        restart_php_market
    fi

    local t pid log
    for t in 1 2; do
        if [ -n "$(running_pid "$t")" ]; then
            info "$(type_name "$t") Go 分发已在运行，先停掉再启动"
            stop_one "$t"
        fi
        log="$(log_file "$t")"
        # -dirty shadow 写死：本脚本只做并行试跑，不管 go/.env 的 DISPATCH_DIRTY 写了什么，都不能读正式脏集合跟 PHP 抢数据
        nohup "$BIN" dispatch -t "$t" -dirty shadow -config "$GO_ENV" </dev/null >>"$log" 2>&1 &
        echo $! > "$(pid_file "$t")"
    done

    sleep 2
    local failed=0
    for t in 1 2; do
        pid="$(running_pid "$t")"
        if [ -n "$pid" ]; then
            ok "$(type_name "$t") Go 分发已启动 pid=$pid 端口=$(go_port "$t") 日志=go/logs/dispatch_$t.log"
        else
            echo "[FAIL] $(type_name "$t") Go 分发没起来，日志末尾：" >&2
            tail -n 10 "$(log_file "$t")" >&2
            failed=1
        fi
    done
    [ "$failed" = 0 ] || exit 1
    echo "下一步：bash go/install.sh compare 2"
}

# 先 TERM 让进程写停机标记、正常退出（心跳检查不会误报），10 秒还不退再 KILL
stop_one() {
    local t="$1" pid i
    pid="$(running_pid "$t")"
    if [ -z "$pid" ]; then
        rm -f "$(pid_file "$t")"
        return
    fi
    kill -TERM "$pid" 2>/dev/null
    for i in $(seq 1 10); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 1
    done
    if kill -0 "$pid" 2>/dev/null; then
        kill -KILL "$pid" 2>/dev/null
        info "$(type_name "$t") Go 分发 10 秒内没退出，已强制结束 pid=$pid"
    else
        ok "$(type_name "$t") Go 分发已停止 pid=$pid"
    fi
    rm -f "$(pid_file "$t")"
}

do_stop() {
    stop_one 1
    stop_one 2
    # 兜底：pid 文件丢了但进程还在（例如手动用 nohup 起的）
    if pgrep -f "$BIN dispatch" >/dev/null 2>&1; then
        pkill -TERM -f "$BIN dispatch"
        info "已停止不在 pid 文件里的 Go 分发进程"
    fi
}

do_status() {
    local t pid port
    for t in 1 2; do
        pid="$(running_pid "$t")"
        port="$(go_port "$t")"
        echo "── $(type_name "$t")（-t $t）"
        if [ -n "$pid" ]; then
            echo "   进程  运行中 pid=$pid"
        else
            echo "   进程  未运行"
        fi
        # 不接 grep -q 管道：grep 提前退出会让 ss 收到 SIGPIPE，pipefail 下判成没监听
        if [[ "$(ss -lnt 2>/dev/null)" =~ :${port}[[:space:]] ]]; then
            echo "   端口  $port 在监听"
        else
            echo "   端口  $port 没在监听"
        fi
        if [ -f "$(log_file "$t")" ]; then
            echo "   日志末尾："
            tail -n 3 "$(log_file "$t")" | sed 's/^/     /'
        fi
    done
    echo "── 影子脏集合  root/.env $SHADOW_KEY=$(env_get "$ROOT_ENV" "$SHADOW_KEY" 未设置)"
    echo "── 监听地址    go/.env DISPATCH_HOST=$(env_get "$GO_ENV" DISPATCH_HOST 127.0.0.1)（改完要 start 重启才生效）"
    if [ -r "$RULES_FILE" ]; then
        echo "── 防火墙      $RULES_FILE 里本脚本的规则 $(grep -c "$FW_TAG" "$RULES_FILE") 条（0 = 外部连不进来）"
    fi
}

do_compare() {
    local t="${1:-2}" go_out php_out
    [ "$t" = 1 ] || [ "$t" = 2 ] || fail "compare 的参数只能是 1（现货）或 2（合约）"
    check_ready
    [ -n "$(running_pid "$t")" ] || fail "$(type_name "$t") Go 分发没在运行，先跑：bash go/install.sh start"

    go_out="$(mktemp)"; php_out="$(mktemp)"
    info "同时 watch 20 秒：Go 端口 $(go_port "$t") / PHP 端口 $(php_port "$t")"
    "$BIN" watch -t "$t" -config "$GO_ENV" -dur 20s -show 0 >"$go_out" 2>&1 &
    "$BIN" watch -t "$t" -config "$GO_ENV" -port "$(php_port "$t")" -dur 20s -show 0 >"$php_out" 2>&1 &
    wait

    echo "════ Go（$(go_port "$t")）"
    cat "$go_out"
    echo "════ PHP（$(php_port "$t")）"
    cat "$php_out"
    rm -f "$go_out" "$php_out"
    echo "两边每秒帧数、各类 stream 数量大致接近即正常；Go 一直 0 帧，多半是影子脏集合没打开或 REDIS_PREFIX 不对"
}

do_cleanup() {
    do_stop
    if [ -n "$(env_get "$ROOT_ENV" "$SHADOW_KEY")" ]; then
        sed -i "/^${SHADOW_KEY}=/d" "$ROOT_ENV" || fail "无法修改 $ROOT_ENV"
        ok "已从 root/.env 删除 $SHADOW_KEY"
        restart_php_market
    else
        ok "root/.env 没有 $SHADOW_KEY，不需要重启 PHP 行情进程"
    fi
    echo "已恢复到测试前状态（go/.env 与 go/logs/ 保留，可直接删除）"
}

check_fw() {
    [ "$(id -u)" -eq 0 ] || fail "防火墙操作需要 root：sudo bash $GO_DIR/install.sh $1"
    [ -f "$GO_ENV" ] || fail "找不到 $GO_ENV，先用 deploy 跑：bash $GO_DIR/install.sh setup"
    [ -f "$RULES_FILE" ] || fail "找不到 $RULES_FILE（防火墙按 deploy/install.sh 第 6 段配置）"
    grep -qx '\*filter' "$RULES_FILE" || fail "$RULES_FILE 里没有 *filter 段"
    command -v iptables-apply >/dev/null 2>&1 || fail "找不到 iptables-apply"
}

# 应用 rules.v4；失败就用备份还原并重新应用，不留半截规则
fw_apply() {
    local backup="$1"
    if echo y | iptables-apply "$RULES_FILE"; then
        ok "iptables 规则已应用（备份 $backup）"
    else
        cat "$backup" > "$RULES_FILE"
        echo y | iptables-apply "$RULES_FILE" >/dev/null 2>&1
        fail "iptables-apply 失败，已用 $backup 还原"
    fi
}

do_firewall_open() {
    check_fw firewall-open
    local spot swap ips ip rules="" backup tmp
    spot="$(go_port 1)"; swap="$(go_port 2)"
    ips="$(env_get "$GO_ENV" DISPATCH_ALLOW_IPS "")"

    if [ -z "$ips" ]; then
        rules="-A INPUT -p tcp -m multiport --dports ${spot},${swap} -m comment --comment ${FW_TAG} -j ACCEPT"
        info "go/.env DISPATCH_ALLOW_IPS 为空：$spot / $swap 对所有来源开放"
    else
        for ip in ${ips//,/ }; do
            # 本机走 lo 网卡，deploy/install.sh 第 6 段已整体放行
            [ "$ip" = "127.0.0.1" ] && continue
            rules+="-A INPUT -p tcp -s ${ip} -m multiport --dports ${spot},${swap} -m comment --comment ${FW_TAG} -j ACCEPT"$'\n'
        done
        rules="${rules%$'\n'}"
        [ -n "$rules" ] || fail "DISPATCH_ALLOW_IPS 只有 127.0.0.1，不需要开放外部访问"
        info "只对 DISPATCH_ALLOW_IPS 开放：$ips"
        # Go 白名单精确匹配来源 IP（dispatch/server.go serveWS），不含 127.0.0.1 时本机 compare 会被拒
        case ",$ips," in
            *,127.0.0.1,*) ;;
            *)
                env_set "$GO_ENV" DISPATCH_ALLOW_IPS "${ips},127.0.0.1"
                info "已把 127.0.0.1 补进 go/.env DISPATCH_ALLOW_IPS，本机 compare 才连得上"
                ;;
        esac
    fi

    backup="${RULES_FILE}.bak.$(date +%Y%m%d%H%M%S)"
    cp "$RULES_FILE" "$backup" || fail "无法备份 $RULES_FILE"
    # 先删旧的再加：改了 DISPATCH_ALLOW_IPS 重跑时整组替换，不会叠加
    sed -i "/${FW_TAG}/d" "$RULES_FILE"
    # 只插在 *filter 段的 COMMIT 前；rules.v4 若还有 nat 等段，它们的 COMMIT 不能插
    tmp="$(mktemp)" || fail "mktemp 失败"
    R="$rules" awk '
        BEGIN { r = ENVIRON["R"] }
        $0 == "*filter" { in_filter = 1 }
        in_filter && $0 == "COMMIT" { print r; in_filter = 0 }
        { print }
    ' "$RULES_FILE" > "$tmp" && cat "$tmp" > "$RULES_FILE"
    rm -f "$tmp"
    fw_apply "$backup"

    env_set "$GO_ENV" DISPATCH_HOST 0.0.0.0
    ok "go/.env DISPATCH_HOST 已改为 0.0.0.0"
    grep -n "$FW_TAG" "$RULES_FILE"
    echo "下一步：切回 deploy 重启 Go 分发才会监听外部网卡：bash $GO_DIR/install.sh start"
    echo "测完收回：sudo bash $GO_DIR/install.sh firewall-close"
}

do_firewall_close() {
    check_fw firewall-close
    local backup
    if grep -q "$FW_TAG" "$RULES_FILE"; then
        backup="${RULES_FILE}.bak.$(date +%Y%m%d%H%M%S)"
        cp "$RULES_FILE" "$backup" || fail "无法备份 $RULES_FILE"
        sed -i "/${FW_TAG}/d" "$RULES_FILE"
        fw_apply "$backup"
        ok "已删除本脚本加的 iptables 规则"
    else
        ok "$RULES_FILE 里没有本脚本加的规则"
    fi
    env_set "$GO_ENV" DISPATCH_HOST 127.0.0.1
    ok "go/.env DISPATCH_HOST 已改回 127.0.0.1"
    echo "下一步：切回 deploy 重启 Go 分发：bash $GO_DIR/install.sh start（不再跑 Go 就 stop）"
}

# 防火墙操作必须 root，其余必须 deploy
case "${1:-}" in
    firewall-open|firewall-close) ;;
    *) check_common "${1:-}" ;;
esac
case "${1:-}" in
    setup)          do_setup ;;
    start)          do_start ;;
    status)         do_status ;;
    compare)        do_compare "${2:-2}" ;;
    stop)           do_stop ;;
    cleanup)        do_cleanup ;;
    firewall-open)  do_firewall_open ;;
    firewall-close) do_firewall_close ;;
    *)
        echo "用法: bash go/install.sh setup|start|status|compare [1|2]|stop|cleanup|firewall-open|firewall-close" >&2
        exit 1
        ;;
esac
