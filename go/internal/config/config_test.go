package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// writeFile 在临时目录写一个配置文件，返回路径。t.TempDir() 测试结束会自动删除。
func writeFile(t *testing.T, content string) string {
	t.Helper()
	p := filepath.Join(t.TempDir(), ".env")
	if err := os.WriteFile(p, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return p
}

// 模板文件本身必须能直接用：复制成 .env 不改也能启动。
func TestExampleFileLoads(t *testing.T) {
	c, err := Load("../../.env.example")
	if err != nil {
		t.Fatal(err)
	}
	if c.Redis.Prefix != "market_relay_" || c.Redis.DB != 2 || c.Dispatch.SwapPort != 18595 || c.Dispatch.Dirty != "shadow" {
		t.Errorf("模板读出来 = %+v", c)
	}
}

func TestDefaultsAndList(t *testing.T) {
	c, err := Load(writeFile(t, "# 注释\nREDIS_PREFIX=\nDISPATCH_ALLOW_IPS= 10.0.0.1 ,,10.0.0.2\nDISPATCH_TICK_MS=1\n"))
	if err != nil {
		t.Fatal(err)
	}
	if c.Redis.Prefix != "" || c.Redis.Port != 6379 || c.Dispatch.SpotPort != 18594 {
		t.Errorf("默认值不对：%+v", c)
	}
	if c.Dispatch.TickMs != 10 {
		t.Errorf("TICK_MS 应被抬到最小 10，得到 %d", c.Dispatch.TickMs)
	}
	if strings.Join(c.Dispatch.AllowIPs, ",") != "10.0.0.1,10.0.0.2" {
		t.Errorf("白名单 = %v", c.Dispatch.AllowIPs)
	}
}

func TestRejects(t *testing.T) {
	if _, err := Load(writeFile(t, "REDIS_HOST=127.0.0.1\n")); err == nil || !strings.Contains(err.Error(), "REDIS_PREFIX") {
		t.Errorf("缺 REDIS_PREFIX 应报错：%v", err)
	}
	if _, err := Load(writeFile(t, "REDIS_PREFIX=x\nDISPATCH_DIRTY=both\n")); err == nil {
		t.Error("DISPATCH_DIRTY 写错应报错")
	}
	if _, err := Load("no-such-file"); err == nil || !strings.Contains(err.Error(), ".env.example") {
		t.Errorf("文件不存在应提示复制模板：%v", err)
	}
}
