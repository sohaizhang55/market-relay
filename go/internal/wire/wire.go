// Package wire 分发机与订阅方之间的线上协议，对应 PHP MarketWireProtocol 与 MarketPublisher 的系统帧部分。
//
// 一条 WebSocket 二进制消息 = 一帧 JSON 经 raw deflate（PHP gzdeflate）压缩；
// 系统帧（ping / pong / error / throttle）是不压缩的文本帧，以 {"_sys": 开头。
// 规则、上限、报错文字都照搬 PHP，订阅方不用改一行。
package wire

import (
	"bytes"
	"compress/flate"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"slices"
	"sort"
	"strconv"
	"strings"
)

// pair 类型，与 PHP MarketDataTypeEnum 相同。
const (
	TypeSpot = 1
	TypeSwap = 2
)

const (
	MaxHelloBytes = 65536 // hello 最大字节数
	MaxStreams    = 4096  // 一条 hello 最多订阅几个 stream
	maxNodeLen    = 40    // 节点标识最大长度

	// SysPrefix 系统帧前缀。只看前缀就能和行情帧分开，不用解 JSON。
	SysPrefix = `{"_sys":`
)

// DepthIntervals depth 可选的推送间隔（毫秒），第一个是默认档。
var DepthIntervals = []int{100, 500, 1000}

// 快照频道 → stream 名（K 线单独处理）。
var channelStreams = map[string]string{
	"market:raw:price":      "ticker",
	"market:raw:mark_price": "markPrice",
	"market:raw:depth":      "depth",
	"market:raw:trade":      "trade",
}

const klineChannel = "market:raw:kline"

// K 线周期（秒）→ stream 名里的写法。
var klineIntervals = map[int]string{
	60: "1m", 300: "5m", 900: "15m", 3600: "1h", 14400: "4h", 86400: "1d",
}

// klineIntervalNames 用于报错文字，顺序与 PHP 一致。
const klineIntervalNames = "1m/5m/15m/1h/4h/1d"

// Hello 解析后的订阅。Streams 为 nil 表示订阅本端口全部 stream；
// 否则 stream → 请求的推送间隔（毫秒），只有 depth 有值，其余为 0。
type Hello struct {
	Node    string
	Streams map[string]int
}

// ParseHello 解析订阅方发来的 hello，对应 PHP MarketWireProtocol::parseHello()。
// 返回的 error 文字会原样回给订阅方，所以和 PHP 保持一致。
func ParseHello(raw []byte, typ int) (*Hello, error) {
	if len(raw) == 0 || raw[0] != '{' {
		return nil, errors.New("hello 必须是 JSON 对象")
	}
	if len(raw) > MaxHelloBytes {
		return nil, fmt.Errorf("hello 超过 %d 字节", MaxHelloBytes)
	}

	// 先解成 键 → 原始 JSON，字段的具体类型逐个再判断，这样能区分「没传」和「传了 null」
	var msg map[string]json.RawMessage
	if err := json.Unmarshal(raw, &msg); err != nil {
		return nil, errors.New("cmd 必须是 hello")
	}
	var cmd string
	if json.Unmarshal(msg["cmd"], &cmd) != nil || cmd != "hello" {
		return nil, errors.New("cmd 必须是 hello")
	}

	var unknown []string
	for k := range msg {
		if k != "cmd" && k != "node" && k != "streams" {
			unknown = append(unknown, k)
		}
	}
	if len(unknown) > 0 {
		sort.Strings(unknown) // map 遍历顺序是随机的，排序后报错文字才稳定
		return nil, fmt.Errorf("不认识的字段: %s（只接受 cmd / node / streams）", strings.Join(unknown, ","))
	}

	h := &Hello{}
	var node string
	if json.Unmarshal(msg["node"], &node) == nil {
		h.Node = sanitizeNode(node)
	}

	if rawStreams, ok := msg["streams"]; ok {
		var list []json.RawMessage
		if json.Unmarshal(rawStreams, &list) != nil || len(list) == 0 {
			return nil, errors.New("streams 必须是非空数组；要订阅全部就不传 streams")
		}
		if len(list) > MaxStreams {
			return nil, fmt.Errorf("streams 超过 %d 个", MaxStreams)
		}
		h.Streams = make(map[string]int, len(list))
		for _, item := range list {
			var s string
			if json.Unmarshal(item, &s) != nil {
				return nil, errors.New("stream 必须是字符串")
			}
			stream, iv, err := ParseStream(s, typ)
			if err != nil {
				return nil, err
			}
			if _, dup := h.Streams[stream]; dup {
				return nil, fmt.Errorf("stream 重复: %s", stream)
			}
			h.Streams[stream] = iv
		}
	}
	return h, nil
}

// ParseStream 解析一个 stream：{symbol}@{名字}[@{间隔}]，大小写不敏感，返回规范写法和请求的间隔。
// 对应 PHP MarketWireProtocol::parseStream()。
//
//	btcusdt@ticker  btcusdt@markPrice  btcusdt@depth  btcusdt@depth@500ms  btcusdt@trade  btcusdt@kline_1m
func ParseStream(raw string, typ int) (string, int, error) {
	parts := strings.Split(strings.ToLower(strings.TrimSpace(raw)), "@")
	if len(parts) < 2 || len(parts) > 3 {
		return "", 0, fmt.Errorf("stream 格式不对: %s（应为 symbol@名字）", raw)
	}

	symbol := strings.NewReplacer("-", "", "_", "", "/", "").Replace(parts[0])
	if !validSymbol(symbol) {
		return "", 0, fmt.Errorf("symbol 不合法: %s", raw)
	}

	name := parts[1]
	switch {
	case name == "markprice":
		if typ == TypeSpot {
			return "", 0, fmt.Errorf("现货端口没有 markPrice: %s", raw)
		}
		name = "markPrice"
	case strings.HasPrefix(name, "kline_"):
		if !slices.Contains(klineIntervalList(), name[len("kline_"):]) {
			return "", 0, fmt.Errorf("K 线周期不支持: %s（可选 %s）", raw, klineIntervalNames)
		}
	case name != "ticker" && name != "depth" && name != "trade":
		return "", 0, fmt.Errorf("stream 名不认识: %s", raw)
	}

	iv := 0
	if name == "depth" {
		iv = DepthIntervals[0]
		if len(parts) == 3 {
			iv = 0
			if s, ok := strings.CutSuffix(parts[2], "ms"); ok {
				iv, _ = strconv.Atoi(s) // 解析失败得到 0，下面的检查会拒绝它
			}
			if !slices.Contains(DepthIntervals, iv) {
				return "", 0, fmt.Errorf("depth 间隔不支持: %s（可选 100ms/500ms/1000ms）", raw)
			}
		}
	} else if len(parts) == 3 {
		return "", 0, fmt.Errorf("只有 depth 可以指定间隔: %s", raw)
	}
	return symbol + "@" + name, iv, nil
}

// validSymbol 只含字母数字、且不是纯数字（纯数字必然是写错了，会变成一条永远收不到数据的订阅）。
func validSymbol(s string) bool {
	if s == "" {
		return false
	}
	allDigit := true
	for _, r := range s {
		isDigit := r >= '0' && r <= '9'
		if !isDigit && !(r >= 'a' && r <= 'z') {
			return false // 前面已经转成小写，所以只需要判断小写字母
		}
		allDigit = allDigit && isDigit
	}
	return !allDigit
}

func klineIntervalList() []string {
	out := make([]string, 0, len(klineIntervals))
	for _, v := range klineIntervals {
		out = append(out, v)
	}
	return out
}

var nodeChars = regexp.MustCompile(`[^A-Za-z0-9._-]`)

func sanitizeNode(v string) string {
	v = nodeChars.ReplaceAllString(v, "")
	if len(v) > maxNodeLen {
		v = v[:maxNodeLen]
	}
	return v
}

// StreamOfField 快照 field → stream 名，认不出返回 false。
// field 格式：{channel}|{pair}，K 线再加 |{周期秒}；pair = {symbol}_{type}。
func StreamOfField(field string) (string, bool) {
	parts := strings.Split(field, "|")
	if len(parts) < 2 {
		return "", false
	}
	channel, pair := parts[0], parts[1]

	pos := strings.LastIndex(pair, "_")
	if pos <= 0 {
		return "", false
	}
	symbol := pair[:pos]

	if channel == klineChannel {
		if len(parts) < 3 {
			return "", false
		}
		sec, _ := strconv.Atoi(parts[2])
		iv, ok := klineIntervals[sec]
		if !ok {
			return "", false
		}
		return symbol + "@kline_" + iv, true
	}
	name, ok := channelStreams[channel]
	if !ok {
		return "", false
	}
	return symbol + "@" + name, true
}

// FieldOfStream stream → 某类 pair 快照里的 field。stream 必须是 ParseStream 归一化过的。
func FieldOfStream(stream string, typ int) (string, bool) {
	symbol, name, _ := strings.Cut(stream, "@")
	pair := strings.ToLower(symbol) + "_" + strconv.Itoa(typ)

	if iv, ok := strings.CutPrefix(name, "kline_"); ok {
		for sec, n := range klineIntervals {
			if n == iv {
				return klineChannel + "|" + pair + "|" + strconv.Itoa(sec), true
			}
		}
		return "", false
	}
	for ch, n := range channelStreams {
		if n == name {
			return ch + "|" + pair, true
		}
	}
	return "", false
}

// StreamKind stream 的类别：ticker / markPrice / depth / trade / kline。
func StreamKind(stream string) string {
	_, name, _ := strings.Cut(stream, "@")
	if strings.HasPrefix(name, "kline_") {
		return "kline"
	}
	return name
}

// IsDepthStream 是否 depth stream。只有 depth 有推送间隔。
func IsDepthStream(stream string) bool {
	return strings.HasSuffix(stream, "@depth")
}

// EncodeFrame 在快照帧最前面插入 stream 字段，再用 raw deflate level 1 压缩。
// 对应 PHP MarketWireProtocol::encodeFrame()。快照帧本身已是合法 JSON 对象，直接拼字符串。
func EncodeFrame(stream string, frameJSON []byte) ([]byte, error) {
	var js []byte
	js = append(js, `{"stream":"`...)
	js = append(js, stream...)
	js = append(js, '"')
	if len(frameJSON) > 2 {
		js = append(js, ',')
		js = append(js, frameJSON[1:]...)
	} else {
		js = append(js, '}')
	}
	return Deflate(js)
}

// Deflate raw deflate（不带 zlib/gzip 头），level 1，等同 PHP gzdeflate($json, 1)。
// 压出来的字节不一定和 PHP 逐字节相同，但解压结果一样，订阅方用 gzinflate 照样能解。
func Deflate(b []byte) ([]byte, error) {
	var buf bytes.Buffer
	w, err := flate.NewWriter(&buf, 1)
	if err != nil {
		return nil, err
	}
	if _, err := w.Write(b); err != nil {
		return nil, err
	}
	if err := w.Close(); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// Inflate 解压一条线上消息。观测工具（watch 命令）用。
func Inflate(b []byte) ([]byte, error) {
	var out bytes.Buffer
	_, err := out.ReadFrom(flate.NewReader(bytes.NewReader(b)))
	return out.Bytes(), err
}

// IsSysFrame 是否系统帧。
func IsSysFrame(b []byte) bool {
	return bytes.HasPrefix(b, []byte(SysPrefix))
}

// sysFrame 系统帧结构。字段顺序就是输出顺序：_sys 必须在第一个，IsSysFrame 靠前缀判断。
type sysFrame struct {
	Sys string `json:"_sys"`
	Ts  int64  `json:"ts"`
	Msg string `json:"msg,omitempty"`
}

// PingFrame {"_sys":"ping","ts":毫秒}
func PingFrame(tsMs int64) []byte { return encodeSys(sysFrame{Sys: "ping", Ts: tsMs}) }

// PongFrame {"_sys":"pong","ts":原样带回 ping 的 ts}
func PongFrame(tsMs int64) []byte { return encodeSys(sysFrame{Sys: "pong", Ts: tsMs}) }

// ErrorFrame {"_sys":"error","ts":毫秒,"msg":"原因"}
func ErrorFrame(tsMs int64, msg string) []byte {
	return encodeSys(sysFrame{Sys: "error", Ts: tsMs, Msg: msg})
}

// encodeSys 用 Encoder 而不是 json.Marshal：要关掉 HTML 转义，
// 否则中文之外的 < > & 会被写成 <，和 PHP 的 JSON_UNESCAPED_UNICODE 输出不一样。
func encodeSys(f sysFrame) []byte {
	var buf bytes.Buffer
	enc := json.NewEncoder(&buf)
	enc.SetEscapeHTML(false)
	_ = enc.Encode(f) // 结构体固定，不会失败
	return bytes.TrimRight(buf.Bytes(), "\n")
}

// SysType 取出系统帧的 _sys 和 ts，不是系统帧或解析失败时 ok 为 false。
func SysType(b []byte) (typ string, tsMs int64, ok bool) {
	if !IsSysFrame(b) {
		return "", 0, false
	}
	var f sysFrame
	if json.Unmarshal(b, &f) != nil {
		return "", 0, false
	}
	return f.Sys, f.Ts, true
}
