<?php

declare(strict_types=1);

namespace app\lib;

/**
 * 读内核 socket 队列（Send-Q / Recv-Q）。
 *
 * ⚠️ 为什么必须有这个类：Workerman 的 `getSendBufferQueueSize()` 实现就是
 * `strlen($this->sendBuffer)` —— 一个**用户态 PHP 字符串**。只要每次 `fwrite()`
 * 都全量返回，它就恒为 0，**哪怕内核 Send-Q 已经堆满、对端窗口快关死**。
 * 接收方向同理：Workerman 每次可读事件把字节读进自己的 recvBuffer，
 * 应用层看到的永远是"已经读进来的"，看不见还堵在内核里的。
 *
 * 一次推送要穿过四层缓冲：应用层 sendBuffer → 内核 Send-Q → 网络 → 内核 Recv-Q → 应用层。
 * 应用层那两层是**最后**才涨的，带宽受限或对端慢时，排队全发生在中间两层里。
 * 当初「53 条连接 sndbuf 全为 0 却仍在丢包」就是栽在只看应用层这个口径上。
 *
 * 数据源是 /proc/net/tcp(6)，列格式：
 *   sl local_address rem_address st tx_queue:rx_queue tr:tm->when retrnsmt uid timeout inode
 *   0  1             2           3  4                 5           6        7   8       9
 * 地址与队列都是 hex；st=01 为 ESTABLISHED。
 *
 * 非 Linux（如开发机 macOS）读不到，一律返回 -1 表示「不可用」而**不是 0** ——
 * 0 会被读成「很健康」，那正是本类要消除的那类误读。
 */
final class KernelSocketQueue
{
    private const DEFAULT_FILES = ['/proc/net/tcp', '/proc/net/tcp6'];

    /** 队列不可用时的取值。刻意用 -1 而不是 0，见类注释 */
    public const UNAVAILABLE = -1;

    /**
     * ⚠️ 下面两处 PhpCapability::hasAll() 是必须的，不能只靠 `@`：
     *
     * ⚠️ 这个检查是必须的，不能只靠 `@`：
     * `@readlink(...)` 里的 `@` 只压制**诊断信息**（warning/notice），
     * 压不住 PHP 8 对"调用不存在的函数"抛出的 **Error**。
     * 而 php.ini 的 `disable_functions` 会把函数从函数表里整个摘掉 ——
     * 于是命名空间内的非限定调用连全局回退都找不到，报出来就是
     *     Call to undefined function app\lib\readlink()
     * 生产环境（尤其是被安全加固过的面板机）常把 readlink / symlink 一起禁掉。
     *
     * 现网就是这么炸的：采集器每一轮 stat 定时器都在这里抛 Error，
     * 而 Workerman 会吞掉定时器异常继续调度，于是进程毫发无损、指标永久冻结，
     * 监控页把它误报成"事件循环被阻塞"。观测组件**绝不能**把宿主的观测搞死。
     */

    /** 三个字段都取"不可用"的返回值 */
    private static function unavailable(): array
    {
        return ['tx' => self::UNAVAILABLE, 'rx' => self::UNAVAILABLE, 'conns' => 0];
    }

    /**
     * 按**本机监听端口**聚合。服务端用（分发机：所有订阅方连的都是同一个监听端口）。
     *
     * @return array{tx:int,rx:int,conns:int}
     */
    public static function byLocalPort(int $port, ?array $files = null): array
    {
        // 端口在 /proc 里是 4 位大写十六进制（8594 -> "2192"），可以当作**廉价预筛串**：
        // 命中才值得 preg_split 整行。判定仍由下面的回调做，预筛只负责快速排除。
        return self::scan(
            $files,
            static function (array $f) use ($port): bool {
                $sep = strrpos($f[1], ':');
                return $sep !== false && hexdec(substr($f[1], $sep + 1)) === $port;
            },
            ':' . strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT))
        );
    }

    /**
     * 只统计**本进程持有**的 socket。客户端用（采集器/订阅方：出向连接的本地端口是临时的，
     * 按端口认不出来，只能按 fd 对应的 inode 认）。
     *
     * @return array{tx:int,rx:int,conns:int}
     */
    public static function forSelf(?array $files = null, ?string $fdDir = null): array
    {
        // readlink/scandir 可能被 disable_functions 禁掉，见上面那段说明
        if (!PhpCapability::hasAll('readlink', 'scandir', 'is_dir')) return self::unavailable();

        $inodes = self::selfSocketInodes($fdDir ?? '/proc/self/fd');
        if ($inodes === null) return self::unavailable();
        return self::scan(
            $files,
            static fn(array $f): bool => isset($f[9]) && isset($inodes[$f[9]])
        );
    }

    /**
     * 本进程所有 socket 的 inode 集合。/proc/self/fd/N 是形如 "socket:[12345]" 的符号链接。
     * @return array<string,true>|null null = 本平台读不到
     */
    private static function selfSocketInodes(string $fdDir): ?array
    {
        if (!@is_dir($fdDir)) return null;
        $fds = @scandir($fdDir);
        if ($fds === false) return null;

        $inodes = [];
        foreach ($fds as $fd) {
            if ($fd === '.' || $fd === '..') continue;
            $link = @readlink($fdDir . '/' . $fd);
            if ($link === false) continue;                 // fd 可能在读的瞬间已关闭
            if (preg_match('/^socket:\[(\d+)\]$/', $link, $m)) {
                $inodes[$m[1]] = true;
            }
        }
        return $inodes;
    }

    /**
     * @param callable(array):bool $accept 逐行判定这一行是否属于我们要统计的连接
     * @param string $hint 可选的廉价预筛子串：整行不含它就直接跳过，连 split 都不做。
     *                     只能用于**必然出现在目标行里**的串，否则会漏统计。
     * @return array{tx:int,rx:int,conns:int}
     */
    private static function scan(?array $files, callable $accept, string $hint = ''): array
    {
        if (!PhpCapability::hasAll('fopen', 'fgets', 'fclose', 'is_readable')) return self::unavailable();

        $tx = 0; $rx = 0; $conns = 0; $found = false;

        foreach ($files ?? self::DEFAULT_FILES as $file) {
            if (!@is_readable($file)) continue;
            $found = true;
            $fh = @fopen($file, 'r');
            if (!$fh) continue;

            fgets($fh); // 表头
            while (($line = fgets($fh)) !== false) {
                // 先用 strpos 把绝大多数行挡在 preg_split 之前。
                // 繁忙机器上 /proc/net/tcp 动辄几千行，而其中 ESTABLISHED 往往是少数
                // （TIME_WAIT=06 通常占大头），对每一行都跑一次正则切分纯属浪费 ——
                // 而这段代码是每 10 秒被 stat 定时器调一次、跑在事件循环里的。
                //
                // 状态列在 /proc 格式里恒为「空格 + 两位十六进制 + 空格」，
                // 所以 ESTABLISHED 行必然含 " 01 "：预筛不会漏。
                if (strpos($line, ' 01 ') === false) continue;
                if ($hint !== '' && strpos($line, $hint) === false) continue;

                $f = preg_split('/\s+/', trim($line));
                if (!is_array($f) || count($f) < 5) continue;
                if (($f[3] ?? '') !== '01') continue;       // 只看 ESTABLISHED（预筛之后再确证一次）
                if (!$accept($f)) continue;

                $q = explode(':', $f[4]);
                if (count($q) !== 2) continue;
                $tx += (int)hexdec($q[0]);
                $rx += (int)hexdec($q[1]);
                $conns++;
            }
            fclose($fh);
        }

        return $found
            ? ['tx' => $tx, 'rx' => $rx, 'conns' => $conns]
            : self::unavailable();
    }
}
