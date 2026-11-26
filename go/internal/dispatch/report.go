package dispatch

// 监控上报：进程指标与分发事件写进 Redis，后台「Go 分发监控」页（GoDispatchMonitor）读取。
// 键名和字段名与 PHP MarketMetrics / MarketDispatcher 对齐，只有分发事件单独一个列表，见 eventKey。
// 所有键都要加 Config.Prefix（PHP 的 CACHE_PREFIX），否则 PHP 读不到。

import (
	"context"
	"encoding/json"
	"maps"
	"math"
	"os"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/redis/go-redis/v9"

	"market-relay/go/internal/wire"
)

const (
	workerKeyPrefix = "market:metrics:worker:" // + worker name，HASH，对应 PHP MarketMetrics::WORKER_PREFIX
	workerRegistry  = "market:metrics:workers" // 上报过指标的 worker 名，SET，对应 PHP MarketMetrics::WORKER_REGISTRY

	// eventKey Go 的分发事件，LIST，新的在头部。对应 PHP MarketMetrics::DISPATCH_EVENT_GO_KEY。
	// 不和 PHP 共用 market:metrics:dispatch_event：「行情链路监控」的健康判定会统计那个列表里的积压断开，
	// 试跑中的 Go 不该影响它。
	eventKey   = "market:metrics:dispatch_event_go"
	eventKeep  = 2000 // 列表最多保留几条，与 PHP DISPATCH_EVENT_KEEP 相同
	eventQueue = 1024 // 还没写进 Redis 的事件最多攒几条，满了就丢弃并计数（event_drop_10s）
	eventBatch = 200  // 一次最多写几条
)

// stats 进程级计数器，每 10 秒由 report() 取走后清零。多个 goroutine 都会加，所以用原子类型。
type stats struct {
	popped, msgs, bytes, deflate, lagClose, hbKick, eventDrop atomic.Int64

	tickMaxUs atomic.Int64 // 本窗口最慢的一次 tick 耗时（微秒）

	kindMu    sync.Mutex
	kindBytes map[string]int64 // stream 类别 → 发出字节数
}

func (st *stats) addKinds(kinds map[string]int64) {
	st.kindMu.Lock()
	defer st.kindMu.Unlock()
	if st.kindBytes == nil {
		st.kindBytes = make(map[string]int64)
	}
	for k, n := range kinds {
		st.kindBytes[k] += n
	}
}

func (st *stats) takeKinds() map[string]int64 {
	st.kindMu.Lock()
	defer st.kindMu.Unlock()
	out := st.kindBytes
	st.kindBytes = nil
	if out == nil {
		out = map[string]int64{}
	}
	return out
}

// storeMax 把 v 记进 a，只有比原值大时才写。CompareAndSwap 失败说明期间被别人改过，重读再比。
func storeMax(a *atomic.Int64, v int64) {
	for {
		old := a.Load()
		if v <= old || a.CompareAndSwap(old, v) {
			return
		}
	}
}

// connState 一条连接的监控现场，字段名与 PHP MarketDispatcher::summarizeBufState() 对齐。
// Go 没有发送缓冲和降级档位，换成待发表的条数和被合并掉的帧数。
type connState struct {
	MsgsPs      float64          `json:"msgs_ps"`  // 每秒发出几条（不含补快照）
	BytesPs     int64            `json:"bytes_ps"` // 每秒发出字节数（压缩后）
	Kinds       map[string]int64 `json:"kinds"`    // stream 类别 → 字节/秒
	IP          string           `json:"ip"`
	AliveSec    int64            `json:"alive_sec"`
	Node        string           `json:"node"`
	Sub         string           `json:"sub"`    // 握手头 token 认出的订阅方名称
	Health      string           `json:"health"` // closing / lagging / no_hello / ok
	Held        int64            `json:"held"`   // 当前这次写已经阻塞了几秒，lagging 时才有
	Streams     string           `json:"streams"`
	Pending     int              `json:"pending"`      // 待发表当前几条
	PendingPeak int              `json:"pending_peak"` // 最近 10 秒最多几条
	Merged      int64            `json:"merged_10s"`   // 最近 10 秒被新帧替换掉、没发出去的旧帧数
	RttAvg      *float64         `json:"rtt_avg"`      // 指针：还没收到过 pong 时是 nil，JSON 里输出 null
	RttMax      *float64         `json:"rtt_max"`
}

// report 每 10 秒：输出一行统计日志，并把进程指标写进 Redis。计数器取走后清零。
func (s *Server) report(ctx context.Context) error {
	now := time.Now()
	popped := s.stat.popped.Swap(0)
	msgs := s.stat.msgs.Swap(0)
	bytes := s.stat.bytes.Swap(0)
	deflate := s.stat.deflate.Swap(0)
	lagClose := s.stat.lagClose.Swap(0)
	hbKick := s.stat.hbKick.Swap(0)
	eventDrop := s.stat.eventDrop.Swap(0)
	tickMaxMs := round1(float64(s.stat.tickMaxUs.Swap(0)) / 1000)
	kinds := s.stat.takeKinds()

	s.mu.Lock()
	subs, rooms := len(s.conns), len(s.rooms)
	state := make(map[string]connState, subs)
	var merged int64
	for c := range s.conns {
		st := s.connStateLocked(c, now)
		merged += st.Merged
		state[strconv.FormatInt(c.id, 10)] = st
	}
	s.mu.Unlock()

	s.log.Info("stat", "popped", popped, "deflate", deflate, "msgs", msgs, "bytes", bytes,
		"merged", merged, "lag_close", lagClose, "hb_kick", hbKick, "tick_max_ms", tickMaxMs,
		"conns", subs, "rooms", rooms)
	if s.cfg.Name == "" {
		return nil
	}

	var mem runtime.MemStats
	runtime.ReadMemStats(&mem)
	s.memPeak = max(s.memPeak, mem.Sys) // 只有 stat 这一个 goroutine 碰它，不用锁

	tasksJS, _ := json.Marshal(s.tasks.Snapshot())
	kindsJS, _ := json.Marshal(kinds)
	stateJS, _ := json.Marshal(state)
	fields := map[string]any{
		"impl":       "go",
		"update_ts":  now.UnixMilli(),
		"pid":        os.Getpid(),
		"boot_ts":    s.bootAt.UnixMilli(),
		"uptime_sec": int64(now.Sub(s.bootAt).Seconds()),
		"mem":        mem.Sys, // 向系统申请的总内存，对应 PHP memory_get_usage(true)
		"mem_peak":   s.memPeak,
		"goroutines": runtime.NumGoroutine(),
		"listen":     s.cfg.Addr,
		"dirty_key":  s.cfg.DirtyKey,
		"tasks":      string(tasksJS),

		"popped_10s":     popped,
		"rate_fps":       round1(float64(popped) / 10),
		"deflate_10s":    deflate,
		"msgs_10s":       msgs,
		"bytes_10s":      bytes,
		"kind_bytes_10s": string(kindsJS),
		"tick_max_ms":    tickMaxMs,
		"merged_10s":     merged,
		"lag_close_10s":  lagClose,
		"hb_kick_10s":    hbKick,
		"event_drop_10s": eventDrop,
		"subscribers":    subs,
		"rooms":          rooms,
		"pending_limit":  maxPending,
		"buf_state":      string(stateJS),
	}
	key := s.cfg.Prefix + workerKeyPrefix + s.cfg.Name
	_, err := s.rdb.Pipelined(ctx, func(p redis.Pipeliner) error {
		p.HSet(ctx, key, fields)
		p.SAdd(ctx, s.cfg.Prefix+workerRegistry, s.cfg.Name)
		return nil
	})
	return err
}

// connStateLocked 取一条连接的现场，并开始新的统计窗口。调用方必须持有 s.mu。
func (s *Server) connStateLocked(c *conn, now time.Time) connState {
	pending, peak, merged := c.box.sample()
	st := connState{
		IP:          c.ip,
		Node:        c.node,
		Sub:         c.sub,
		AliveSec:    int64(now.Sub(c.connectedAt).Seconds()),
		Streams:     describeStreams(c.streams),
		Pending:     pending,
		PendingPeak: peak,
		Merged:      merged,
	}
	st.Health, st.Held = health(c, now)

	c.sent.mu.Lock()
	defer c.sent.mu.Unlock()
	// 刚连上不满 10 秒的按实际秒数折算；下限 1 秒，免得刚连上几毫秒就被折算成几百条/秒
	win := max(1.0, now.Sub(c.sent.since).Seconds())
	st.MsgsPs = round1(float64(c.sent.msgs) / win)
	st.BytesPs = int64(math.Round(float64(c.sent.bytes) / win))
	st.Kinds = make(map[string]int64, len(c.sent.kinds))
	for k, n := range c.sent.kinds {
		st.Kinds[k] = int64(math.Round(float64(n) / win))
	}
	if c.sent.rttN > 0 {
		avg, mx := round1(c.sent.rttSum/float64(c.sent.rttN)), round1(c.sent.rttMax)
		st.RttAvg, st.RttMax = &avg, &mx
	}
	c.sent.msgs, c.sent.bytes, c.sent.since = 0, 0, now
	clear(c.sent.kinds)
	return st
}

// lagScene 积压断开那一刻这条连接的现场，并进 subscriber_lag_close 事件，后台据此分辨「卡死」还是「慢」：
//
//	peer=unresponsive  超过 pong_stale_sec 秒没回 pong：对方多半已掉线、休眠或程序卡死
//	peer=alive         最近还回过 pong：对方活着，多半是带宽或处理能力跟不上
//
// 只读不清零，不影响每 10 秒的监控窗口；msgs_ps / bytes_ps / pending_peak 是从上次 report 到现在（≤10 秒）。
// streams 由调用方传：c.streams 归 s.mu 保护，pending_full 路径持有 s.mu、write_timeout 路径没有，这里不能自己拿锁。
// peer 是倾向而非定论：慢到一批要发很久时，排在批后的 ping 也会被拖后，活着的对方也可能被判成 unresponsive。
func (s *Server) lagScene(c *conn, streams string, now time.Time) map[string]any {
	// ping 由写 goroutine 发出，我们这边写阻塞时 ping 也晚发，阈值在 ping 间隔上再加 lag_close 和 5 秒余量
	stale := int64(pingInterval) + int64(s.cfg.LagClose.Seconds()) + 5
	pongAge := now.Unix() - c.lastActive.Load()
	peer := "alive"
	if pongAge > stale {
		peer = "unresponsive"
	}

	c.box.mu.Lock()
	pending := len(c.box.pending)
	peak := max(c.box.peak, pending)
	c.box.mu.Unlock()

	c.sent.mu.Lock()
	win := max(1.0, now.Sub(c.sent.since).Seconds())
	msgsPs := round1(float64(c.sent.msgs) / win)
	bytesPs := int64(math.Round(float64(c.sent.bytes) / win))
	var rttAvg, rttMax *float64 // 还没收到过 pong 时是 nil，JSON 里输出 null
	if c.sent.rttN > 0 {
		avg, mx := round1(c.sent.rttSum/float64(c.sent.rttN)), round1(c.sent.rttMax)
		rttAvg, rttMax = &avg, &mx
	}
	c.sent.mu.Unlock()

	scene := map[string]any{
		"peer": peer, "pong_age_sec": pongAge, "pong_stale_sec": stale,
		"rtt_avg": rttAvg, "rtt_max": rttMax, "msgs_ps": msgsPs, "bytes_ps": bytesPs,
		"pending_now": pending, "pending_peak": peak,
		"streams": streams, "alive_sec": int64(now.Sub(c.connectedAt).Seconds()),
	}
	// pending_full 时写 goroutine 可能正卡在某一次写上；write_timeout 走到这里时已经清零
	if ws := c.writingSince.Load(); ws > 0 {
		scene["writing_ms"] = now.UnixMilli() - ws
	}
	return scene
}

// streamsOf 订阅描述，给没有持有 s.mu 的地方用。
func (s *Server) streamsOf(c *conn) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return describeStreams(c.streams)
}

// health 连接状态，顺序即优先级。对应 PHP MarketDispatcher::connHealth()，
// Go 没有 handshaking（补快照是同步完成的）和 degraded（没有降级）。
func health(c *conn, now time.Time) (string, int64) {
	if c.closing.Load() {
		return "closing", 0
	}
	if ws := c.writingSince.Load(); ws > 0 && now.UnixMilli()-ws >= lagShowAfter.Milliseconds() {
		return "lagging", (now.UnixMilli() - ws) / 1000
	}
	if !c.helloOK {
		return "no_hello", 0
	}
	return "ok", 0
}

// event 记一条分发事件，字段与 PHP MarketMetrics::dispatchEvent() 相同。
// 只放进队列、不碰 Redis（由 eventLoop 批量写），所以持有 s.mu 时也能调用。
// node 由调用方传：c.node 归 s.mu 保护，持锁的地方直接传 c.node，没持锁的用 s.nodeOf(c)。
func (s *Server) event(typ string, c *conn, node string, fields map[string]any) {
	if s.cfg.Name == "" {
		return
	}
	e := map[string]any{
		"ts": time.Now().UnixMilli(), "type": typ, "market_type": s.cfg.Type,
		"conn": c.id, "node": node, "sub": c.sub, "ip": c.ip,
	}
	maps.Copy(e, fields)
	b, err := json.Marshal(e)
	if err != nil {
		return
	}
	select {
	case s.events <- b:
	default:
		s.stat.eventDrop.Add(1) // 队列满说明 Redis 写不进去，丢掉事件也不能卡住分发
	}
}

// nodeOf 取连接的机器名，给没有持有 s.mu 的地方用。
func (s *Server) nodeOf(c *conn) string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return c.node
}

// eventLoop 把事件队列批量写进 Redis，直到 ctx 取消。退出时队列里没写完的直接丢弃。
func (s *Server) eventLoop(ctx context.Context) {
	key := s.cfg.Prefix + eventKey
	for {
		var batch []any
		select {
		case <-ctx.Done():
			return
		case b := <-s.events:
			batch = append(batch, b)
		}
		// 把此刻已经在排队的也一起带上，凑成一批
	more:
		for len(batch) < eventBatch {
			select {
			case b := <-s.events:
				batch = append(batch, b)
			default:
				break more
			}
		}
		// LPUSH 多个值时最后一个在最头部，batch 按发生顺序排，所以最新的事件在列表头部
		_ = s.tasks.Run("dispatch_event", func() error {
			_, err := s.rdb.Pipelined(ctx, func(p redis.Pipeliner) error {
				p.LPush(ctx, key, batch...)
				p.LTrim(ctx, key, 0, eventKeep-1)
				return nil
			})
			return err
		})
	}
}

// describeStreams 订阅摘要，与 PHP MarketDispatcher::describeStreams() 相同：
// 全部订阅为 all；超过 8 个只报数量；depth 选了慢档的带上间隔。
func describeStreams(streams map[string]int) string {
	if streams == nil {
		return "all"
	}
	if len(streams) > 8 {
		return strconv.Itoa(len(streams)) + " streams"
	}
	parts := make([]string, 0, len(streams))
	for stream, iv := range streams {
		if iv > wire.DepthIntervals[0] {
			stream += "@" + strconv.Itoa(iv) + "ms"
		}
		parts = append(parts, stream)
	}
	sort.Strings(parts) // map 遍历顺序是随机的，排序后每次输出一样
	return strings.Join(parts, "+")
}

// round1 保留 1 位小数。
func round1(f float64) float64 { return math.Round(f*10) / 10 }
