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

# seed 里的 SQL 路径是相对的（'./database/xxx.sql'），不在 root/ 下跑一个都找不到，
# 而那几个 seeder 找不到文件时只 echo 不抛异常 —— 装机会"成功"跑完但币种/交易对/行情源全是空的。
# install.sh 调本脚本时 cwd 是 /home/deploy，所以这一行不能省。
cd /home/deploy/market-relay/root || { echo "[Error] 进不去 root 目录"; exit 1; }

# db 数据初始化
$PHP_BIN /home/deploy/market-relay/root/think migrate:run
$PHP_BIN /home/deploy/market-relay/root/think seed:run -s InitMasterMenu
$PHP_BIN /home/deploy/market-relay/root/think seed:run -s AddAdminUser

# 初始数据
# InitMarketData 必须在 InitExchangePair 之前：交易对的 source_key 指向 market_data 里的行情源
$PHP_BIN /home/deploy/market-relay/root/think seed:run -s InitCoinCategory
$PHP_BIN /home/deploy/market-relay/root/think seed:run -s InitMarketData
$PHP_BIN /home/deploy/market-relay/root/think seed:run -s InitExchangePair

# 节假日休市日历（幂等，update.sh 每次部署也会重跑）
$PHP_BIN /home/deploy/market-relay/root/think market:import_calendar
