// Package heartbeat 进程心跳、实例注册表与任务存活证据。
//
// 对应 PHP 的三个类，写出来的 Redis 键与它们逐字节兼容，
// 后台「进程心跳」页和 command_heartbeat:check_expire 不用区分进程是 PHP 还是 Go：
//
//	CommandHeartBeatService  →  Beater（beat.go）、LiveInstances（instances.go）
//	TaskLiveness             →  Tasks（tasks.go）
//
// 单例守卫（SingletonGuardTrait）要和部署脚本的 restart 语义一起设计，之后再加。
// 键的格式见 keys.go，改动要和 root/app/lib/CommandHeartBeatService.php 同步。
package heartbeat
