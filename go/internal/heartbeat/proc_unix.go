//go:build !windows

// 上面那行是 build tag：只在非 Windows 平台编译这个文件，Windows 用 proc_windows.go。

package heartbeat

import (
	"errors"
	"syscall"
)

// processAlive 本机上 pid 对应的进程是否还在。对应 PHP 的 posix_kill($pid, 0)。
//
// 信号 0 不会真的发给进程，只检查它是否存在。
// EPERM 表示进程存在、只是无权发信号，也算活着；
// PHP 的 posix_kill 遇到 EPERM 会返回 false，这里刻意不照搬那个误判。
func processAlive(pid int) bool {
	if pid <= 0 {
		// kill(0, 0) 检查的是整个进程组，一定成功，会把坏数据当成活进程
		return false
	}
	err := syscall.Kill(pid, 0)
	return err == nil || errors.Is(err, syscall.EPERM)
}
