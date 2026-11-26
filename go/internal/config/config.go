// Package config Go 分发机自己的配置，读 go/.env（模板见 go/.env.example）。
//
// 与 market-relay 的 root/.env 分开：两边各管各的，改 Go 的端口、节拍不会碰到 PHP。
// 唯一要两边对齐的是 Redis 连接与键前缀 —— Go 读的是 PHP 写进去的快照，连错库或前缀不对，
// 读到的会是别的键，而且不会报错。
package config

import (
	"errors"
	"fmt"
)

// Redis 连接。必须和 market-relay 采集器用的是同一个库、同一个前缀。
type Redis struct {
	Host     string
	Port     int
	Password string
	DB       int    // 对应 market-relay 的 CACHE_REDIS_SELECT
	Prefix   string // 对应 market-relay 的 CACHE_PREFIX：PHP 会给每个键自动加上它，Go 要自己拼
}

// Dispatch 分发机。
type Dispatch struct {
	Host        string
	SpotPort    int
	SwapPort    int
	TickMs      int
	LagCloseSec int
	AllowIPs    []string // 为空 = 不限制来源 IP
	Dirty       string   // shadow = 读影子脏集合，与 PHP 分发并行；main = 读正式脏集合，PHP 分发必须先停
}

// Config 全部配置。
type Config struct {
	Redis    Redis
	Dispatch Dispatch
}

// Load 读取配置文件，没写的项用默认值。
func Load(path string) (*Config, error) {
	e, err := readEnv(path)
	if err != nil {
		return nil, fmt.Errorf("读取配置 %s 失败（第一次用请先复制 .env.example 为 .env）: %w", path, err)
	}

	// 前缀没有安全的默认值：写错了会静默读到别的键，所以必须显式写出来（允许写成空）
	prefix, ok := e["REDIS_PREFIX"]
	if !ok {
		return nil, errors.New("配置缺少 REDIS_PREFIX，要和 market-relay root/.env 的 CACHE_PREFIX 一致")
	}

	c := &Config{
		Redis: Redis{
			Host:     e.str("REDIS_HOST", "127.0.0.1"),
			Port:     e.num("REDIS_PORT", 6379),
			Password: e.str("REDIS_PASSWORD", ""),
			DB:       e.num("REDIS_DB", 0),
			Prefix:   prefix,
		},
		Dispatch: Dispatch{
			Host:        e.str("DISPATCH_HOST", "127.0.0.1"),
			SpotPort:    e.num("DISPATCH_SPOT_PORT", 18594),
			SwapPort:    e.num("DISPATCH_SWAP_PORT", 18595),
			TickMs:      max(10, e.num("DISPATCH_TICK_MS", 100)),
			LagCloseSec: max(1, e.num("DISPATCH_LAG_CLOSE_SEC", 5)),
			AllowIPs:    e.list("DISPATCH_ALLOW_IPS"),
			Dirty:       e.str("DISPATCH_DIRTY", "shadow"),
		},
	}
	if c.Dispatch.Dirty != "shadow" && c.Dispatch.Dirty != "main" {
		return nil, fmt.Errorf("DISPATCH_DIRTY 只能是 shadow 或 main，现在是 %q", c.Dispatch.Dirty)
	}
	return c, nil
}
