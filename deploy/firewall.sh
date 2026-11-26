#!/bin/bash
#
# market-relay 防火墙 —— 按 deploy/ports.env 写 /etc/iptables/rules.v4 并应用。新机器、同机部署都跑这一条
#
# 身份  root（--dry-run 不需要）
# 依赖  deploy/ports.env · iptables-apply（iptables-persistent 包）
# 产物  /etc/iptables/rules.v4 · 改动前的备份 /etc/iptables/rules.v4.bak.<时间>
#
# 用法:
#   sudo bash deploy/firewall.sh              # 写入并应用
#   bash deploy/firewall.sh --dry-run         # 只打印与当前 rules.v4 的差异，不写文件、不应用
#
# 改了 ports.env 的端口重跑即可。查看本项目的规则：grep market_relay_fw /etc/iptables/rules.v4
#
# 自动判断 rules.v4 的状态
#   没有文件，或文件里一条规则都没有（新机器）  先写基础规则，再加本项目规则
#   已经有规则（同机，或本机之前跑过）          基础规则不动，只把带 FW_TAG 的本项目规则整组替换
#
# 两类规则
#   基础规则  默认拒绝入站、lo、已建立连接、拒绝 3306、22、SSH_PORT、HTTP_PORT、HTTPS_PORT、ping。
#             只在新建时写一次，不带标记：同机时归先装的项目管，这里永远不碰（误删 SSH 会把自己关在外面）。
#             所以新机器以后改 SSH_PORT / HTTP_PORT / HTTPS_PORT，要手动改 rules.v4
#   本项目    IP_SITE_PORTS、SSL_PORT_2 / SSL_PORT_3、DISPATCH_SPOT_PORT / DISPATCH_CONTRACT_PORT，带标记 FW_TAG。
#             每次整组删掉再写，端口改了旧端口的规则也会一起清掉
# 不放行 Workerman Gateway 那批端口（22365 / 21258 / 22960+）：market-relay 不启动 Gateway
#
# 退出码  0 = 成功；1 = 失败（原因见输出；应用失败时已用备份还原）

set -uo pipefail

# 与 deploy/*.sh 一致写死部署路径：不随脚本位置或执行目录变化
PROJECT_DIR="/home/deploy/market-relay"
PORTS_ENV="$PROJECT_DIR/deploy/ports.env"
RULES_FILE="/etc/iptables/rules.v4"
# 本项目规则的注释标记。go/install.sh 的 Go 旁路规则用 market_relay_go_dispatch，
# 两个名字互不包含，各自按标记删除时不会误删对方
FW_TAG="market_relay_fw"

fail() { echo "[FAIL] $*" >&2; exit 1; }
ok()   { echo "[OK] $*"; }
info() { echo "[Info] $*"; }

# 读入 ports.env 并逐个校验：端口是空串时拼出来的规则 iptables-apply 才报错，那时已经改了文件
load_ports() {
    local k v
    [ -f "$PORTS_ENV" ] || fail "找不到 $PORTS_ENV"
    # shellcheck source=/dev/null
    source "$PORTS_ENV"
    for k in SSH_PORT HTTP_PORT HTTPS_PORT SSL_PORT_2 SSL_PORT_3 DISPATCH_SPOT_PORT DISPATCH_CONTRACT_PORT; do
        v="${!k:-}"
        if ! [[ "$v" =~ ^[0-9]+$ ]] || [ "$v" -lt 1 ] || [ "$v" -gt 65535 ]; then
            fail "$PORTS_ENV 的 $k 缺失或不是合法端口（当前值：${v:-空}）"
        fi
    done
    [[ "${IP_SITE_PORTS:-}" =~ ^[0-9]+(,[0-9]+)*$ ]] \
        || fail "$PORTS_ENV 的 IP_SITE_PORTS 不是逗号分隔的端口列表（当前值：${IP_SITE_PORTS:-空}）"
}

# 本项目规则（带标记），每行一条
project_rules() {
    local tag="-m comment --comment ${FW_TAG}"
    echo "-A INPUT -p tcp -m multiport --dports ${IP_SITE_PORTS} ${tag} -j ACCEPT"
    echo "-A INPUT -p tcp -m multiport --dports ${SSL_PORT_2},${SSL_PORT_3} ${tag} -j ACCEPT"
    echo "-A INPUT -p tcp -m multiport --dports ${DISPATCH_SPOT_PORT},${DISPATCH_CONTRACT_PORT} ${tag} -j ACCEPT"
}

# 生成新的 rules.v4 写到 $1
build_rules() {
    local out="$1"
    if [ ! -f "$RULES_FILE" ] || ! grep -q '^-A ' "$RULES_FILE"; then
        # 新机器：iptables-persistent 装好时可能留一个没有规则的空壳文件，同样按新建处理
        info "$RULES_FILE 不存在或没有规则：写入基础规则 + 本项目规则"
        cat > "$out" <<EOF
*filter
:INPUT DROP
:FORWARD ACCEPT [0:0]
:OUTPUT ACCEPT [0:0]
-A INPUT -i lo -j ACCEPT
-A INPUT -m state --state RELATED,ESTABLISHED -j ACCEPT
-A INPUT -p tcp -m tcp --dport 3306 -j DROP
-A INPUT -p tcp -m tcp --dport 22 -j ACCEPT
-A INPUT -p tcp -m tcp --dport ${SSH_PORT} -j ACCEPT
-A INPUT -p tcp -m tcp --dport ${HTTP_PORT} -j ACCEPT
-A INPUT -p tcp -m tcp --dport ${HTTPS_PORT} -j ACCEPT
$(project_rules)
-A INPUT -p icmp -m icmp --icmp-type 8 -j ACCEPT
COMMIT
EOF
        return 0
    fi

    info "$RULES_FILE 已有规则：基础规则不动，只替换本项目规则"
    grep -qx '\*filter' "$RULES_FILE" || fail "$RULES_FILE 里没有 *filter 段"
    # 删掉的有两种：带 FW_TAG 的整组；以及改造前手册追加的三条不带标记的规则（按当前端口值精确匹配），
    # 不删的话会和新规则重复，而且以后改端口时它们不会被清掉
    R="$(project_rules)" TAG="--comment ${FW_TAG}" \
    OLD1="-A INPUT -p tcp -m multiport --dports ${DISPATCH_SPOT_PORT},${DISPATCH_CONTRACT_PORT} -j ACCEPT" \
    OLD2="-A INPUT -p tcp -m multiport --dports ${IP_SITE_PORTS} -j ACCEPT" \
    OLD3="-A INPUT -p tcp -m multiport --dports ${SSL_PORT_2},${SSL_PORT_3} -j ACCEPT" \
    awk '
        BEGIN { r = ENVIRON["R"]; tag = ENVIRON["TAG"] }
        # 标记后面必须是空格或行尾，免得前缀相同的其他标记被误删
        { i = index($0, tag); c = substr($0, i + length(tag), 1) }
        i && (c == " " || c == "") { next }
        $0 == ENVIRON["OLD1"] || $0 == ENVIRON["OLD2"] || $0 == ENVIRON["OLD3"] { next }
        $0 == "*filter" { in_filter = 1 }
        # 只插在 *filter 段的 COMMIT 前：rules.v4 若还有 *nat 等段，它们的 COMMIT 不能插
        in_filter && $0 == "COMMIT" { print r; in_filter = 0 }
        { print }
    ' "$RULES_FILE" > "$out" || fail "生成规则失败"
}

# 把 $1 写进 rules.v4 并应用；应用失败用备份还原并重新应用，不留半截规则
apply_rules() {
    local tmp="$1" backup=""
    command -v iptables-apply >/dev/null 2>&1 || fail "找不到 iptables-apply（apt-get install iptables-persistent）"
    mkdir -p "$(dirname "$RULES_FILE")" || fail "无法创建 $(dirname "$RULES_FILE")"
    if [ -f "$RULES_FILE" ]; then
        backup="${RULES_FILE}.bak.$(date +%Y%m%d%H%M%S)"
        cp "$RULES_FILE" "$backup" || fail "无法备份 $RULES_FILE"
        info "已备份 $backup"
    fi
    cat "$tmp" > "$RULES_FILE" || fail "无法写入 $RULES_FILE"
    if echo y | iptables-apply "$RULES_FILE"; then
        ok "iptables 规则已应用"
        return 0
    fi
    if [ -n "$backup" ]; then
        cat "$backup" > "$RULES_FILE"
        echo y | iptables-apply "$RULES_FILE" >/dev/null 2>&1
        fail "iptables-apply 失败，已用 $backup 还原"
    fi
    fail "iptables-apply 失败（原先没有 $RULES_FILE，无备份可还原）"
}

dry=0
case "${1:-}" in
    "")        ;;
    --dry-run) dry=1 ;;
    *)         echo "用法: sudo bash deploy/firewall.sh [--dry-run]" >&2; exit 1 ;;
esac

load_ports
[ "$dry" = 1 ] || [ "$(id -u)" -eq 0 ] || fail "写防火墙需要 root：sudo bash $PROJECT_DIR/deploy/firewall.sh"
tmp="$(mktemp)" || fail "mktemp 失败"
trap 'rm -f "$tmp"' EXIT
build_rules "$tmp"

if [ "$dry" = 1 ]; then
    if [ -f "$RULES_FILE" ]; then
        info "dry-run：$RULES_FILE 将要发生的变化（- 删除 / + 新增），没有写入"
        diff -u "$RULES_FILE" "$tmp" && info "没有变化"
    else
        info "dry-run：$RULES_FILE 不存在，将写入以下内容，没有写入"
        cat "$tmp"
    fi
    exit 0
fi

apply_rules "$tmp"
grep -n "$FW_TAG" "$RULES_FILE"
