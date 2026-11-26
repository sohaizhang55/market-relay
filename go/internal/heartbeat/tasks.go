package heartbeat

import (
	"fmt"
	"log/slog"
	"math"
	"sync"
	"time"
)

// TaskStat 一个任务的存活证据，JSON 字段与 PHP TaskLiveness::snapshot() 相同。
//
// 结构体字段后面反引号里的 `json:"last"` 叫 struct tag，
// 告诉 encoding/json 序列化时用什么键名，作用类似 PHP 数组的键。
type TaskStat struct {
	Last  int64   `json:"last"`            // 最后一次执行的时刻，毫秒时间戳
	Runs  int64   `json:"runs"`            // 累计执行次数（失败的那次也算）
	Ms    float64 `json:"ms"`              // 最后一次耗时，毫秒，保留 1 位小数
	Fails int64   `json:"fails,omitempty"` // 累计失败次数；omitempty = 为 0 时不输出，与 PHP 一致
}

// Tasks 进程内各常驻任务的存活登记处。对应 PHP TaskLiveness。
//
// 为什么心跳不够：心跳只证明进程还活着，证明不了「那件事还在做」。
// 某个定时任务一直失败或者卡住，心跳照样每 5 秒写一次。
// 把各任务最后一次执行的时刻随心跳带出去，check_expire 才能发现。
//
// 与 PHP 的区别：PHP 是单进程事件循环，静态数组随便读写；
// Go 里多个 goroutine 会同时调用 Run，所以要用 sync.Mutex 加锁，
// 否则并发读写 map 会直接让进程崩溃（Go 运行时会检测到并 panic）。
type Tasks struct {
	mu      sync.Mutex
	stat    map[string]TaskStat
	lastLog map[string]time.Time
	log     *slog.Logger
}

// taskLogInterval 同一个任务的失败日志最多多久写一条。
// 任务常常 100ms 跑一轮，出问题就是每轮都出，不限流会刷爆日志。
const taskLogInterval = time.Minute

// NewTasks 创建登记处。log 为 nil 时用 slog.Default()。
func NewTasks(log *slog.Logger) *Tasks {
	if log == nil {
		log = slog.Default()
	}
	return &Tasks{
		stat:    make(map[string]TaskStat),
		lastLog: make(map[string]time.Time),
		log:     log,
	}
}

// Run 执行一次任务并记账，对应 PHP TaskLiveness::guard()。返回 fn 的错误。
//
// 用法：
//
//	tasks.Run("dispatch_tick", func() error { return d.tick(ctx) })
//
// fn 里如果 panic，会被转成错误返回，进程不会因此退出。
// 这和 Workerman「吞掉定时器回调里的异常继续调度」的行为一致，区别是这里会记下失败次数。
// 注意：只有在 Run 所在的 goroutine 里发生的 panic 才拦得住，
// fn 里自己再 go 出去的 goroutine 要自己处理。
func (t *Tasks) Run(bucket string, fn func() error) (err error) {
	start := time.Now()

	// defer 的函数在 Run 返回前执行，不管是正常 return 还是 panic，
	// 相当于 PHP 的 finally。返回值 err 是命名返回值，所以 defer 里能改它。
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("panic: %v", r)
		}
		// 失败的那次也要记存活证据：只在成功时记的话，
		// 一个每次都失败的任务看起来会像「从没跑过」，而两者的处置方向相反。
		t.record(bucket, time.Since(start), err)
	}()

	return fn()
}

// record 记一笔。调用方不持锁。
func (t *Tasks) record(bucket string, cost time.Duration, err error) {
	t.mu.Lock()
	defer t.mu.Unlock()

	s := t.stat[bucket] // map 里没有这个键时得到零值 TaskStat{}，不会报错
	s.Last = time.Now().UnixMilli()
	s.Runs++
	s.Ms = math.Round(float64(cost.Microseconds())/100) / 10 // 微秒 → 毫秒，保留 1 位
	if err != nil {
		s.Fails++
		if time.Since(t.lastLog[bucket]) >= taskLogInterval {
			t.lastLog[bucket] = time.Now()
			t.log.Error("任务执行失败", "bucket", bucket, "fails", s.Fails, "runs", s.Runs, "err", err)
		}
	}
	t.stat[bucket] = s // 结构体是值类型，改完要写回 map
}

// Snapshot 返回当前所有任务的副本，供心跳带出去。
//
// t 为 nil 时也能调用，返回空 map。Go 允许在 nil 指针上调用方法，
// 这样不登记任务的进程可以直接传 nil，Beater 不用另外判断。
func (t *Tasks) Snapshot() map[string]TaskStat {
	out := make(map[string]TaskStat)
	if t == nil {
		return out
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	for k, v := range t.stat {
		out[k] = v
	}
	return out
}
