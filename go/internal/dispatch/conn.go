package dispatch

import (
	"context"
	"sync"
	"sync/atomic"
	"time"

	"github.com/coder/websocket"

	"market-relay/go/internal/wire"
)

// maxPending 一条连接待发表里最多积压多少条。超过就断开，订阅方重连后补快照。
// 覆盖类 stream（depth / ticker / markPrice）每个 stream 最多占一条，
// 能涨到这个数的只有 trade / kline 这类排队的帧，说明订阅方已经长时间收不动了。
const maxPending = 4096

// lagShowAfter 一次写阻塞超过这么久，监控页就把连接显示为「积压中」（还没到 lag_close 断开）。
const lagShowAfter = time.Second

// entry 待发表里的一条。
type entry struct {
	stream string // 系统帧为空
	typ    websocket.MessageType
	data   []byte // 已压缩好的帧，多条连接共用同一份字节，只读
	replay bool   // 补快照的帧：照常发送，但不计入「发出」统计，与 PHP 口径一致
}

// mailbox 每条连接的待发表。
//
// PHP 版把帧直接塞进 Workerman 的发送缓冲，缓冲是先进先出的：订阅方慢的时候，
// 缓冲里堆满同一个 depth 的几十个旧版本，新数据只能排在后面，于是需要「降级」来少发。
// 这里换了做法：覆盖类 stream 在待发表里只占一个位置，新帧直接替换还没发出去的旧帧，
// 订阅方再慢，积压也不会超过它订阅的 stream 数，收到的永远是最新值。
// trade 和 kline 不能覆盖（trade 是成交窗口、kline 收盘那根不能被下一根顶掉），所以排队。
type mailbox struct {
	mu      sync.Mutex
	sys     []entry        // 系统帧（ping / error），每次都比行情帧先发
	pending []entry        // 行情帧，按到达顺序
	slot    map[string]int // 覆盖类 stream → 它在 pending 里的下标
	notify  chan struct{}  // 容量 1：有新内容时往里塞一个信号，塞不进说明已经有信号在等，不用重复

	// 监控用，sample() 取走时重置
	merged int64 // 还没发出去就被新帧替换掉的旧帧数。订阅方越慢这个数越大，相当于 PHP 的降级少发
	peak   int   // 待发表最长的时候有几条
}

func newMailbox() *mailbox {
	return &mailbox{slot: make(map[string]int), notify: make(chan struct{}, 1)}
}

// put 放入一条行情帧。overwrite 为 true 时，同一个 stream 还没发出去的旧帧会被替换。
// 返回 false 表示积压超限，调用方应该断开这条连接。
func (m *mailbox) put(e entry, overwrite bool) bool {
	m.mu.Lock()
	if overwrite {
		if i, ok := m.slot[e.stream]; ok {
			m.pending[i] = e // 替换旧帧，位置不变
			m.merged++
			m.mu.Unlock()
			return true
		}
		m.slot[e.stream] = len(m.pending)
	}
	m.pending = append(m.pending, e)
	n := len(m.pending)
	m.peak = max(m.peak, n)
	m.mu.Unlock()

	m.wake()
	return n <= maxPending
}

// putSys 放入一条系统帧。
func (m *mailbox) putSys(e entry) {
	m.mu.Lock()
	m.sys = append(m.sys, e)
	m.mu.Unlock()
	m.wake()
}

// wake 唤醒写 goroutine。select 带 default 是「非阻塞发送」：channel 满了就直接跳过。
func (m *mailbox) wake() {
	select {
	case m.notify <- struct{}{}:
	default:
	}
}

// take 把待发表整批取走（系统帧在前），并清空。
func (m *mailbox) take() []entry {
	m.mu.Lock()
	defer m.mu.Unlock()
	out := append(m.sys, m.pending...)
	m.sys, m.pending = nil, nil
	clear(m.slot)
	return out
}

// sample 监控取数：当前待发几条、窗口内最多几条、合并了几条，然后开始新窗口。
func (m *mailbox) sample() (pending, peak int, merged int64) {
	m.mu.Lock()
	defer m.mu.Unlock()
	pending = len(m.pending)
	peak, merged = max(m.peak, pending), m.merged
	m.peak, m.merged = pending, 0
	return
}

// sentStat 一条连接的发出统计与往返时延。写 goroutine 和读 goroutine 写入、stat 定时器读取，所以带锁。
type sentStat struct {
	mu     sync.Mutex
	since  time.Time        // 本统计窗口的起点
	msgs   int64            // 窗口内发出的行情帧数
	bytes  int64            // 窗口内发出的字节数（压缩后）
	kinds  map[string]int64 // stream 类别 → 字节数
	rttN   int64            // 收到过几次带时间戳的 pong（以下三项从连上起累计，不随窗口清零）
	rttSum float64
	rttMax float64
}

// conn 一个订阅方连接。
type conn struct {
	id          int64
	ws          *websocket.Conn
	ip          string
	sub         string // 握手头 token 认出的订阅方名称，认不出为空；连上后不再变，不用加锁
	connectedAt time.Time
	box         *mailbox
	stop        context.CancelFunc // 取消这条连接的 context，读写两个 goroutine 都会退出
	sent        sentStat

	lastActive   atomic.Int64 // 最后一次收到 pong 的 Unix 秒。读 goroutine 写、巡检 goroutine 读，所以用原子类型
	writingSince atomic.Int64 // 正在进行的那次写从什么时候开始（Unix 毫秒），0 = 没在写
	closing      atomic.Bool  // 已经决定断开，避免重复断开和重复记日志
	helloN       int          // 收到过几条非系统帧，只在读 goroutine 里用

	// 以下字段由 Server.mu 保护
	node    string
	streams map[string]int // nil = 订阅全部（还没发 hello 时也是 nil，但那时不在任何房间里）
	helloOK bool           // 收到过合法 hello
}

// kill 立即断开，不等待发送缓冲排空（对应 PHP 的 destroy()）。
// 积压时缓冲里正是发不出去的旧帧，等它排空没有意义。可以重复调用。
func (c *conn) kill() bool {
	if !c.closing.CompareAndSwap(false, true) {
		return false
	}
	c.stop()
	_ = c.ws.CloseNow()
	return true
}

// writeLoop 写 goroutine：等待唤醒，整批取走待发表依次发出。
//
// 每次写都带 lagClose 超时：订阅方收得慢时 TCP 发送窗口会满、写会阻塞，
// 阻塞超过 lagClose（默认 5 秒）就判定为积压并断开，对应 PHP 的 lag_close_sec。
func (s *Server) writeLoop(ctx context.Context, c *conn) {
	for {
		select {
		case <-ctx.Done():
			return
		case <-c.box.notify:
		}

		// 先在本地累加，整批写完再一次性记进统计，免得每帧都去抢锁
		var msgs, bytes int64
		kinds := map[string]int64{}
		for _, e := range c.box.take() {
			c.writingSince.Store(time.Now().UnixMilli())
			wctx, cancel := context.WithTimeout(ctx, s.cfg.LagClose)
			err := c.ws.Write(wctx, e.typ, e.data)
			timedOut := wctx.Err() == context.DeadlineExceeded
			cancel()
			c.writingSince.Store(0)
			if err != nil {
				s.addSent(c, msgs, bytes, kinds)
				// 超时 = 订阅方收不动，记为积压断开；其他错误（对端已断开等）静默收尾
				if c.kill() && timedOut {
					s.stat.lagClose.Add(1)
					scene := s.lagScene(c, s.streamsOf(c), time.Now())
					scene["reason"], scene["held"] = "write_timeout", int(s.cfg.LagClose.Seconds())
					s.log.Warn("断开积压订阅方", "conn", c.id, "ip", c.ip, "held", s.cfg.LagClose,
						"peer", scene["peer"], "pong_age_sec", scene["pong_age_sec"])
					s.event("subscriber_lag_close", c, s.nodeOf(c), scene)
				}
				return
			}
			if e.stream != "" && !e.replay {
				n := int64(len(e.data))
				msgs++
				bytes += n
				kinds[wire.StreamKind(e.stream)] += n
			}
		}
		s.addSent(c, msgs, bytes, kinds)
	}
}

// addSent 把一批发送结果记进这条连接和整个进程的统计。
func (s *Server) addSent(c *conn, msgs, bytes int64, kinds map[string]int64) {
	if msgs == 0 {
		return
	}
	c.sent.mu.Lock()
	c.sent.msgs += msgs
	c.sent.bytes += bytes
	for k, n := range kinds {
		c.sent.kinds[k] += n
	}
	c.sent.mu.Unlock()

	s.stat.msgs.Add(msgs)
	s.stat.bytes.Add(bytes)
	s.stat.addKinds(kinds)
}

// touch 记录一次 pong。tsMs 是 pong 里带回来的 ping 发出时刻，用来算往返时延；0 表示没带。
func (c *conn) touch(tsMs int64) {
	now := time.Now()
	c.lastActive.Store(now.Unix())
	if tsMs <= 0 {
		return
	}
	rtt := float64(now.UnixMilli() - tsMs)
	if rtt < 0 {
		return
	}
	c.sent.mu.Lock()
	c.sent.rttN++
	c.sent.rttSum += rtt
	c.sent.rttMax = max(c.sent.rttMax, rtt)
	c.sent.mu.Unlock()
}
