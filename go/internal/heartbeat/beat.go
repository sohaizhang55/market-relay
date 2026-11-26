package heartbeat

import (
	"context"
	"encoding/json"
	"log/slog"
	"os"
	"strconv"
	"time"

	"github.com/redis/go-redis/v9"
)

// beatPayload heartbeat:cmd:{name} 的值。
// 用结构体而不是 map 编码：结构体按字段声明顺序输出，与 PHP 的 json_encode 顺序一致。
type beatPayload struct {
	Pid   int                 `json:"pid"`
	Tick  int                 `json:"tick"`
	Ts    int64               `json:"ts"` // 秒级时间戳，PHP 是 time()
	Tasks map[string]TaskStat `json:"tasks"`
}

// Beater 定时上报心跳，对应 PHP CommandHeartBeatService::start()。
//
// 一个进程一个 Beater，在一个单独的 goroutine 里跑 Run：
//
//	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
//	defer stop()
//	go heartbeat.New(rdb, "market_relay_", "market_relay_MarketDispatchServer_Swap", 0, tasks, log).Run(ctx)
//
// PHP 里心跳挂在 Workerman 的 Timer 上，和业务共用一个事件循环，
// 所以事件循环被卡住时心跳也会停，能借此发现「进程在、但卡死了」。
// Go 的心跳 goroutine 和业务 goroutine 互相独立，业务卡住不会拖住心跳，
// 因此「业务还在不在做」要靠 Tasks 记录的存活证据来判断，而不是心跳本身。
type Beater struct {
	rdb      redis.Cmdable // go-redis 的命令接口；*redis.Client 和测试用的客户端都满足它
	prefix   string        // Redis 键前缀，见 keys.go 的说明
	name     string        // worker 名，例如 market_relay_MarketDispatchServer_Swap
	interval time.Duration
	tasks    *Tasks // 可以为 nil
	log      *slog.Logger

	pid  int
	host string
	tick int // 只在 Run 所在的 goroutine 里读写，不需要加锁
}

// New 创建 Beater。prefix 是 Redis 键前缀（PHP 的 CACHE_PREFIX）；interval 传 0 用默认的 5 秒；tasks、log 可以为 nil。
func New(rdb redis.Cmdable, prefix, name string, interval time.Duration, tasks *Tasks, log *slog.Logger) *Beater {
	if interval <= 0 {
		interval = DefaultInterval
	}
	if log == nil {
		log = slog.Default()
	}
	return &Beater{
		rdb:      rdb,
		prefix:   prefix,
		name:     name,
		interval: interval,
		tasks:    tasks,
		log:      log,
		pid:      os.Getpid(),
		host:     HostName(),
	}
}

// Run 立即发第一拍，之后每隔 interval 发一拍，直到 ctx 被取消。
// 取消时写下停机标记再返回，对应 PHP 挂在 onWorkerStop 上的那段逻辑。
//
// Run 会一直阻塞，所以调用方要用 go 关键字放到单独的 goroutine 里。
func (b *Beater) Run(ctx context.Context) {
	b.beat(ctx)

	// Ticker 每隔 interval 往 ticker.C 这个 channel 里送一个值，
	// 相当于 Workerman 的 Timer::add($interval, $beat)。
	ticker := time.NewTicker(b.interval)
	defer ticker.Stop() // 函数返回时停掉，否则 Ticker 会一直占着资源

	// for + select 是 Go 常驻循环的标准写法：
	// select 同时等待多个 channel，哪个先有消息就执行哪个分支。
	for {
		select {
		case <-ctx.Done(): // ctx 被取消（收到 SIGINT/SIGTERM）时，这个 channel 会被关闭
			b.markStopping()
			return
		case <-ticker.C:
			b.beat(ctx)
		}
	}
}

// beat 发一拍：注册名字、写心跳、登记实例，第二拍时删掉停机标记。
//
// 五条命令放在一个 pipeline 里一次发出去，只占一次网络往返；
// PHP 是逐条调用的，效果相同。
// Redis 出错只记日志不中断：心跳失败不能影响业务，下一拍会自动重试，
// go-redis 内部有连接池，断线后会自己重连（PHP 那边要手动 redis_reconnect）。
func (b *Beater) beat(ctx context.Context) {
	b.tick++

	js, err := json.Marshal(beatPayload{
		Pid:   b.pid,
		Tick:  b.tick,
		Ts:    time.Now().Unix(),
		Tasks: b.tasks.Snapshot(),
	})
	if err != nil {
		b.log.Error("心跳编码失败", "name", b.name, "err", err)
		return
	}

	instKey := b.prefix + KeyInstPrefix + b.name
	_, err = b.rdb.Pipelined(ctx, func(p redis.Pipeliner) error {
		p.SAdd(ctx, b.prefix+RegistryKey, b.name)
		p.Set(ctx, b.prefix+KeyPrefix+b.name, js, 3*b.interval) // 带过期时间的 SET，等同 PHP setex
		p.HSet(ctx, instKey, b.instanceField(), time.Now().UnixMilli())
		p.Expire(ctx, instKey, instTTL(b.interval))

		// 第二拍才宣告「我起来了」：第一拍是启动时同步发的，那时后面的初始化可能还没做完；
		// 能发出第二拍，说明进程已经稳定地跑起来了。
		if b.tick == 2 {
			p.Del(ctx, b.prefix+KeyStoppingPrefix+b.name)
		}
		return nil
	})
	if err != nil {
		b.log.Warn("心跳写入失败", "name", b.name, "tick", b.tick, "err", err)
		return
	}
	b.log.Debug("心跳", "name", b.name, "pid", b.pid, "tick", b.tick)
}

// markStopping 写停机标记。
//
// 这时 Run 收到的 ctx 已经取消了，拿它发命令会立刻失败，
// 所以另起一个 2 秒超时的 context。context.Background() 是「永不取消」的根 context。
func (b *Beater) markStopping() {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()

	err := b.rdb.Set(ctx, b.prefix+KeyStoppingPrefix+b.name, strconv.Itoa(b.pid), stoppingTTL).Err()
	if err != nil {
		// 写不进去的后果只是 check_expire 误报一次，不值得让退出流程出错
		b.log.Warn("停机标记写入失败", "name", b.name, "err", err)
	}
}

// instanceField 本实例在注册表里的 field：{hostname}#{pid}。
func (b *Beater) instanceField() string {
	return b.host + "#" + strconv.Itoa(b.pid)
}
