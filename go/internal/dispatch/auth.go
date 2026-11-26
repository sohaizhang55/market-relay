package dispatch

// 订阅方鉴权：握手请求头 X-Inner-Token 命中哪个订阅方就是谁，并记下它这次的来源 IP。
//
// 订阅方和开关都在 PHP 后台「订阅方管理」里配，PHP 把它们发布成 Redis 快照（authKey），
// 这里每 5 秒读一次。快照格式见 PHP app\lib\DispatchAuth 的类注释，两边改格式要一起改。
// 快照读不到时沿用上一次读到的；启动后一次都没读到，就当开关关闭、没有订阅方（与 PHP 侧相同）。

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strconv"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
)

const (
	authKey     = "market:dispatch:auth"            // 鉴权快照，STRING（JSON），对应 PHP DispatchAuth::SNAP_KEY
	seenKey     = "market:dispatch:subscriber_seen" // 最后来源 IP，HASH，对应 PHP DispatchAuth::SEEN_KEY
	seenWS      = "ws"                              // seenKey 的 field 后缀，对应 PHP DispatchAuth::SEEN_WS
	authHeader  = "X-Inner-Token"                   // 对应 PHP DispatchAuth::HEADER
	authRefresh = 5 * time.Second                   // 多久重读一次快照，后台改完最长这么久生效
)

// subscriber 快照里的一个订阅方。
type subscriber struct {
	ID   int    `json:"id"`
	Name string `json:"name"`
}

// authSnap 鉴权快照。Subs 的键是 token 的 sha256 十六进制小写，只含启用的订阅方。
type authSnap struct {
	Enabled bool                  `json:"enabled"`
	Subs    map[string]subscriber `json:"subs"`
}

// authStore 当前生效的快照。握手 goroutine 读，刷新 goroutine 写。
type authStore struct {
	mu   sync.RWMutex
	snap authSnap
}

func (a *authStore) set(s authSnap) {
	a.mu.Lock()
	a.snap = s
	a.mu.Unlock()
}

// enabled 后台「分发鉴权」开关是否打开。
func (a *authStore) enabled() bool {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.snap.Enabled
}

// identify 按 token 找订阅方。token 为空、不存在或订阅方已停用都返回 false。
func (a *authStore) identify(token string) (subscriber, bool) {
	if token == "" {
		return subscriber{}, false
	}
	sum := sha256.Sum256([]byte(token))
	a.mu.RLock()
	defer a.mu.RUnlock()
	sub, ok := a.snap.Subs[hex.EncodeToString(sum[:])]
	return sub, ok
}

// loadAuth 从 Redis 重读快照。出错时保留上一次的快照。
func (s *Server) loadAuth(ctx context.Context) error {
	raw, err := s.rdb.Get(ctx, s.cfg.Prefix+authKey).Bytes()
	if errors.Is(err, redis.Nil) {
		return errors.New("鉴权快照不存在，沿用上一次的（PHP market:subscriber_sync 每 60 秒重写）")
	}
	if err != nil {
		return err
	}
	var snap authSnap
	if err := json.Unmarshal(raw, &snap); err != nil {
		return fmt.Errorf("鉴权快照不是合法 JSON，沿用上一次的: %w", err)
	}
	s.auth.set(snap)
	return nil
}

// markSeen 记下订阅方这次 WS 连接的来源 IP，PHP 后台读同一个 HASH。写失败只丢这一次记录。
func (s *Server) markSeen(ctx context.Context, sub subscriber, ip string) {
	_ = s.tasks.Run("auth_seen", func() error {
		ctx, cancel := context.WithTimeout(ctx, 2*time.Second)
		defer cancel()
		field := strconv.Itoa(sub.ID) + ":" + seenWS
		return s.rdb.HSet(ctx, s.cfg.Prefix+seenKey, field, ip+"|"+strconv.FormatInt(time.Now().Unix(), 10)).Err()
	})
}
