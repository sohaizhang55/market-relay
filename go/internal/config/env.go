package config

import (
	"bufio"
	"os"
	"strconv"
	"strings"
)

// env 配置文件里的全部键值。
type env map[string]string

// readEnv 读取 KEY=VALUE 格式的文件：# 开头是注释，空行忽略，值两边的引号会去掉。
func readEnv(path string) (env, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	e := env{}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		k, v, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		e[strings.TrimSpace(k)] = strings.Trim(strings.TrimSpace(v), `"'`)
	}
	return e, sc.Err()
}

// str 取字符串，没有或为空时用默认值。
func (e env) str(key, def string) string {
	if v := e[key]; v != "" {
		return v
	}
	return def
}

// num 取整数，没有或不是数字时用默认值。
func (e env) num(key string, def int) int {
	if n, err := strconv.Atoi(e[key]); err == nil {
		return n
	}
	return def
}

// list 取逗号分隔的列表，去掉空项。
func (e env) list(key string) []string {
	var out []string
	for _, s := range strings.Split(e[key], ",") {
		if s = strings.TrimSpace(s); s != "" {
			out = append(out, s)
		}
	}
	return out
}
