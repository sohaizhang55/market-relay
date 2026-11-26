package heartbeat

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
)

// newRedis 起一个内存版 Redis（miniredis），测试结束自动关闭。
// Protocol: 2 与线上一致：本机和测试服的 Redis 4.x 不支持 RESP3。
func newRedis(t *testing.T) (*miniredis.Miniredis, *redis.Client) {
	t.Helper()
	m := miniredis.RunT(t)
	rdb := redis.NewClient(&redis.Options{Addr: m.Addr(), Protocol: 2})
	t.Cleanup(func() { _ = rdb.Close() })
	return m, rdb
}

// 心跳写出的键要和 PHP CommandHeartBeatService 完全一致，PHP 那边才读得懂。
func TestBeatWritesPhpCompatibleKeys(t *testing.T) {
	m, rdb := newRedis(t)
	ctx := context.Background()
	name := "market_relay_Test"
	m.Set(KeyStoppingPrefix+name, "123") // 模拟上次正常停机留下的标记

	b := New(rdb, "", name, 0, nil, nil)
	b.beat(ctx)

	// 注册表
	if ok, _ := m.SIsMember(RegistryKey, name); !ok {
		t.Fatalf("%s 里没有 %s", RegistryKey, name)
	}

	// 心跳 JSON：字段顺序与 PHP json_encode 一致，tasks 为空时是 {}
	raw, err := m.Get(KeyPrefix + name)
	if err != nil {
		t.Fatal(err)
	}
	wantPrefix := `{"pid":` + strconv.Itoa(os.Getpid()) + `,"tick":1,"ts":`
	if !strings.HasPrefix(raw, wantPrefix) || !strings.HasSuffix(raw, `,"tasks":{}}`) {
		t.Errorf("心跳 JSON = %s", raw)
	}
	if ttl := m.TTL(KeyPrefix + name); ttl != 15*time.Second {
		t.Errorf("心跳 TTL = %v，应为 15s（3 × 5s）", ttl)
	}

	// 实例注册表：field = host#pid，value = 毫秒时间戳
	field := HostName() + "#" + strconv.Itoa(os.Getpid())
	ms, err := strconv.ParseInt(m.HGet(KeyInstPrefix+name, field), 10, 64)
	if err != nil || time.Since(time.UnixMilli(ms)) > time.Minute {
		t.Errorf("实例注册表 field %s 的值不是最近的毫秒时间戳", field)
	}
	if ttl := m.TTL(KeyInstPrefix + name); ttl != time.Hour {
		t.Errorf("实例注册表 TTL = %v，应为 1h", ttl)
	}

	// 第一拍不删停机标记，第二拍才删
	if !m.Exists(KeyStoppingPrefix + name) {
		t.Error("第一拍就删了停机标记")
	}
	b.beat(ctx)
	if m.Exists(KeyStoppingPrefix + name) {
		t.Error("第二拍没有删停机标记")
	}
}

// 带 CACHE_PREFIX 时，每个键都要拼上前缀，和 PHP redis_client() 写出来的一样。
func TestBeatAppliesKeyPrefix(t *testing.T) {
	m, rdb := newRedis(t)
	ctx := context.Background()
	name := "market_relay_Test"

	b := New(rdb, "market_relay_", name, 0, nil, nil)
	b.beat(ctx)
	b.beat(ctx)

	for _, key := range []string{"market_relay_" + RegistryKey, "market_relay_" + KeyPrefix + name, "market_relay_" + KeyInstPrefix + name} {
		if !m.Exists(key) {
			t.Errorf("没有写 %s", key)
		}
	}
	if m.Exists(RegistryKey) || m.Exists(KeyPrefix+name) {
		t.Error("写出了不带前缀的键")
	}
	if live, err := LiveInstances(ctx, rdb, "market_relay_", name, 0); err != nil || len(live) != 1 {
		t.Errorf("带前缀读注册表得到 %v, %v", live, err)
	}
}

// ctx 取消后 Run 要写停机标记再返回。
func TestRunMarksStoppingOnCancel(t *testing.T) {
	m, rdb := newRedis(t)
	name := "market_relay_Test"

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		New(rdb, "", name, time.Hour, nil, nil).Run(ctx) // 间隔设 1 小时，保证只发第一拍
		close(done)
	}()

	// 等第一拍写进去再取消
	deadline := time.Now().Add(2 * time.Second)
	for !m.Exists(KeyPrefix+name) && time.Now().Before(deadline) {
		time.Sleep(10 * time.Millisecond)
	}
	cancel()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("ctx 取消后 Run 没有返回")
	}

	got, err := m.Get(KeyStoppingPrefix + name)
	if err != nil || got != strconv.Itoa(os.Getpid()) {
		t.Errorf("停机标记 = %q, %v，应为本进程 pid", got, err)
	}
	if ttl := m.TTL(KeyStoppingPrefix + name); ttl != 60*time.Second {
		t.Errorf("停机标记 TTL = %v，应为 60s", ttl)
	}
}

// LiveInstances 要剪掉过期、格式不对的 field，保留活着的。
func TestLiveInstancesPrunes(t *testing.T) {
	m, rdb := newRedis(t)
	name := "market_relay_Test"
	key := KeyInstPrefix + name
	now := time.Now().UnixMilli()
	self := HostName() + "#" + strconv.Itoa(os.Getpid())

	m.HSet(key, self, strconv.FormatInt(now, 10))                   // 自己：保留
	m.HSet(key, "other-host#42", strconv.FormatInt(now, 10))        // 别的机器：不验 pid，保留
	m.HSet(key, "other-host#43", strconv.FormatInt(now-60_000, 10)) // 60 秒没心跳：剪掉
	m.HSet(key, "no-sharp-sign", strconv.FormatInt(now, 10))        // 格式不对：剪掉
	m.HSet(key, "other-host#abc", strconv.FormatInt(now, 10))       // pid 不是数字：剪掉

	live, err := LiveInstances(context.Background(), rdb, "", name, 0)
	if err != nil {
		t.Fatal(err)
	}
	if len(live) != 2 {
		t.Fatalf("活实例 %d 个，应为 2：%+v", len(live), live)
	}
	for _, in := range live {
		if in.Host == HostName() && !in.Self {
			t.Errorf("本进程没有标成 Self：%+v", in)
		}
	}
	if keys, _ := m.HKeys(key); len(keys) != 2 {
		t.Errorf("剪枝后还剩 %v", keys)
	}
}

// Tasks 的 JSON 与 PHP TaskLiveness::snapshot() 一致，失败和 panic 都要记账。
func TestTasksRecordsSuccessFailureAndPanic(t *testing.T) {
	tasks := NewTasks(nil)

	_ = tasks.Run("ok", func() error { return nil })
	if err := tasks.Run("bad", func() error { return errors.New("boom") }); err == nil {
		t.Error("失败的任务没有把错误返回")
	}
	if err := tasks.Run("bad", func() error { panic("crash") }); err == nil || !strings.Contains(err.Error(), "crash") {
		t.Errorf("panic 没有转成错误：%v", err)
	}

	snap := tasks.Snapshot()
	if s := snap["ok"]; s.Runs != 1 || s.Fails != 0 || s.Last == 0 {
		t.Errorf("ok = %+v", s)
	}
	if s := snap["bad"]; s.Runs != 2 || s.Fails != 2 {
		t.Errorf("bad = %+v，应为 runs=2 fails=2", s)
	}

	// 没失败过的任务不输出 fails 字段
	js, _ := json.Marshal(snap["ok"])
	if strings.Contains(string(js), "fails") {
		t.Errorf("没失败过却输出了 fails：%s", js)
	}

	// nil 的 Tasks 也能调用 Snapshot
	var none *Tasks
	if got := none.Snapshot(); got == nil || len(got) != 0 {
		t.Errorf("nil Tasks 的 Snapshot = %v", got)
	}
}
