package wire

import (
	"strings"
	"testing"
)

func TestParseHelloNormalizesStreams(t *testing.T) {
	h, err := ParseHello([]byte(`{"cmd":"hello","node":"svr b!","streams":["BTC-USDT@Depth@500ms","eth_usdt@ticker","btcusdt@kline_1m","btcusdt@markPrice"]}`), TypeSwap)
	if err != nil {
		t.Fatal(err)
	}
	if h.Node != "svrb" {
		t.Errorf("node = %q，非法字符应被去掉", h.Node)
	}
	want := map[string]int{"btcusdt@depth": 500, "ethusdt@ticker": 0, "btcusdt@kline_1m": 0, "btcusdt@markPrice": 0}
	for s, iv := range want {
		if got, ok := h.Streams[s]; !ok || got != iv {
			t.Errorf("streams[%s] = %d, %v，应为 %d", s, got, ok, iv)
		}
	}

	// 不传 streams = 订阅全部
	h, err = ParseHello([]byte(`{"cmd":"hello"}`), TypeSpot)
	if err != nil || h.Streams != nil {
		t.Errorf("不传 streams 应得到 nil：%+v, %v", h, err)
	}

	// depth 不写间隔 = 默认 100ms
	h, _ = ParseHello([]byte(`{"cmd":"hello","streams":["btcusdt@depth"]}`), TypeSpot)
	if h.Streams["btcusdt@depth"] != 100 {
		t.Errorf("depth 默认间隔 = %d", h.Streams["btcusdt@depth"])
	}
}

// 报错文字会原样回给订阅方，和 PHP 保持一致。
func TestParseHelloRejects(t *testing.T) {
	cases := []struct {
		raw  string
		typ  int
		want string
	}{
		{`["hello"]`, TypeSpot, "hello 必须是 JSON 对象"},
		{`{"cmd":"hi"}`, TypeSpot, "cmd 必须是 hello"},
		{`{"cmd":"hello"`, TypeSpot, "cmd 必须是 hello"},
		{`{"cmd":"hello","topics":[],"subs":1}`, TypeSpot, "不认识的字段: subs,topics"},
		{`{"cmd":"hello","streams":[]}`, TypeSpot, "streams 必须是非空数组"},
		{`{"cmd":"hello","streams":null}`, TypeSpot, "streams 必须是非空数组"},
		{`{"cmd":"hello","streams":{"a":"b"}}`, TypeSpot, "streams 必须是非空数组"},
		{`{"cmd":"hello","streams":[1]}`, TypeSpot, "stream 必须是字符串"},
		{`{"cmd":"hello","streams":["btcusdt"]}`, TypeSpot, "stream 格式不对"},
		{`{"cmd":"hello","streams":["123@ticker"]}`, TypeSpot, "symbol 不合法"},
		{`{"cmd":"hello","streams":["btcusdt@markPrice"]}`, TypeSpot, "现货端口没有 markPrice"},
		{`{"cmd":"hello","streams":["btcusdt@kline_2m"]}`, TypeSpot, "K 线周期不支持"},
		{`{"cmd":"hello","streams":["btcusdt@depth@200ms"]}`, TypeSpot, "depth 间隔不支持"},
		{`{"cmd":"hello","streams":["btcusdt@ticker@500ms"]}`, TypeSpot, "只有 depth 可以指定间隔"},
		{`{"cmd":"hello","streams":["btcusdt@foo"]}`, TypeSpot, "stream 名不认识"},
		{`{"cmd":"hello","streams":["btcusdt@ticker","BTCUSDT@TICKER"]}`, TypeSpot, "stream 重复: btcusdt@ticker"},
	}
	for _, c := range cases {
		_, err := ParseHello([]byte(c.raw), c.typ)
		if err == nil || !strings.Contains(err.Error(), c.want) {
			t.Errorf("%s → %v，应包含 %q", c.raw, err, c.want)
		}
	}

	big := `{"cmd":"hello","node":"` + strings.Repeat("a", MaxHelloBytes) + `"}`
	if _, err := ParseHello([]byte(big), TypeSpot); err == nil || !strings.Contains(err.Error(), "超过") {
		t.Errorf("超长 hello 应被拒绝：%v", err)
	}
}

func TestFieldStreamRoundTrip(t *testing.T) {
	cases := map[string]string{
		"market:raw:price|btcusdt_2":       "btcusdt@ticker",
		"market:raw:mark_price|btcusdt_2":  "btcusdt@markPrice",
		"market:raw:depth|btcusdt_2":       "btcusdt@depth",
		"market:raw:trade|btcusdt_2":       "btcusdt@trade",
		"market:raw:kline|btcusdt_2|60":    "btcusdt@kline_1m",
		"market:raw:kline|btcusdt_2|86400": "btcusdt@kline_1d",
	}
	for field, stream := range cases {
		got, ok := StreamOfField(field)
		if !ok || got != stream {
			t.Errorf("StreamOfField(%s) = %s, %v，应为 %s", field, got, ok, stream)
		}
		back, ok := FieldOfStream(stream, TypeSwap)
		if !ok || back != field {
			t.Errorf("FieldOfStream(%s) = %s, %v，应为 %s", stream, back, ok, field)
		}
	}
	for _, bad := range []string{"market:raw:price", "market:raw:foo|btcusdt_2", "market:raw:kline|btcusdt_2|7", "market:raw:price|_2"} {
		if s, ok := StreamOfField(bad); ok {
			t.Errorf("StreamOfField(%s) 应认不出，得到 %s", bad, s)
		}
	}
}

func TestEncodeFrameInsertsStreamFirst(t *testing.T) {
	enc, err := EncodeFrame("btcusdt@ticker", []byte(`{"pair":"btcusdt_2","close":"1.5"}`))
	if err != nil {
		t.Fatal(err)
	}
	js, err := Inflate(enc)
	if err != nil {
		t.Fatal(err)
	}
	if want := `{"stream":"btcusdt@ticker","pair":"btcusdt_2","close":"1.5"}`; string(js) != want {
		t.Errorf("解压后 = %s，应为 %s", js, want)
	}

	enc, _ = EncodeFrame("btcusdt@ticker", []byte(`{}`))
	js, _ = Inflate(enc)
	if string(js) != `{"stream":"btcusdt@ticker"}` {
		t.Errorf("空帧解压后 = %s", js)
	}
}

func TestSysFrames(t *testing.T) {
	if got := string(PingFrame(123)); got != `{"_sys":"ping","ts":123}` {
		t.Errorf("ping = %s", got)
	}
	if got := string(ErrorFrame(1, "a<b")); got != `{"_sys":"error","ts":1,"msg":"a<b"}` {
		t.Errorf("error = %s", got)
	}
	if typ, ts, ok := SysType([]byte(`{"_sys":"pong","ts":99}`)); !ok || typ != "pong" || ts != 99 {
		t.Errorf("SysType = %s %d %v", typ, ts, ok)
	}
	if _, _, ok := SysType([]byte(`{"cmd":"hello"}`)); ok {
		t.Error("hello 被当成了系统帧")
	}
}
