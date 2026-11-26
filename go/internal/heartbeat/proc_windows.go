//go:build windows

package heartbeat

// processAlive Windows 上不检查 pid，一律当作活着，只靠过期时间剪枝。
// 与 PHP 在没有 ext-posix 时跳过这道剪枝的做法一致；线上是 Linux，这里只为本机开发能编译。
func processAlive(pid int) bool {
	return pid > 0
}
