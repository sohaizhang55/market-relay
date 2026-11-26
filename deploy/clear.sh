#!/bin/bash
# 部署后清缓存
#
# runtime/cache 里的东西都可重建，且有两类必须随发布清掉：权限节点/系统配置（永久缓存），
# 以及表结构缓存（fields_cache 在生产开启、永久，migrate 加列后不清模型就看不到新字段）。
# 以前不能整清是因为前端登录 token 也落在这里，会被连带删掉；token 已迁到独立的
# safefile/token store，这里才可以放心整清。
#
# ⚠️ runtime/cache 是只增不减的：file store 配的是永久缓存（config/cache.php），而 ThinkPHP 的
# File 驱动只在**读到**某个过期文件时才顺手删它，没有后台 GC。偏偏写进去的多是一次性键
# （验证码 CaptchaService、闪兑报价 flashswap:quote:*），写完不会再被读到，于是过期了也没人清。
# 文件数 ≈ 上次部署以来的这类请求数，原地 rm 要逐个 unlink，量大时能把部署卡住几十分钟。
set -eo pipefail

ROOT=/home/deploy/market-relay/root

# 目录缺失时 find 直接非零退出，在 set -e 下会中止整个部署；
# runtime/temp 是模板引擎按需创建的，不保证存在
mkdir -p "$ROOT/runtime/temp" "$ROOT/runtime/cache"
echo "[Success] temp 和 cache 已创建/已存在"

# 把一级子项整体 rename 进 trash，再把真正的删除丢后台：rename 的成本只跟子项数有关
# （cache 下是 255 个两位十六进制目录），不受里面堆了多少文件影响。
# trash 放在 runtime/ 下面，与被删目录同一文件系统，mv 才是纯改名而不是逐个复制。
#
# 只挪子项、不重建父目录 —— 父目录的属主与权限保持原样。若本脚本以 root 跑，
# 重建出来的目录会是 root:root，php-fpm 的 deploy 用户就再也写不进去了。
trash_children() {
    local dir="$1" trash
    [ -d "$dir" ] || return 0
    trash="$ROOT/runtime/.trash-$$-$(basename "$dir")"
    mkdir -p "$trash"
    find "$dir" -mindepth 1 -maxdepth 1 -exec mv {} "$trash/" \;
    nohup rm -rf "$trash" >/dev/null 2>&1 &
}

# 上一次部署若被中断，会留下没删完的 trash。只回收一小时前的，避开正在跑的那一份。
# 这一步同样丢后台：陈旧 trash 可能很大，在前台删就等于把刚搬走的阻塞又搬回来
stale=$(find "$ROOT/runtime" -maxdepth 1 -name '.trash-*' -mmin +60 2>/dev/null | wc -l | tr -d ' ') || stale=0
nohup find "$ROOT/runtime" -maxdepth 1 -name '.trash-*' -mmin +60 -exec rm -rf {} + >/dev/null 2>&1 &
echo "[Success] 历史遗留 trash ${stale} 个（上次部署被中断才会有；不为 0 时已转入后台删除）"

# 模板编译产物，自带源文件 mtime 校验
trash_children "$ROOT/runtime/temp"
echo "[Success] 模板编译产物清理完成"

# 框架缓存：权限节点 / 系统配置 / 表结构 / 验证码等，全部可重建
trash_children "$ROOT/runtime/cache"
echo "[Success] 框架缓存清理完成（实际删除转入后台，不阻塞部署）"

# 登录态不整清，只回收已过期的。TTL 固定 7 天，File 驱动按 filemtime + TTL 判过期，
# 而 check() 每次续期都会重写文件，所以 mtime 就是最后一次使用时间，按 mtime 筛
# 等价于按过期筛（过期项本身只在被读到时才删，不补这一步会一直堆积）。
# 用 -mmin 而不是 -mtime：后者按整天向下取整，+7 实际要满 8 天才删，会多留一轮
find "$ROOT/safefile/token" -type f -mmin +10080 -delete 2>/dev/null || true
echo "[Success] 过期登入缓存清理完成"

echo "[Success] 全部缓存清理完成"
