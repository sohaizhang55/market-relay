#!/bin/bash
# =============================================================================
#  deploy/*.sh 共用：以「不持有终端 fd」的方式下发一条会 daemon 化的 think 指令
#
#  为什么必须包一层，而不能直接写 `$PHP_BIN think xxx start -d`：
#
#  ThinkPHP 的 console 输出驱动在 Output 构造时就执行了 fopen('php://stdout')
#  （think\console\output\driver\Console::__construct），PHP 在 CLI 下用
#  dup(STDOUT_FILENO) 实现 php://stdout —— 这是一份**独立的 fd 副本**。
#  而 Workerman 的 resetStd() 只 fclose 了 STDOUT / STDERR 常量对应的那两个流
#  （且从不关 STDIN），daemonize() 又只做 fork + setsid、完全不碰 fd。
#
#  于是 daemon 化之后的常驻进程会一直攥着终端 / SSH 管道的**写端**不放，
#  shell 和 sshd 等不到 EOF —— 现象就是「脚本明明跑完了、输出也打完了，
#  却回不到提示符，必须 Ctrl+C」。本地交互式 bash 不等 fd 关闭所以看不出来，
#  一走 SSH（尤其 ssh host 'cmd'）或者把输出接进管道就会卡住。
#
#  这里让指令的 stdin 来自 /dev/null、stdout/stderr 落到临时文件（daemon dup 到的
#  是这个文件的 fd，跟终端无关），指令返回后再 cat 回终端：卡住的问题消失，
#  输出一个字不少。代价只是输出从「逐行实时刷」变成「执行完一次性打印」——
#  这些指令 daemon 化后立刻返回，肉眼没有差别。
#
#  用法：
#      source "$(dirname "${BASH_SOURCE[0]}")/lib/run_daemon.sh"
#      run_daemon "火币现货采集器" $PHP_BIN "$THINK_BIN" market:huobi restart -t 1 -d
#      daemon_summary
# =============================================================================

DAEMON_OK_COUNT=0
DAEMON_SKIP_COUNT=0
DAEMON_FAIL_COUNT=0
DAEMON_DUP_COUNT=0

# run_daemon <人类可读的名字> <要执行的命令...>
#
# 按**真实退出码**汇报结果，不要再用「命令后面无条件 echo [OK] xxx 已启动」那种写法：
#   0   = 真的起来了
#   3   = 按配置未启用（如 market.source_select.enable=false，且指令已顺手确保它没在运行）
#         见 app\command\DisabledGuardTrait::EXIT_DISABLED；
#         非 market 指令不会返回这个码，写在这里只是让 helper 通用
#   其它 = 真的失败了
run_daemon() {
    local label="$1"
    shift

    local out
    if ! out="$(mktemp "${TMPDIR:-/tmp}/market-relay_daemon.XXXXXX")"; then
        echo "[FAIL] ${label} 无法创建临时文件（${TMPDIR:-/tmp} 不可写？），已跳过下发"
        DAEMON_FAIL_COUNT=$((DAEMON_FAIL_COUNT + 1))
        return 1
    fi

    "$@" </dev/null >"$out" 2>&1
    local rc=$?

    cat "$out"
    # daemon 若仍持有该 fd，Linux 下 unlink 后待其关闭自动释放，不会残留文件
    rm -f "$out"

    case $rc in
        0)
            echo "[OK]   ${label} 已启动"
            DAEMON_OK_COUNT=$((DAEMON_OK_COUNT + 1))
            ;;
        3)
            echo "[SKIP] ${label} 按配置未启用，已确保其未在运行"
            DAEMON_SKIP_COUNT=$((DAEMON_SKIP_COUNT + 1))
            ;;
        4)
            # 与 3 分开：3 是「按配置本来就不该跑，且已确认没在跑」，可以不管；
            # 4 是「已经有一套在跑，而且脚本管不着它」——服务还活着，但多了一份，
            # 撮合/结算类属正确性问题。必须让它出现在汇总里，不能混进 SKIP
            echo "[DUP]  ${label} 已有实例在运行，本次启动被拒绝（见上方守卫输出）"
            DAEMON_DUP_COUNT=$((DAEMON_DUP_COUNT + 1))
            ;;
        *)
            echo "[FAIL] ${label} 启动失败，退出码 ${rc}"
            DAEMON_FAIL_COUNT=$((DAEMON_FAIL_COUNT + 1))
            ;;
    esac

    return 0
}

# daemon_summary：打印汇总，并以「有无真失败」决定返回码，方便 CI / 上层脚本感知
daemon_summary() {
    echo "=========================================="
    echo "启动 ${DAEMON_OK_COUNT} 个，按模式跳过 ${DAEMON_SKIP_COUNT} 个，重复被拒 ${DAEMON_DUP_COUNT} 个，失败 ${DAEMON_FAIL_COUNT} 个。"
    if [ "$DAEMON_FAIL_COUNT" -gt 0 ]; then
        echo "存在启动失败的进程，请查看上方 [FAIL] 行及 root/runtime/log/ 下对应日志。"
    fi
    if [ "$DAEMON_DUP_COUNT" -gt 0 ]; then
        echo "有 ${DAEMON_DUP_COUNT} 个指令已有实例在跑而被拒绝启动 —— 那些实例不归 pid 文件管，"
        echo "脚本停不掉它们。用 ps -eo pid,lstart,args | grep 'WorkerMan: master process' 找出来手工处理。"
    fi
    echo "=========================================="
    # 重复也算不通过：它和「真失败」一样需要人去处理，混成 0 退出码等于把问题藏起来
    [ "$DAEMON_FAIL_COUNT" -eq 0 ] && [ "$DAEMON_DUP_COUNT" -eq 0 ]
}
