package heartbeat

import (
	"os"
	"time"
)

// Redis 键名，与 PHP CommandHeartBeatService 的同名常量一一对应。
// 读这些键的是 PHP（后台心跳页、check_expire），所以一个字符都不能差。
//
// ⚠️ 实际写进 Redis 的键还要在最前面加上 CACHE_PREFIX（.env 里配置，默认 market_relay_）：
// PHP 的 redis_client() 在连接层自动加这个前缀，例如 market_relay_heartbeat:registry。
// go-redis 没有这个功能，所以 Beater 和 LiveInstances 都要传入 prefix，自己拼。
const (
	// KeyPrefix 存活心跳：heartbeat:cmd:{name}，STRING，TTL = 3 × 间隔，
	// 值为 JSON {"pid","tick","ts","tasks"}，见 beatPayload。
	KeyPrefix = "heartbeat:cmd:"

	// KeyInstPrefix 实例注册表：heartbeat:inst:{name}，HASH，
	// field = {hostname}#{pid}，value = 最后心跳的毫秒时间戳。
	// cmd 键是覆盖写的，同名进程起了几个它看不出来；这张表每个实例占一个 field。
	KeyInstPrefix = "heartbeat:inst:"

	// KeyStoppingPrefix 正常停机标记：heartbeat:stopping:{name}，TTL 60 秒，值为 pid。
	// 有它，check_expire 才分得清「计划内重启」和「进程真的没了」。
	KeyStoppingPrefix = "heartbeat:stopping:"

	// RegistryKey 跑过的 worker 名字集合，SET，只加不删。
	RegistryKey = "heartbeat:registry"
)

// DefaultInterval 心跳间隔，与 PHP DEFAULT_INTERVAL 相同。
// PHP 判活侧用 tick × 5 秒推算运行时长，所以不要随便改。
const DefaultInterval = 5 * time.Second

// stoppingTTL 停机标记的兜底寿命：正常情况下新进程第二拍就删掉它，
// 这个 TTL 只管「停了但起不来」，到期后恢复告警。
const stoppingTTL = 60 * time.Second

// instTTL 实例注册表整个 HASH 的寿命，PHP 是 max(3600, interval × 60) 秒。
// 全部实例都被 kill -9 时，没人来剪枝，它自己也会消失。
func instTTL(interval time.Duration) time.Duration {
	return max(time.Hour, interval*60)
}

// HostName 本机主机名，取不到时用固定字符串（PHP 同样处理）。
// Go 的 os.Hostname 与 PHP 的 gethostname 在同一台 Linux 上结果相同，
// 所以 PHP 与 Go 进程写进注册表的 host 可以直接比较。
func HostName() string {
	h, err := os.Hostname()
	if err != nil || h == "" {
		return "unknown-host"
	}
	return h
}
