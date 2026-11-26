package heartbeat

import (
	"context"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"
)

// Instance 注册表里一个此刻还活着的实例。
type Instance struct {
	Host string
	Pid  int
	Ts   int64 // 最后心跳，毫秒时间戳
	Self bool  // 是不是调用者自己
}

// LiveInstances 读出 name 下此刻真的活着的实例，顺手剪掉过期和已死的 field。
// 对应 PHP CommandHeartBeatService::liveInstances()。
//
// 两道剪枝：
//   - 过期：最后心跳早于 3 × interval，进程多半已经没了；
//   - 本机已死：正常退出不会删自己的 field，会残留最多 3 × interval。
//     restart 正好落在这个窗口里，不检查 pid 就会把刚停掉的旧进程当成重复实例。
//
// 只检查同主机的 pid：别的机器上的进程号在本机查不了。
//
// 与 PHP 的区别：PHP 读失败时返回空数组，这里把错误返回给调用方，
// 由它决定读不到注册表时放行还是拒绝。
func LiveInstances(ctx context.Context, rdb redis.Cmdable, prefix, name string, interval time.Duration) ([]Instance, error) {
	if interval <= 0 {
		interval = DefaultInterval
	}
	key := prefix + KeyInstPrefix + name

	raw, err := rdb.HGetAll(ctx, key).Result()
	if err != nil {
		return nil, err
	}

	host := HostName()
	selfPid := os.Getpid()
	expireBefore := time.Now().Add(-3 * interval).UnixMilli()

	var live []Instance // nil 切片可以直接 append，不用先 make
	var dead []string
	for field, val := range raw {
		at := strings.LastIndex(field, "#")
		if at < 0 {
			dead = append(dead, field) // 不认识的格式，清掉
			continue
		}
		fHost := field[:at]
		fPid, err1 := strconv.Atoi(field[at+1:])
		ts, err2 := strconv.ParseInt(val, 10, 64)
		if err1 != nil || err2 != nil || ts < expireBefore {
			dead = append(dead, field)
			continue
		}
		if fHost == host && !processAlive(fPid) {
			dead = append(dead, field)
			continue
		}
		live = append(live, Instance{
			Host: fHost,
			Pid:  fPid,
			Ts:   ts,
			Self: fHost == host && fPid == selfPid,
		})
	}

	if len(dead) > 0 {
		// 剪枝失败不影响这次的判定结果，忽略错误
		_ = rdb.HDel(ctx, key, dead...).Err()
	}
	return live, nil
}
