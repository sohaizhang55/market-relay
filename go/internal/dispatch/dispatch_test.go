package dispatch

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net"
	"strings"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/coder/websocket"
	"github.com/redis/go-redis/v9"

	"market-relay/go/internal/heartbeat"
	"market-relay/go/internal/wire"
)

const (
	snapKey  = "market:dispatch:pair_snap:2"
	dirtyKey = "market:dispatch:pair_dirty_shadow:2"
)

// startServer 用内存 Redis 起一个分发机，返回 Redis 和 ws:// 地址。测试结束自动停止。
func startServer(t *testing.T) (*miniredis.Miniredis, string) {
	t.Helper()
	m, _, url := startServerWith(t, nil)
	return m, url
}

// startServerWith 同 startServer，mod 可以在启动前改配置；多返回 Server，测试里可以直接调它的方法。
func startServerWith(t *testing.T, mod func(*Config)) (*miniredis.Miniredis, *Server, string) {
	t.Helper()
	m := miniredis.RunT(t)
	rdb := redis.NewClient(&redis.Options{Addr: m.Addr(), Protocol: 2})
	t.Cleanup(func() { _ = rdb.Close() })

	ln, err := net.Listen("tcp", "127.0.0.1:0") // 端口 0 = 让系统分配一个空闲端口
	if err != nil {
		t.Fatal(err)
	}
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	cfg := Config{
		Type: wire.TypeSwap, Tick: 50 * time.Millisecond, LagClose: 5 * time.Second,
		SnapKey: snapKey, DirtyKey: dirtyKey,
	}
	if mod != nil {
		mod(&cfg)
	}
	s := New(cfg, rdb, heartbeat.NewTasks(log), log)

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { _ = s.Serve(ctx, ln); close(done) }()
	t.Cleanup(func() { cancel(); <-done })
	return m, s, "ws://" + ln.Addr().String() + "/"
}

// waitFor 等 cond 成立，最多 2 秒。用来等别的 goroutine 把事情做完。
func waitFor(t *testing.T, what string, cond func() bool) {
	t.Helper()
	for deadline := time.Now().Add(2 * time.Second); time.Now().Before(deadline); time.Sleep(10 * time.Millisecond) {
		if cond() {
			return
		}
	}
	t.Fatalf("等了 2 秒仍未满足：%s", what)
}

// dial 连上并发 hello。
func dial(t *testing.T, url, hello string) *websocket.Conn {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	ws, _, err := websocket.Dial(ctx, url, nil)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = ws.CloseNow() })
	if err := ws.Write(ctx, websocket.MessageText, []byte(hello)); err != nil {
		t.Fatal(err)
	}
	return ws
}

// recv 读一条消息；行情帧解压后返回 JSON，系统帧原样返回。
func recv(t *testing.T, ws *websocket.Conn) string {
	t.Helper()
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	typ, data, err := ws.Read(ctx)
	if err != nil {
		t.Fatalf("没收到消息: %v", err)
	}
	if typ == websocket.MessageText {
		return string(data)
	}
	js, err := wire.Inflate(data)
	if err != nil {
		t.Fatalf("解压失败: %v", err)
	}
	return string(js)
}

func TestHelloReplayThenTick(t *testing.T) {
	m, url := startServer(t)
	m.HSet(snapKey, "market:raw:price|btcusdt_2", `{"pair":"btcusdt_2","close":"100"}`)
	m.HSet(snapKey, "market:raw:depth|btcusdt_2", `{"pair":"btcusdt_2","depth":[]}`)

	ws := dial(t, url, `{"cmd":"hello","streams":["btcusdt@ticker"]}`)

	// 补快照：只补订阅的 ticker，不补 depth
	if got := recv(t, ws); got != `{"stream":"btcusdt@ticker","pair":"btcusdt_2","close":"100"}` {
		t.Fatalf("补快照收到 %s", got)
	}

	// 采集侧写新值并标脏，下一个 tick 应该推过来
	m.HSet(snapKey, "market:raw:price|btcusdt_2", `{"pair":"btcusdt_2","close":"101"}`)
	m.SAdd(dirtyKey, "market:raw:price|btcusdt_2")
	if got := recv(t, ws); !strings.Contains(got, `"close":"101"`) {
		t.Fatalf("tick 收到 %s", got)
	}
	if ok, _ := m.SIsMember(dirtyKey, "market:raw:price|btcusdt_2"); ok {
		t.Error("脏集合没有被取走")
	}
}

func TestInvalidHelloGetsErrorFrame(t *testing.T) {
	_, url := startServer(t)
	ws := dial(t, url, `{"cmd":"hello","streams":["btcusdt@markPrice@500ms"]}`)
	got := recv(t, ws)
	if !strings.HasPrefix(got, `{"_sys":"error"`) || !strings.Contains(got, "只有 depth 可以指定间隔") {
		t.Fatalf("收到 %s", got)
	}
}

// 选了 500ms 档的 depth，连续变化多次也只在慢档定时器到点时发最新的一次。
func TestDepthSlowTierSendsLatestOnly(t *testing.T) {
	m, url := startServer(t)
	ws := dial(t, url, `{"cmd":"hello","streams":["btcusdt@depth@500ms"]}`)
	time.Sleep(100 * time.Millisecond) // 等 hello 生效（快照里没有 depth，不会补快照）

	for i := 1; i <= 3; i++ {
		m.HSet(snapKey, "market:raw:depth|btcusdt_2", fmt.Sprintf(`{"v":%d}`, i))
		m.SAdd(dirtyKey, "market:raw:depth|btcusdt_2")
		time.Sleep(60 * time.Millisecond) // 每次都隔开一个 tick，让三次变化各被取走一次
	}
	if got := recv(t, ws); got != `{"stream":"btcusdt@depth","v":3}` {
		t.Fatalf("应只收到最新的 v=3，收到 %s", got)
	}
}

// 覆盖类 stream 只留最新一帧，trade / kline 排队。
func TestMailboxOverwriteAndQueue(t *testing.T) {
	box := newMailbox()
	box.put(entry{stream: "a@depth", data: []byte("1")}, true)
	box.put(entry{stream: "a@trade", data: []byte("t1")}, false)
	box.put(entry{stream: "a@depth", data: []byte("2")}, true)
	box.put(entry{stream: "a@trade", data: []byte("t2")}, false)
	box.putSys(entry{data: []byte("ping")})

	var got []string
	for _, e := range box.take() {
		got = append(got, string(e.data))
	}
	if want := "ping,2,t1,t2"; strings.Join(got, ",") != want {
		t.Errorf("取出顺序 %v，应为 %s", got, want)
	}
	if len(box.take()) != 0 {
		t.Error("take 之后应该清空")
	}
}

// 积压断开的现场：速率按窗口折算、往返时延取累计值，超过 ping 间隔 + lag_close + 5 秒没回 pong 判为无响应。
func TestLagScene(t *testing.T) {
	s := &Server{cfg: Config{LagClose: 5 * time.Second}}
	now := time.Now()
	c := &conn{
		connectedAt: now.Add(-100 * time.Second), box: newMailbox(),
		sent: sentStat{since: now.Add(-5 * time.Second), msgs: 50, bytes: 5000, kinds: map[string]int64{},
			rttN: 2, rttSum: 200, rttMax: 150},
	}
	c.box.put(entry{stream: "a@trade"}, false)
	c.box.put(entry{stream: "a@trade"}, false)
	c.writingSince.Store(now.Add(-1500 * time.Millisecond).UnixMilli())

	c.lastActive.Store(now.Add(-40 * time.Second).Unix())
	sc := s.lagScene(c, "all", now)
	if sc["peer"] != "unresponsive" || sc["pong_age_sec"] != int64(40) || sc["pong_stale_sec"] != int64(25) {
		t.Errorf("40 秒没回 pong 应判无响应，阈值 15+5+5=25：%v", sc)
	}
	if sc["msgs_ps"] != 10.0 || sc["bytes_ps"] != int64(1000) || sc["alive_sec"] != int64(100) || sc["streams"] != "all" {
		t.Errorf("速率按 5 秒窗口折算、已连 100 秒：%v", sc)
	}
	if *sc["rtt_avg"].(*float64) != 100 || *sc["rtt_max"].(*float64) != 150 {
		t.Errorf("往返时延 = %v / %v", *sc["rtt_avg"].(*float64), *sc["rtt_max"].(*float64))
	}
	if sc["pending_now"] != 2 || sc["pending_peak"] != 2 || sc["writing_ms"] != int64(1500) {
		t.Errorf("待发表 2 条、当前写已卡 1500ms：%v", sc)
	}

	c.lastActive.Store(now.Add(-3 * time.Second).Unix())
	c.writingSince.Store(0)
	sc = s.lagScene(c, "all", now)
	if sc["peer"] != "alive" {
		t.Errorf("3 秒前还回过 pong 应判活着：%v", sc)
	}
	if _, ok := sc["writing_ms"]; ok {
		t.Error("没在写时不该有 writing_ms")
	}
	// 窗口内的统计不能被现场采集清零，否则下一次 report 的数字会少
	if c.sent.msgs != 50 || c.box.peak != 2 {
		t.Errorf("lagScene 不能清零窗口：msgs=%d peak=%d", c.sent.msgs, c.box.peak)
	}
}

// 指标和事件要写成 PHP 后台能直接读的样子：键带前缀、buf_state 按连接 id 展开、事件最新的在头部。
func TestReportWritesMetricsAndEvents(t *testing.T) {
	m, s, url := startServerWith(t, func(c *Config) { c.Name = "w_Go"; c.Prefix = "p_" })
	m.HSet(snapKey, "market:raw:price|btcusdt_2", `{"close":"100"}`)
	ws := dial(t, url, `{"cmd":"hello","node":"n1","streams":["btcusdt@ticker"]}`)
	recv(t, ws) // 补快照
	m.HSet(snapKey, "market:raw:price|btcusdt_2", `{"close":"101"}`)
	m.SAdd(dirtyKey, "market:raw:price|btcusdt_2")
	recv(t, ws) // tick 推来的
	// recv 返回时写 goroutine 可能还没把这一批记进统计
	waitFor(t, "发出统计", func() bool { return s.stat.msgs.Load() == 1 })

	if err := s.report(context.Background()); err != nil {
		t.Fatal(err)
	}
	key := "p_market:metrics:worker:w_Go"
	if got := m.HGet(key, "impl"); got != "go" {
		t.Errorf("impl = %q", got)
	}
	if got := m.HGet(key, "msgs_10s"); got != "1" {
		t.Errorf("msgs_10s = %q，补快照那一帧不该计入", got)
	}
	if ok, _ := m.SIsMember("p_market:metrics:workers", "w_Go"); !ok {
		t.Error("worker 名没进注册表")
	}

	var state map[string]connState
	if err := json.Unmarshal([]byte(m.HGet(key, "buf_state")), &state); err != nil || len(state) != 1 {
		t.Fatalf("buf_state = %s", m.HGet(key, "buf_state"))
	}
	for _, c := range state {
		if c.Node != "n1" || c.Health != "ok" || c.Streams != "btcusdt@ticker" || c.Kinds["ticker"] == 0 {
			t.Errorf("连接现场 = %+v", c)
		}
	}

	evKey := "p_" + eventKey
	waitFor(t, "三条事件", func() bool { l, _ := m.List(evKey); return len(l) == 3 })
	list, _ := m.List(evKey)
	var types []string
	for _, js := range list {
		var e map[string]any
		_ = json.Unmarshal([]byte(js), &e)
		types = append(types, fmt.Sprint(e["type"]))
	}
	if want := "snapshot_replay,subscriber_hello,subscriber_connect"; strings.Join(types, ",") != want {
		t.Errorf("事件（新的在前）= %v，应为 %s", types, want)
	}
}

func TestDescribeStreams(t *testing.T) {
	cases := map[string]map[string]int{
		"all":                               nil,
		"btcusdt@depth@500ms+btcusdt@trade": {"btcusdt@trade": 0, "btcusdt@depth": 500},
		"btcusdt@depth":                     {"btcusdt@depth": 100},
	}
	for want, in := range cases {
		if got := describeStreams(in); got != want {
			t.Errorf("describeStreams(%v) = %q，应为 %q", in, got, want)
		}
	}
}
