// market-relay 的 Go 分发机原型（纯 Go，不用框架）。在 go/ 目录下运行：
//
//	copy .env.example .env              第一次用：复制配置模板，按机器修改
//	go run . dispatch -t 2              启动合约分发机（端口、节拍等见 .env）
//	go run . watch -t 2                 连上去看收到了什么（-port 8595 连 PHP 的；开了分发鉴权要加 -token）
//
// 配置只读 go/.env，与 market-relay 的 root/.env 无关。
// 与 PHP 分发并行试跑时，market-relay 采集侧要打开 market.dispatch.shadow_dirty，见 root/config/market.sample.php。
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"net"
	"net/http"
	"os"
	"os/signal"
	"sort"
	"strconv"
	"strings"
	"syscall"
	"time"

	"github.com/coder/websocket"
	"github.com/redis/go-redis/v9"

	"market-relay/go/internal/config"
	"market-relay/go/internal/dispatch"
	"market-relay/go/internal/heartbeat"
	"market-relay/go/internal/wire"
)

// shadowSuffix Go 试跑进程的 worker name 后缀，与 PHP MarketWorkers::GO_SHADOW_SUFFIX 一致。
const shadowSuffix = "_Go"

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "用法: go run . dispatch|watch -t 1|2 [参数]，加 -h 看参数说明")
		os.Exit(2)
	}
	var err error
	switch os.Args[1] {
	case "dispatch":
		err = runDispatch(os.Args[2:])
	case "watch":
		err = runWatch(os.Args[2:])
	default:
		err = fmt.Errorf("不认识的子命令 %q，可选 dispatch / watch", os.Args[1])
	}
	if err != nil {
		fmt.Fprintln(os.Stderr, "错误:", err)
		os.Exit(1)
	}
}

// signalContext 收到 Ctrl+C 或 SIGTERM 时取消的 context。
func signalContext() (context.Context, context.CancelFunc) {
	return signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
}

func typeName(t int) string {
	if t == wire.TypeSpot {
		return "Spot"
	}
	return "Swap"
}

// portOf 某类 pair 的分发端口。
func portOf(d config.Dispatch, t int) int {
	if t == wire.TypeSpot {
		return d.SpotPort
	}
	return d.SwapPort
}

func runDispatch(args []string) error {
	fs := flag.NewFlagSet("dispatch", flag.ExitOnError)
	typ := fs.Int("t", 0, "pair 类型：1 现货 / 2 合约（必填）")
	cfgPath := fs.String("config", ".env", "配置文件路径，模板见 .env.example")
	host := fs.String("host", "", "监听地址，不传则用配置里的 DISPATCH_HOST")
	port := fs.Int("port", 0, "监听端口，不传则用配置里的 DISPATCH_SPOT_PORT / DISPATCH_SWAP_PORT")
	dirty := fs.String("dirty", "", "读哪个脏集合 shadow / main，不传则用配置里的 DISPATCH_DIRTY")
	debug := fs.Bool("v", false, "输出 debug 日志（含每拍心跳）")
	_ = fs.Parse(args)

	if *typ != wire.TypeSpot && *typ != wire.TypeSwap {
		return fmt.Errorf("必须用 -t 指定类型：-t 1 现货 / -t 2 合约")
	}

	cfg, err := config.Load(*cfgPath)
	if err != nil {
		return err
	}
	// 命令行参数优先于配置文件
	d := cfg.Dispatch
	if *host != "" {
		d.Host = *host
	}
	if *port == 0 {
		*port = portOf(d, *typ)
	}
	if *dirty != "" {
		if *dirty != "shadow" && *dirty != "main" {
			return fmt.Errorf("-dirty 只能是 shadow 或 main")
		}
		d.Dirty = *dirty
	}

	level := slog.LevelInfo
	if *debug {
		level = slog.LevelDebug
	}
	log := slog.New(slog.NewTextHandler(os.Stdout, &slog.HandlerOptions{Level: level}))

	// Protocol: 2 —— 本机和测试服的 Redis 是 4.x，不支持 RESP3
	rdb := redis.NewClient(&redis.Options{
		Addr:     net.JoinHostPort(cfg.Redis.Host, strconv.Itoa(cfg.Redis.Port)),
		Password: cfg.Redis.Password,
		DB:       cfg.Redis.DB,
		Protocol: 2,
	})
	defer rdb.Close()

	ctx, stop := signalContext()
	defer stop()
	if err := rdb.Ping(ctx).Err(); err != nil {
		return fmt.Errorf("连不上 Redis: %w", err)
	}

	// PHP 的 redis_client() 会给每个键自动加上 CACHE_PREFIX，go-redis 不会，所以这里自己拼。
	// 漏了前缀，读到的会是别的键（可能是加前缀之前留下的旧数据）。
	prefix := cfg.Redis.Prefix

	dirtyKey := prefix + "market:dispatch:pair_dirty_shadow:" + strconv.Itoa(*typ)
	if d.Dirty == "main" {
		dirtyKey = prefix + "market:dispatch:pair_dirty:" + strconv.Itoa(*typ)
	}

	tasks := heartbeat.NewTasks(log)
	name := "market_relay_MarketDispatchServer_" + typeName(*typ) + shadowSuffix
	go heartbeat.New(rdb, prefix, name, 0, tasks, log).Run(ctx)

	srv := dispatch.New(dispatch.Config{
		Type:     *typ,
		Addr:     net.JoinHostPort(d.Host, strconv.Itoa(*port)),
		Tick:     time.Duration(d.TickMs) * time.Millisecond,
		LagClose: time.Duration(d.LagCloseSec) * time.Second,
		AllowIPs: d.AllowIPs,
		SnapKey:  prefix + "market:dispatch:pair_snap:" + strconv.Itoa(*typ),
		DirtyKey: dirtyKey,
		Name:     name, // 指标与事件，后台「Go 分发监控」页读
		Prefix:   prefix,
	}, rdb, tasks, log)
	log.Info("worker", "name", name)
	return srv.Run(ctx)
}

// runWatch 一个观察用的订阅方：发 hello、回 pong、解压帧，每秒打印一行统计。
func runWatch(args []string) error {
	fs := flag.NewFlagSet("watch", flag.ExitOnError)
	typ := fs.Int("t", 0, "pair 类型：1 现货 / 2 合约（必填）")
	cfgPath := fs.String("config", ".env", "配置文件路径，用来取默认端口")
	host := fs.String("host", "127.0.0.1", "分发机地址")
	port := fs.Int("port", 0, "分发机端口，不传则用配置里的 Go 分发端口；PHP 的是 8594 / 8595")
	streams := fs.String("streams", "", "逗号分隔的 stream，例如 btcusdt@ticker,btcusdt@depth@500ms；留空 = 全部")
	show := fs.Int("show", 3, "打印前几帧的内容")
	dur := fs.Duration("dur", 0, "运行多久后自动退出，例如 30s；0 = 一直运行")
	token := fs.String("token", "", "握手头 X-Inner-Token；后台开了分发鉴权时必填，token 在「订阅方管理」里发")
	_ = fs.Parse(args)

	if *typ != wire.TypeSpot && *typ != wire.TypeSwap {
		return fmt.Errorf("必须用 -t 指定类型：-t 1 现货 / -t 2 合约")
	}
	if *port == 0 {
		cfg, err := config.Load(*cfgPath)
		if err != nil {
			return fmt.Errorf("没有用 -port 指定端口，%w", err)
		}
		*port = portOf(cfg.Dispatch, *typ)
	}

	ctx, stop := signalContext()
	defer stop()
	if *dur > 0 {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, *dur)
		defer cancel()
	}

	url := "ws://" + net.JoinHostPort(*host, strconv.Itoa(*port)) + "/"
	opts := &websocket.DialOptions{HTTPHeader: http.Header{}}
	if *token != "" {
		opts.HTTPHeader.Set("X-Inner-Token", *token)
	}
	ws, _, err := websocket.Dial(ctx, url, opts)
	if err != nil {
		return fmt.Errorf("连接 %s 失败: %w", url, err)
	}
	defer ws.CloseNow()
	ws.SetReadLimit(1 << 24)

	hello := `{"cmd":"hello","node":"go-watch"}`
	if *streams != "" {
		list := strings.Split(*streams, ",")
		hello = `{"cmd":"hello","node":"go-watch","streams":["` + strings.Join(list, `","`) + `"]}`
	}
	if err := ws.Write(ctx, websocket.MessageText, []byte(hello)); err != nil {
		return err
	}
	fmt.Println("已连接", url, "hello:", hello)

	var frames, bytes, shown int
	byKind := map[string]int{}
	tick := time.NewTicker(time.Second)
	defer tick.Stop()

	// 读放在单独的 goroutine 里，主 goroutine 负责每秒打印；两者通过 channel 交接
	type msg struct {
		typ  websocket.MessageType
		data []byte
	}
	msgs := make(chan msg, 1024)
	readErr := make(chan error, 1)
	go func() {
		for {
			t, data, err := ws.Read(ctx)
			if err != nil {
				readErr <- err
				return
			}
			msgs <- msg{t, data}
		}
	}()

	for {
		select {
		case <-ctx.Done():
			fmt.Println("结束")
			return nil
		case err := <-readErr:
			if ctx.Err() != nil {
				return nil
			}
			return fmt.Errorf("连接断开: %w", err)
		case m := <-msgs:
			if wire.IsSysFrame(m.data) {
				fmt.Println("系统帧:", string(m.data))
				if typ, ts, ok := wire.SysType(m.data); ok && typ == "ping" {
					_ = ws.Write(ctx, websocket.MessageText, wire.PongFrame(ts))
				}
				continue
			}
			js, err := wire.Inflate(m.data)
			if err != nil {
				fmt.Println("坏包（解压失败）:", err)
				continue
			}
			frames++
			bytes += len(m.data)
			stream := streamOf(js)
			byKind[wire.StreamKind(stream)]++
			if shown < *show {
				shown++
				fmt.Printf("帧 #%d（压缩 %d 字节 → %d 字节）: %s\n", shown, len(m.data), len(js), truncate(js, 300))
			}
		case <-tick.C:
			fmt.Printf("%s  %d 帧/秒  %d 字节/秒  %s\n", time.Now().Format("15:04:05"), frames, bytes, kinds(byKind))
			frames, bytes = 0, 0
			clear(byKind)
		}
	}
}

// streamOf 取帧里的 stream 字段。帧以 {"stream":"xxx" 开头，直接切字符串，不解整个 JSON。
func streamOf(js []byte) string {
	const head = `{"stream":"`
	s := string(js)
	if !strings.HasPrefix(s, head) {
		return "?"
	}
	s = s[len(head):]
	if i := strings.IndexByte(s, '"'); i >= 0 {
		return s[:i]
	}
	return "?"
}

func kinds(m map[string]int) string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	var parts []string
	for _, k := range keys {
		parts = append(parts, k+"="+strconv.Itoa(m[k]))
	}
	return strings.Join(parts, " ")
}

func truncate(b []byte, n int) string {
	if len(b) <= n {
		return string(b)
	}
	return string(b[:n]) + "…"
}
