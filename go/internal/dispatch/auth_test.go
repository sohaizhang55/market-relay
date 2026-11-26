package dispatch

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/coder/websocket"
)

// setAuth 写一份鉴权快照（格式同 PHP DispatchSubscriberService::publish()）并让分发机立即读到。
func setAuth(t *testing.T, s *Server, m interface{ Set(k, v string) error }, enabled bool, token string) {
	t.Helper()
	sum := sha256.Sum256([]byte(token))
	snap := `{"enabled":` + map[bool]string{true: "true", false: "false"}[enabled] +
		`,"subs":{"` + hex.EncodeToString(sum[:]) + `":{"id":7,"name":"server-b"}},"ts":1}`
	if err := m.Set(authKey, snap); err != nil {
		t.Fatal(err)
	}
	if err := s.loadAuth(context.Background()); err != nil {
		t.Fatal(err)
	}
}

// dialToken 带 X-Inner-Token 头握手，返回连接与 HTTP 状态码（握手失败时连接为 nil）。
func dialToken(t *testing.T, url, token string) (*websocket.Conn, int) {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	h := http.Header{}
	if token != "" {
		h.Set(authHeader, token)
	}
	ws, resp, err := websocket.Dial(ctx, url, &websocket.DialOptions{HTTPHeader: h})
	code := 0
	if resp != nil {
		code = resp.StatusCode
	}
	if err != nil {
		return nil, code
	}
	t.Cleanup(func() { _ = ws.CloseNow() })
	return ws, code
}

func TestAuthEnabledRejectsMissingAndWrongToken(t *testing.T) {
	m, s, url := startServerWith(t, nil)
	setAuth(t, s, m, true, "good-token")

	for _, tok := range []string{"", "bad-token"} {
		if ws, code := dialToken(t, url, tok); ws != nil || code != http.StatusForbidden {
			t.Fatalf("token=%q：期望 403 拒绝，实际 code=%d ws=%v", tok, code, ws != nil)
		}
	}
	if keys, _ := m.HKeys(seenKey); len(keys) != 0 {
		t.Fatalf("被拒的连接不该记最后来源，实际记了 %v", keys)
	}
}

func TestAuthEnabledAcceptsGoodTokenAndRecordsSeen(t *testing.T) {
	m, s, url := startServerWith(t, nil)
	setAuth(t, s, m, true, "good-token")

	if ws, _ := dialToken(t, url, "good-token"); ws == nil {
		t.Fatal("有效 token 应该能连上")
	}
	waitFor(t, "记下最后来源", func() bool { return m.HGet(seenKey, "7:ws") != "" })
	if v := m.HGet(seenKey, "7:ws"); !strings.HasPrefix(v, "127.0.0.1|") {
		t.Fatalf("最后来源应为 127.0.0.1|<秒>，实际 %q", v)
	}
	waitFor(t, "连接带上订阅方名称", func() bool {
		s.mu.Lock()
		defer s.mu.Unlock()
		for c := range s.conns {
			if c.sub == "server-b" {
				return true
			}
		}
		return false
	})
}

func TestAuthDisabledLetsAnyoneIn(t *testing.T) {
	m, s, url := startServerWith(t, nil)
	setAuth(t, s, m, false, "good-token")

	if ws, _ := dialToken(t, url, ""); ws == nil {
		t.Fatal("开关关闭时不带 token 也应能连上")
	}
	if ws, _ := dialToken(t, url, "bad-token"); ws == nil {
		t.Fatal("开关关闭时带错 token 也应能连上")
	}
}

func TestAuthKeepsLastSnapshotWhenKeyMissing(t *testing.T) {
	m, s, url := startServerWith(t, nil)
	setAuth(t, s, m, true, "good-token")
	m.Del(authKey)
	if err := s.loadAuth(context.Background()); err == nil {
		t.Fatal("快照不存在时应返回错误")
	}
	if ws, code := dialToken(t, url, ""); ws != nil || code != http.StatusForbidden {
		t.Fatalf("快照丢了应沿用上一次（开关开），期望 403，实际 code=%d", code)
	}
}
