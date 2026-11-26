#!/bin/bash

# 获取当前脚本所在的绝对路径，确保能精准定位到同目录下的 config.env
CONFIG_FILE="/home/deploy/market-relay/deploy/config.env"

# 判断配置文件是否存在，存在则引入
if [ -f "$CONFIG_FILE" ]; then
    # 使用 source 引入，里面的变量会瞬间注入到当前脚本的生命周期里
    source "$CONFIG_FILE"
    echo "[Success] 成功引入配置文件，当前锁定 PHP: $PHP_BIN"
else
    # 兜底防御：如果配置不小心被删了，给出报错并退出，防止盲目往下跑
    echo "[Error] 找不到配置文件: $CONFIG_FILE，请检查！"
    exit 1
fi

$PHP_BIN /home/deploy/market-relay/root/think migrate:run 
$PHP_BIN /home/deploy/market-relay/root/think xadmin:task 

# migrate 刚加过列，缓存没清干净后面就会跑在旧的表结构/权限节点上，中止比带病继续安全
bash /home/deploy/market-relay/deploy/clear.sh || { echo "[Error] clear.sh 执行失败，中止部署"; exit 1; }

# 节假日休市日历导入：数据文件随代码走（root/database/market_calendar_holiday.json，由
# tools/export_exchange_calendars.py 离线产出），这里只把它灌进 market_calendar_holiday 表。
# 幂等（按 calendar_code + holiday_date upsert），重跑报 0 行改动，所以直接挂进常规部署流程。
# 只覆盖 source='exchange_calendars' 的行，运维在后台手工加的临时调休不会被冲掉。
# 覆盖体检读的是 market_data.calendar_code（源维度，2026-09-18 从 coin_list 迁来），
# 报「哪本日历有行情源跟着走、却一条假日都没有」。那一列由种子随代码走，无需回填任务。
# 退出码非 0 有两种可能：导入本身失败，或导入成功但体检发现假日数据快到期 / 已到期。
# 两种的后果相同（tradfi 标的节假日照常开盘），所以合并成一条提示，细节看上方输出。
# 不中止部署 —— 假日缺失不动现有交易（tradfi 标的还没有交易对）；但必须喊出来，
# 因为「节假日照常开盘」正是上游文档反复点名的、最难发现的那个方向。
$PHP_BIN /home/deploy/market-relay/root/think market:import_calendar \
    || echo "[Warn] 节假日日历导入或体检报告了问题（见上方输出）—— 放着不管会让 tradfi 标的节假日照常开盘"
