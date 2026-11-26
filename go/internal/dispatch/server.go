// Package dispatch 分发机：从 Redis 快照取变化的帧，按 stream 推给订阅方。对应 PHP MarketDispatcher。
//
// goroutine 关系：
//
//	              ┌── Server（共享状态由 s.mu 保护：连接、房间、最新 depth、慢档待发）
//	tick 每 100ms ─┤  SPOP 脏集合 → HMGET 快照 → 每帧压缩一次 → 放进订阅连接的待发表
//	depth 慢档 ───┤  每 500 / 1000ms 把攒下的 depth 最新值发给选了慢档的连接
//	巡检 每 1 秒 ──┤  45 秒没回 pong 就断开；每 15 秒往每条连接放一个 ping
//	统计 每 10 秒 ─┘  进程指标和每条连接的现场写进 Redis（report.go）
//
//	事件写入（eventLoop）：连上 / 握手 / 断开等事件先进队列，由它批量写进 Redis，分发路径不等 Redis
//
//	每条连接两个 goroutine：
//	  读（HTTP handler 本身）：收 hello / pong → 换房间、补快照
//	  写（writeLoop）：被唤醒后整批取走待发表依次发出；单次写超过 lag_close 秒 → 断开
//
// PHP 是单线程事件循环，所有状态天然不会被同时修改；Go 里这些 goroutine 真的会同时跑，
// 所以凡是多个 goroutine 都会碰的 map，读写前都要拿 s.mu。
package dispatch

import (
	"context"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"slices"
	"strconv"
	"sync"
	"sync/atomic"
	"time"

	"github.com/coder/websocket"
	"github.com/redis/go-redis/v9"

	"market-relay/go/internal/heartbeat"
	"market-relay/go/internal/wire"
)

const (
	pingInterval    = 15 // 秒，与 PHP MarketPublisher::HEARTBEAT_INTERVAL 相同
	pongTimeout     = 45 // 秒，与 PHP MarketPublisher::HEARTBEAT_TIMEOUT 相同
	maxHelloPerConn = 5  // 每条连接最多处理几条 hello，与 PHP MAX_HELLO_PER_CONN 相同
	readLimit       = 1 << 20
)

// Config 分发机配置，由入口层（main.go）从 .env 和命令行参数组装后传进来。
type Config struct {
	Type     int           // 1 现货 / 2 合约
	Addr     string        // 监听地址，例如 127.0.0.1:18595
	Tick     time.Duration // 取数节拍，默认 100ms
	LagClose time.Duration // 单次写阻塞多久就断开，默认 5s
	AllowIPs []string      // 非空时只放行名单内 IP
	SnapKey  string        // 快照 HASH，例如 market:dispatch:pair_snap:2（已含前缀）
	DirtyKey string        // 脏集合，并行试跑时用影子键（已含前缀）
	Name     string        // worker name，监控指标用；空 = 不往 Redis 写指标和事件（测试里用）
	Prefix   string        // Redis 键前缀，加在指标与事件键前，与 PHP 的 CACHE_PREFIX 相同
}

// Server 分发机。
type Server struct {
	cfg   Config
	rdb   *redis.Client
	tasks *heartbeat.Tasks
	log   *slog.Logger
	stat  stats

	nextID  atomic.Int64
	watchN  int         // 巡检拍数
	bootAt  time.Time   // 启动时刻
	memPeak uint64      // 内存峰值，只在 report 里读写
	events  chan []byte // 待写进 Redis 的分发事件，见 eventLoop
	auth    authStore   // 订阅方 token 与鉴权开关，见 auth.go

	mu          sync.Mutex
	conns       map[*conn]struct{}            // 全部连接（含还没发 hello 的）
	rooms       map[string]map[*conn]struct{} // stream → 点名订阅它的连接
	allRoom     map[*conn]struct{}            // 订阅全部的连接
	latestDepth map[string][]byte             // depth stream → 最新一帧（未压缩 JSON），慢档定时器用
	tierPending map[int]map[string]struct{}   // 慢档间隔(ms) → 这段时间变过的 depth stream
	fieldStream map[string]string             // 快照 field → stream 的缓存
}

// New 创建分发机。
func New(cfg Config, rdb *redis.Client, tasks *heartbeat.Tasks, log *slog.Logger) *Server {
	return &Server{
		cfg:         cfg,
		rdb:         rdb,
		tasks:       tasks,
		log:         log,
		conns:       make(map[*conn]struct{}),
		rooms:       make(map[string]map[*conn]struct{}),
		allRoom:     make(map[*conn]struct{}),
		latestDepth: make(map[string][]byte),
		tierPending: make(map[int]map[string]struct{}),
		fieldStream: make(map[string]string),
		bootAt:      time.Now(),
		events:      make(chan []byte, eventQueue),
	}
}

// Run 监听 cfg.Addr 并运行，直到 ctx 被取消。
func (s *Server) Run(ctx context.Context) error {
	ln, err := net.Listen("tcp", s.cfg.Addr)
	if err != nil {
		return err
	}
	return s.Serve(ctx, ln)
}

// Serve 在给定的 listener 上运行全部定时任务和连接处理，直到 ctx 被取消。
// 测试用它传入随机端口的 listener。
func (s *Server) Serve(ctx context.Context, ln net.Listener) error {
	srv := &http.Server{
		Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { s.serveWS(ctx, w, r) }),
	}

	var wg sync.WaitGroup
	// 小工具：起一个 goroutine 每隔 d 调一次 fn，ctx 取消时退出。
	every := func(d time.Duration, bucket string, fn func() error) {
		wg.Add(1)
		go func() {
			defer wg.Done()
			t := time.NewTicker(d)
			defer t.Stop()
			for {
				select {
				case <-ctx.Done():
					return
				case <-t.C:
					// 出错只记进 Tasks 并限流写日志（见 heartbeat.Tasks），下一拍照常执行
					_ = s.tasks.Run(bucket, fn)
				}
			}
		}()
	}

	// 先读一次鉴权快照再接连接，否则开关打开时，刚启动那几秒会把不带 token 的连接放进来
	_ = s.tasks.Run("auth_refresh", func() error { return s.loadAuth(ctx) })
	every(authRefresh, "auth_refresh", func() error { return s.loadAuth(ctx) })
	every(s.cfg.Tick, "snapshot_tick", func() error { return s.tick(ctx) })
	for _, iv := range wire.DepthIntervals {
		if time.Duration(iv)*time.Millisecond > s.cfg.Tick {
			every(time.Duration(iv)*time.Millisecond, "depth_"+strconv.Itoa(iv)+"ms", func() error { s.flushTier(iv); return nil })
		}
	}
	every(time.Second, "conn_watch", func() error { s.watch(); return nil })
	every(10*time.Second, "stat", func() error { return s.report(ctx) })
	wg.Add(1)
	go func() { defer wg.Done(); s.eventLoop(ctx) }()

	s.log.Info("分发机已启动", "addr", ln.Addr().String(), "type", s.cfg.Type,
		"tick", s.cfg.Tick, "lag_close", s.cfg.LagClose, "dirty", s.cfg.DirtyKey)

	go func() {
		<-ctx.Done()
		_ = srv.Close() // 关掉监听；已升级成 WebSocket 的连接不归它管，下面单独断开
		s.mu.Lock()
		for c := range s.conns {
			c.kill()
		}
		s.mu.Unlock()
	}()

	err := srv.Serve(ln)
	wg.Wait()
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}
	return err
}

// serveWS 一条新连接。这个函数本身就是这条连接的读 goroutine，返回时连接结束。
func (s *Server) serveWS(rootCtx context.Context, w http.ResponseWriter, r *http.Request) {
	ip, _, _ := net.SplitHostPort(r.RemoteAddr)
	if len(s.cfg.AllowIPs) > 0 && !slices.Contains(s.cfg.AllowIPs, ip) {
		s.log.Warn("拒绝连接：不在 allow_ips 内", "ip", ip)
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}

	// 鉴权开关关闭时不带或带错都放行（只是认不出是谁），打开后认不出就在握手阶段回 403
	token := r.Header.Get(authHeader)
	sub, known := s.auth.identify(token)
	if !known && s.auth.enabled() {
		reason := "token 无效或订阅方已停用"
		if token == "" {
			reason = "未带 token"
		}
		s.log.Warn("拒绝连接：鉴权失败", "ip", ip, "reason", reason)
		s.event("subscriber_auth_reject", &conn{ip: ip}, "", map[string]any{"reason": reason})
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	if known {
		s.markSeen(rootCtx, sub, ip)
	}

	ws, err := websocket.Accept(w, r, &websocket.AcceptOptions{
		// 压缩在应用层做（每帧压一次、所有连接共用），不用协议级的 permessage-deflate
		CompressionMode: websocket.CompressionDisabled,
		// 订阅方是服务器上的程序，不带浏览器的 Origin；关掉同源检查，和 PHP 一样任何来源都能连
		InsecureSkipVerify: true,
	})
	if err != nil {
		return
	}
	ws.SetReadLimit(readLimit)

	ctx, cancel := context.WithCancel(rootCtx)
	now := time.Now()
	c := &conn{
		id: s.nextID.Add(1), ws: ws, ip: ip, sub: sub.Name, connectedAt: now, box: newMailbox(), stop: cancel,
		sent: sentStat{since: now, kinds: make(map[string]int64)},
	}
	c.touch(0)

	s.mu.Lock()
	s.conns[c] = struct{}{}
	total := len(s.conns)
	s.mu.Unlock()
	s.log.Info("订阅方连上", "conn", c.id, "ip", ip, "sub", c.sub, "total", total)
	s.event("subscriber_connect", c, "", map[string]any{"total": total})

	go s.writeLoop(ctx, c)
	s.readLoop(ctx, c)

	// 读循环结束 = 连接结束，清理房间
	c.kill()
	s.mu.Lock()
	s.leaveRoomsLocked(c)
	delete(s.conns, c)
	remaining := len(s.conns)
	s.event("subscriber_close", c, c.node, map[string]any{
		"alive_sec": int64(time.Since(c.connectedAt).Seconds()), "remaining": remaining,
	})
	s.mu.Unlock()
	s.log.Info("订阅方断开", "conn", c.id, "remaining", remaining)
}

// readLoop 收订阅方发来的消息：pong 刷新存活时间，其他的按 hello 处理。
func (s *Server) readLoop(ctx context.Context, c *conn) {
	for {
		_, data, err := c.ws.Read(ctx)
		if err != nil {
			return
		}

		// 系统帧先分流，而且不占 hello 配额，否则 pong 会把配额耗光
		if wire.IsSysFrame(data) {
			if typ, ts, ok := wire.SysType(data); ok && typ == "pong" {
				c.touch(ts)
			}
			continue
		}

		c.helloN++
		if c.helloN > maxHelloPerConn {
			continue
		}
		h, err := wire.ParseHello(data, s.cfg.Type)
		if err != nil {
			// 非法 hello 不改变订阅，把原因回给对端
			c.box.putSys(entry{typ: websocket.MessageText, data: wire.ErrorFrame(time.Now().UnixMilli(), err.Error())})
			s.log.Warn("拒绝非法 hello", "conn", c.id, "ip", c.ip, "err", err)
			s.event("subscriber_hello_invalid", c, s.nodeOf(c), map[string]any{"error": err.Error(), "bytes": len(data)})
			continue
		}
		s.applyHello(c, h)
		if err := s.replay(ctx, c); err != nil {
			s.log.Warn("补快照失败", "conn", c.id, "err", err)
		}
	}
}

// applyHello 先退出旧房间再进新房间：再发一次 hello 等于整个替换订阅。
func (s *Server) applyHello(c *conn, h *wire.Hello) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.leaveRoomsLocked(c)
	c.node, c.streams = h.Node, h.Streams
	if h.Streams == nil {
		s.allRoom[c] = struct{}{}
	} else {
		for stream := range h.Streams {
			if s.rooms[stream] == nil {
				s.rooms[stream] = make(map[*conn]struct{})
			}
			s.rooms[stream][c] = struct{}{}
		}
	}
	s.log.Info("hello", "conn", c.id, "node", h.Node, "streams", describeStreams(h.Streams))
	if !c.helloOK { // 与 PHP 一样，只记第一次握手
		c.helloOK = true
		s.event("subscriber_hello", c, h.Node, map[string]any{"streams": describeStreams(h.Streams)})
	}
}

// leaveRoomsLocked 退出全部房间。调用方必须持有 s.mu（函数名带 Locked 就是这个约定）。
func (s *Server) leaveRoomsLocked(c *conn) {
	delete(s.allRoom, c)
	for stream := range c.streams {
		delete(s.rooms[stream], c)
		if len(s.rooms[stream]) == 0 {
			delete(s.rooms, stream)
		}
	}
}

// replay 给刚订阅的连接补一次快照，只补它订阅的 stream。
//
// 读 Redis 时一直拿着 s.mu，看上去会挡住 tick，但这是为了保证顺序：
// 不拿锁的话，可能出现「补快照读到旧值 → tick 先把新值发出去 → 补快照再把旧值发出去」，
// 而那个字段已经不在脏集合里，订阅方会一直停在旧值，直到它下一次变化。
// 拿着锁时，任何比补快照更旧的值，它的字段一定又被标脏了，下一个 tick 会纠正。
func (s *Server) replay(ctx context.Context, c *conn) error {
	s.mu.Lock()
	defer s.mu.Unlock()

	all := map[string]string{}
	if c.streams == nil {
		m, err := s.rdb.HGetAll(ctx, s.cfg.SnapKey).Result()
		if err != nil {
			return err
		}
		all = m
	} else {
		var fields []string
		for stream := range c.streams {
			if f, ok := wire.FieldOfStream(stream, s.cfg.Type); ok {
				fields = append(fields, f)
			}
		}
		if len(fields) > 0 {
			vals, err := s.rdb.HMGet(ctx, s.cfg.SnapKey, fields...).Result()
			if err != nil {
				return err
			}
			for i, v := range vals {
				if str, ok := v.(string); ok && str != "" {
					all[fields[i]] = str
				}
			}
		}
	}

	n := 0
	for field, js := range all {
		stream, ok := s.streamOfLocked(field)
		if !ok {
			continue
		}
		if wire.IsDepthStream(stream) {
			if _, has := s.latestDepth[stream]; !has {
				s.latestDepth[stream] = []byte(js)
			}
		}
		s.sendLocked([]*conn{c}, stream, []byte(js), true)
		n++
	}
	s.log.Info("补快照", "conn", c.id, "fields", n)
	var asked any = "all"
	if c.streams != nil {
		asked = len(c.streams)
	}
	s.event("snapshot_replay", c, c.node, map[string]any{"fields": n, "asked": asked})
	return nil
}

// tick 取走本轮变过的字段，推给订阅方。对应 PHP 的 snapshot_tick。
//
// Redis 读写放在锁外，只有分发那一段拿锁，避免网络等待挡住其他 goroutine。
func (s *Server) tick(ctx context.Context) error {
	t0 := time.Now()
	defer func() { storeMax(&s.stat.tickMaxUs, time.Since(t0).Microseconds()) }()

	n, err := s.rdb.SCard(ctx, s.cfg.DirtyKey).Result()
	if err != nil || n == 0 {
		return err
	}
	fields, err := s.rdb.SPopN(ctx, s.cfg.DirtyKey, n).Result()
	if err != nil || len(fields) == 0 {
		return err
	}
	vals, err := s.rdb.HMGet(ctx, s.cfg.SnapKey, fields...).Result()
	if err != nil {
		return err
	}

	s.mu.Lock()
	defer s.mu.Unlock()
	for i, v := range vals {
		js, ok := v.(string) // 字段不存在时 v 是 nil，类型断言失败
		if !ok || js == "" {
			continue
		}
		s.stat.popped.Add(1)
		// 没有订阅方时照样取走脏集合（攒着没有意义），但不用处理
		if len(s.conns) == 0 {
			continue
		}
		if stream, ok := s.streamOfLocked(fields[i]); ok {
			s.deliverLocked(stream, []byte(js))
		}
	}
	return nil
}

// deliverLocked 一帧推给订阅了它的连接。depth 的成员如果选了比 tick 慢的档，本轮不发，记进慢档待发。
func (s *Server) deliverLocked(stream string, js []byte) {
	isDepth := wire.IsDepthStream(stream)
	if isDepth {
		s.latestDepth[stream] = js
	}

	var now []*conn
	visit := func(c *conn) {
		if isDepth {
			if iv := depthInterval(c, stream); time.Duration(iv)*time.Millisecond > s.cfg.Tick {
				if s.tierPending[iv] == nil {
					s.tierPending[iv] = make(map[string]struct{})
				}
				s.tierPending[iv][stream] = struct{}{}
				return
			}
		}
		now = append(now, c)
	}
	for c := range s.rooms[stream] {
		visit(c)
	}
	for c := range s.allRoom {
		visit(c)
	}
	if len(now) > 0 {
		s.sendLocked(now, stream, js, false)
	}
}

// flushTier 慢档定时器：把这段时间变过的 depth 各发一次最新值，只发给当前正处在这一档的连接。
func (s *Server) flushTier(iv int) {
	s.mu.Lock()
	defer s.mu.Unlock()

	pending := s.tierPending[iv]
	if len(pending) == 0 {
		return
	}
	delete(s.tierPending, iv)

	for stream := range pending {
		js, ok := s.latestDepth[stream]
		if !ok {
			continue
		}
		var targets []*conn
		for c := range s.rooms[stream] {
			if depthInterval(c, stream) == iv {
				targets = append(targets, c)
			}
		}
		for c := range s.allRoom {
			if depthInterval(c, stream) == iv {
				targets = append(targets, c)
			}
		}
		if len(targets) > 0 {
			s.sendLocked(targets, stream, js, false)
		}
	}
}

// sendLocked 压缩一次，放进这批连接的待发表。replay = 补快照的帧，不计入「发出」统计。
func (s *Server) sendLocked(targets []*conn, stream string, js []byte, replay bool) {
	frame, err := wire.EncodeFrame(stream, js)
	if err != nil {
		s.log.Error("帧压缩失败", "stream", stream, "err", err)
		return
	}
	s.stat.deflate.Add(1)

	kind := wire.StreamKind(stream)
	overwrite := kind != "trade" && kind != "kline"
	e := entry{stream: stream, typ: websocket.MessageBinary, data: frame, replay: replay}
	for _, c := range targets {
		if !c.box.put(e, overwrite) && c.kill() {
			s.stat.lagClose.Add(1)
			// 这里持有 s.mu，c.streams / c.node 直接读
			scene := s.lagScene(c, describeStreams(c.streams), time.Now())
			scene["reason"], scene["pending"] = "pending_full", maxPending
			s.log.Warn("断开积压订阅方：待发表超限", "conn", c.id, "ip", c.ip, "limit", maxPending,
				"peer", scene["peer"], "pong_age_sec", scene["pong_age_sec"])
			s.event("subscriber_lag_close", c, c.node, scene)
		}
	}
}

// watch 每秒巡检：回收心跳超时的连接，每 15 拍发一次 ping。
// 用计数器而不是「当前秒数能被 15 整除」：定时器偶尔会跳过某一秒，那样这一轮 ping 就漏了。
func (s *Server) watch() {
	s.watchN++ // 只在巡检 goroutine 里读写，不需要加锁
	now := time.Now()
	sendPing := s.watchN%pingInterval == 0
	ping := wire.PingFrame(now.UnixMilli())

	s.mu.Lock()
	defer s.mu.Unlock()
	for c := range s.conns {
		if idle := now.Unix() - c.lastActive.Load(); idle > pongTimeout {
			if c.kill() {
				s.stat.hbKick.Add(1)
				s.log.Warn("心跳超时，回收连接", "conn", c.id, "ip", c.ip, "idle_sec", idle)
				s.event("subscriber_hb_kick", c, c.node, map[string]any{"idle": idle})
			}
			continue
		}
		if sendPing {
			c.box.putSys(entry{typ: websocket.MessageText, data: ping})
		}
	}
}

// streamOfLocked 快照 field → stream，带缓存。field 全集几乎不变，不用每帧重新解析。
func (s *Server) streamOfLocked(field string) (string, bool) {
	if st, ok := s.fieldStream[field]; ok {
		return st, st != ""
	}
	st, ok := wire.StreamOfField(field)
	if len(s.fieldStream) > 20000 {
		clear(s.fieldStream)
	}
	s.fieldStream[field] = st // 认不出的也缓存成空串，下次不用再解析
	return st, ok
}

// depthInterval 某连接某 depth stream 的推送间隔（毫秒）。原型不做降级，就是订阅时声明的间隔。
func depthInterval(c *conn, stream string) int {
	if iv, ok := c.streams[stream]; ok && iv > 0 {
		return iv
	}
	return wire.DepthIntervals[0]
}
