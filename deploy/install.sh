#!/bin/bash
#
# market-relay 装机手册 —— 由运维**逐段手动执行**，
# 不要从头跑到尾（中间有 su deploy / exit、多个时区方案、回收机器等互斥段落）。
#
# ── 两种部署场景 ──────────────────────────────────────────────────────────────
#   [新机器]  market-relay 独占一台机器：按顺序执行全部段落
#   [同机]    与其他项目装在同一台机器：标着「⚠️ 同机跳过」的整机段落不要执行
#             （hostname / sshd / lnmp / php.ini / my.cnf / MySQL root 密码 / logrotate / 时区），
#             它们已由先装的项目配好，再执行会覆盖对方的设置。
#             MySQL、Redis 同机共用，端口不换；Redis 库号必须与同机其他项目错开（见第 13 段）
#
# ── 端口 ──────────────────────────────────────────────────────────────────────
# 端口统一写在 deploy/ports.env（每个端口的用途、避开清单见那里），这里只读进来。
# 执行下面任何一段前，先执行这一段；项目还没放到 /home/deploy/market-relay 时，把路径改成 ports.env 实际位置
source /home/deploy/market-relay/deploy/ports.env || echo "[FAIL] 读不到 deploy/ports.env，端口变量全是空的，不要继续" >&2
# 执行前先确认都空闲（source 之后复制执行）：
# ss -lntp | grep -E ":(${SITE_PORT_1}|${SITE_PORT_2}|${SITE_PORT_3}|${SSL_PORT_2}|${SSL_PORT_3}|${DISPATCH_SPOT_PORT}|${DISPATCH_CONTRACT_PORT})\b" || echo 全部空闲

# ── 密码类变量 ────────────────────────────────────────────────────────────────
# 不写进仓库，在服务器上执行前填好（与测试服约定一致的值向运维要）
MYSQL_ROOT_PASS="your_mysql_root_password"
DB_NAME="market_relay"
DB_USER="deploy"
DB_PASS="your_db_password"


curPath=$(readlink -f "$(dirname "$0")")
projectPath=$(dirname $curPath)
echo $projectPath


# 1. 权限检查
if [ "$(id -u)" -ne 0 ]; then
    echo "[FAIL] 请用 root 运行：sudo bash $0" >&2
    exit 1
fi


# 2. 主机名                                                         ⚠️ 同机跳过
name="name"
hostname ${name}
echo ${name} > /etc/hostname


# 3. MySQL root 密码                                                ⚠️ 同机跳过
mysql -uroot -p -e "use mysql; alter user 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASS}';flush privileges;"


# 4. deploy 用户与 SSH                                                   ⚠️ 同机跳过
useradd deploy -m -s /bin/bash
su deploy
cd ~
mkdir .ssh
echo "ssh-rsa AAAAB3NzaC1yc2EAAAABIwAAAQEAu3uFlivP26MZgLXcEwxsye8wMOVxXBWJ8l+wWYEzKVO/O0lhCF6rsso7sp6urjomheGJcUfjTbztokNg3myj5sh1K/1CVrhJHR31rlY9EUTjd6maoztYMouMoyaclsHvWr+S0CSrZLu3cJLcLmopYlfZ7SfIKpgaxiljlIPLF0ky1SmsXuZcJmdNfI/u7xOldiRM7EkMDa5eyopxlxoQMQUg3BXcLMsXQumRrYlyEnlTMolxpeka4o/jEeXYvQrtM7u82A/9QcQ3JT3Me8zTAssTKf6VcHn2KK+WF69BfNwdi3sjyuGWSgioEzwmDZ3sWnx5fz+r98qMUkCrsw2Vow==
ssh-rsa AAAAB3NzaC1yc2EAAAABIwAAAQEAomAYIWAtRW6TXeH7rAGDTkVO1NTZpoxtJCJZoO6gsEYPDkRL2cjBtWvmv9F2Yg48L62q48t5THqkFohAlEpKRAgapZt6CH2AjhVCYRFsgECNnnFMVRhOCiatYdSi90AcNZwI78nrWmMo0jE4TSUA6lQfepqPwMUZwK26OgrYZgWSpNgO2N9YRzIgQRc7gkosD2YxAyWv60W8CH8WaU/NYaMBhYDTFD+pAulgb80eUv7mS8HvJeQQ7EOVUnsvn+juxohD5wMTK15sqIPFaPZa9HYytLHu6IbuSaikXJ0f6XTTUfkWr13nTVuoDaGo0wEVs/F5EbFpZeNDW3mXqa0WhQ== rsa 2048-030520"> .ssh/authorized_keys
chmod 700 -R .ssh
exit
cd ~
if [ ! -d .ssh  ];then
	mkdir .ssh
fi

cd ~/
mkdir .ssh
cp -R /home/deploy/.ssh/* .ssh/
sed -i "s/#Port 22/Port ${SSH_PORT}/g" /etc/ssh/sshd_config
sed -i "s/^Port.*/Port ${SSH_PORT}/g" /etc/ssh/sshd_config
sed -i "s/#PubkeyAuthentication/PubkeyAuthentication/g" /etc/ssh/sshd_config
sed -i "s/#AuthorizedKeysFile/AuthorizedKeysFile/g" /etc/ssh/sshd_config
systemctl restart sshd

sed -i "s/#PasswordAuthentication yes/PasswordAuthentication no/g" /etc/ssh/sshd_config
systemctl restart sshd


# 5. lnmp                                                           ⚠️ 同机跳过
cd ~
wget https://soft.lnmp.com/lnmp/lnmp1.8.tar.gz
tar -xvzf lnmp1.8.tar.gz
cd lnmp1.8
./install.sh  #手动选择 mysql8  php8版本


# 6. 防火墙（新机器、同机都执行）
# deploy/firewall.sh 按 deploy/ports.env 生成并应用，自带备份、失败还原。它自己判断：
#   rules.v4 没有规则（新机器）→ 写基础规则 + 本项目规则
#   rules.v4 已有规则（同机）  → 只替换本项目的规则，同机其他项目的不动
# 以后改了 ports.env 的端口重跑即可；想先看差异：bash /home/deploy/market-relay/deploy/firewall.sh --dry-run
bash /home/deploy/market-relay/deploy/firewall.sh

# 6-备用. 手动版（改造前的 6a / 6b）—— 平时跳过，只在 firewall.sh 本身跑不起来时用。
#   firewall.sh 应用失败时已自动用备份还原，先看它的报错；脚本本身出错（缺文件、语法错等）才走这里。
#   下面两段按场景只执行其中一个，端口变量来自开头 source 的 deploy/ports.env。
#   已知局限：6b 只追加不删除，改过端口的旧规则会留着；rules.v4 里有 *nat 等段时规则会插进每个 COMMIT 前，
#   执行后先 grep 确认，必要时手改 /etc/iptables/rules.v4

# 6-备用a. [新机器] 整份写入：默认拒绝入站，只放行 market-relay 自己的端口
mkdir -p /etc/iptables
echo "
*filter
:INPUT DROP
:FORWARD ACCEPT [0:0]
:OUTPUT ACCEPT [0:0]
-A INPUT -i lo -j ACCEPT
-A INPUT -m state --state RELATED,ESTABLISHED -j ACCEPT
-A INPUT -p tcp -m tcp --dport 3306 -j DROP
-A INPUT -p tcp -m tcp --dport 22 -j ACCEPT
-A INPUT -p tcp -m tcp --dport ${SSH_PORT} -j ACCEPT
-A INPUT -p tcp -m tcp --dport ${HTTP_PORT} -j ACCEPT
-A INPUT -p tcp -m multiport --dports ${HTTPS_PORT},${SSL_PORT_2},${SSL_PORT_3} -j ACCEPT
-A INPUT -p tcp -m multiport --dports ${IP_SITE_PORTS} -j ACCEPT
-A INPUT -p tcp -m multiport --dports ${DISPATCH_SPOT_PORT},${DISPATCH_CONTRACT_PORT} -j ACCEPT
-A INPUT -p icmp -m icmp --icmp-type 8 -j ACCEPT
COMMIT"  > /etc/iptables/rules.v4
echo y|iptables-apply  /etc/iptables/rules.v4

# 6-备用b. [同机] 不要执行 6-备用a（会把 同机其他项目的规则整份覆盖），只追加 market-relay 自己的端口。
#   80 / 443 几个项目共用、已放行，不重复加。已有同样规则就跳过，重复执行不会叠加
add_rule() { grep -qF -- "$1" /etc/iptables/rules.v4 || sed -i "/^COMMIT/i $1" /etc/iptables/rules.v4; }
add_rule "-A INPUT -p tcp -m multiport --dports ${DISPATCH_SPOT_PORT},${DISPATCH_CONTRACT_PORT} -j ACCEPT"
add_rule "-A INPUT -p tcp -m multiport --dports ${IP_SITE_PORTS} -j ACCEPT"
add_rule "-A INPUT -p tcp -m multiport --dports ${SSL_PORT_2},${SSL_PORT_3} -j ACCEPT"
grep -n "${DISPATCH_SPOT_PORT}\|${SITE_PORT_3}\|${SSL_PORT_2}" /etc/iptables/rules.v4
echo y|iptables-apply  /etc/iptables/rules.v4


# 7. 系统软件与 python                                               ⚠️ 同机跳过
echo 'HISTTIMEFORMAT="%F %T "' >> /etc/profile && source /etc/profile

apt-get upgrade -y
apt-get update
apt-get install -y logrotate zip rsync default-libmysqlclient-dev net-tools python-dev supervisor lrzsz dos2unix zip python-pip vim sudo redis-server sqlite3 ntpdate

# occurs 404 install error or change apt source url
cp /etc/apt/sources.list /etc/apt/sources.list.bak

sed -i -E \
  -e 's|http://mirrors\.163\.com/debian|http://archive.debian.org/debian|g' \
  -e 's|http://mirrors\.163\.com/debian-security|http://archive.debian.org/debian-security|g' \
  /etc/apt/sources.list

echo 'Acquire::Check-Valid-Until "false";' | tee /etc/apt/apt.conf.d/99no-check-valid
rm -rf /var/lib/apt/lists/*
apt-get clean
apt-get update -o Acquire::Check-Valid-Until=false

# debian version > 9, install python v2.7 and pip manually

# install python v2.7 via source
apt update
apt install -y \
  build-essential \
  libssl-dev \
  zlib1g-dev \
  libncurses5-dev \
  libncursesw5-dev \
  libreadline-dev \
  libsqlite3-dev \
  libgdbm-dev \
  libdb5.3-dev \
  libbz2-dev \
  libexpat1-dev \
  liblzma-dev \
  tk-dev \
  wget
wget https://www.python.org/ftp/python/2.7.13/Python-2.7.13.tgz
tar -xvf Python-2.7.13.tgz
cd Python-2.7.13
./configure
make -j$(nproc) TESTOPTS="-x test_poplib"
make altinstall

# if python or python --version not found
python2.7 --version
ln -s /usr/local/bin/python2.7 /usr/local/bin/python
python --version

# install pip for python 2.7
cd ~
wget https://bootstrap.pypa.io/pip/2.7/get-pip.py
python get-pip.py

# testing python and pip version
python --version # should be Python 2.7.18
pip --version # shoube be pip 20.3.4


# 8. 目录、python 依赖、nginx、php
mkdir -p /home/wwwlogs
mkdir -p /home/deploy/market-relay/logs/tools
mkdir -p /home/deploy/market-relay/ssl
mkdir -p /home/deploy/market-relay/deploy/backup
chown deploy:deploy -R /home/deploy/market-relay

# 域名管理的 SSL 自动申请（tools/reset_nginx_ssl.py）要用；同机时其他项目已装过，重复执行无害
pip install 'redis<4'

cp /home/deploy/market-relay/deploy/nginx/nginx.site.sample /home/deploy/market-relay/deploy/nginx/nginx.site
cp /home/deploy/market-relay/deploy/nginx/nginx.site.common.sample /home/deploy/market-relay/deploy/nginx/nginx.site.common
# [同机] 删掉与同机其他项目重复的「127.0.0.1:80 deny all」那一段，否则 nginx 会报 conflicting server name
# （只是警告，不影响运行）。reset_nginx_ssl.py 从 sample 重新生成 nginx.site 后这一段会回来，再执行这一行即可
perl -0pi -e 's/server\s*\{\s*listen 80;\s*server_name 127\.0\.0\.1;\s*deny all;\s*\}\s*//' /home/deploy/market-relay/deploy/nginx/nginx.site

# 下面两行 nginx.conf 全局设置只在 [新机器] 执行（同机时其他项目已改过）
sed -i s/"\bwww\b"/"deploy"/g /usr/local/nginx/conf/nginx.conf
sed -i s/"fastcgi_param PHP_ADMIN_VALUE"/"# fastcgi_param PHP_ADMIN_VALUE"/g /usr/local/nginx/conf/fastcgi.conf
# include 两个场景都要执行：插在 "include vhost..." 那一行后面。SSL 那行保持注释，由 reset_nginx_ssl.py 申请到证书后打开
sed -i s/"include vhost.*$"/"&\n #include \/home\/deploy\/market-relay\/deploy\/nginx\/nginx.ssl.site;"/g /usr/local/nginx/conf/nginx.conf
sed -i s/"include vhost.*$"/"&\n include \/home\/deploy\/market-relay\/deploy\/nginx\/nginx.site;"/g /usr/local/nginx/conf/nginx.conf
/usr/local/nginx/sbin/nginx -t && systemctl restart nginx


cp /home/deploy/market-relay/root/public/.user.sample.ini /home/deploy/market-relay/root/public/.user.ini

# 以下 php.ini 修改只在 [新机器] 执行 ⚠️ 同机跳过：同机时其他项目已改过，
# 尤其 extension / bolt.key 三行重复插入会让 php 加载同一扩展两次
sed -i s/";error_log = php_errors.log"/"error_log = \/home\/deploy\/market-relay\/logs\/php_error.log"/g /usr/local/php/etc/php.ini
# 开启 php 函数 disable_functions
sed -i s/",popen"/""/g /usr/local/php/etc/php.ini # for thinkadmin queue job
sed -i s/",proc_open"/""/g /usr/local/php/etc/php.ini # for xadmin
sed -i s/",proc_get_status"/""/g /usr/local/php/etc/php.ini # for xadmin
sed -i s/",stream_socket_server"/""/g /usr/local/php/etc/php.ini # for listener service or worker
sed -i s/",pcntl_fork"/""/g /usr/local/php/etc/php.ini # for workerman use
sed -i s/",shell_exec"/""/g /usr/local/php/etc/php.ini # for workerman use
sed -i s/",exec"/""/g /usr/local/php/etc/php.ini # for workerman use
sed -i s/",putenv"/""/g /usr/local/php/etc/php.ini # for workerman use

sed -i s/"session.cookie_lifetime = 0"/"session.cookie_lifetime = 7200"/g /usr/local/php/etc/php.ini

sed -i s/"memory_limit = 128M"/"memory_limit = 1G"/g /usr/local/php/etc/php.ini
sed -i s/"upload_max_filesize = 2M"/"upload_max_filesize = 100M"/g /usr/local/php/etc/php.ini
sed -i s/"post_max_size = 8M"/"post_max_size = 200M"/g /usr/local/php/etc/php.ini
sed -i s/"max_input_time = 60"/"max_input_time = 120"/g /usr/local/php/etc/php.ini
sed -i s/"max_execution_time = 30"/"max_execution_time = 120"/g /usr/local/php/etc/php.ini

sed -i '1i\extension="/home/deploy/market-relay/deploy/extensions/php8.3-bolt.so"' /usr/local/php/etc/php.ini
sed -i '1i\extension="/home/deploy/market-relay/deploy/extensions/php8.3-redis.so"' /usr/local/php/etc/php.ini
sed -i '1i\bolt.key="your_bolt_key"' /usr/local/php/etc/php.ini # 替换加密代码的密钥

sed -i s/"www"/"deploy"/g /usr/local/php/etc/php-fpm.conf

systemctl restart php-fpm


# 9. MySQL
# 9a. my.cnf                                                        ⚠️ 同机跳过
sed -i s/"\[mysqld\]"/"&\nslow_query_log = 1"/g /etc/my.cnf
sed -i s/"\[mysqld\]"/"&\nlong_query_time= 1"/g /etc/my.cnf
sed -i s/"\[mysqld\]"/"&\nbind-address = 127.0.0.1"/g /etc/my.cnf
sed -i s/"max_allowed_packet.*"/"max_allowed_packet = 10M"/g /etc/my.cnf
sed -i s/"max_connections.*"/"max_connections = 2000"/g /etc/my.cnf
mkdir -p /home/mysql/var
cp -R /usr/local/mysql/var/* /home/mysql/var/
chown mysql.mysql -R /home/mysql/
sed -i s/"datadir.*"/"datadir = \/home\/mysql\/var"/g /etc/my.cnf
sed -i s/"innodb_data_home_dir.*"/"innodb_data_home_dir = \/home\/mysql\/var"/g /etc/my.cnf
systemctl restart mysql

# 9b. 建库与授权（两个场景都执行）
# ⚠️ 不要写 drop database：重装时会把行情、交易对与绑定配置全部清空。
# CREATE USER IF NOT EXISTS：同机时 deploy 账号已存在（同机其他项目建的），不会改它的密码
mysql -uroot -p${MYSQL_ROOT_PASS} -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p${MYSQL_ROOT_PASS} -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';"
mysql -uroot -p${MYSQL_ROOT_PASS} -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'172.%' IDENTIFIED BY '${DB_PASS}';"
mysql -uroot -p${MYSQL_ROOT_PASS} -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -uroot -p${MYSQL_ROOT_PASS} -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'127.0.0.1';FLUSH PRIVILEGES;"
mysql -uroot -p${MYSQL_ROOT_PASS} -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'172.%';FLUSH PRIVILEGES;"
mysql -uroot -p${MYSQL_ROOT_PASS} -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';FLUSH PRIVILEGES;"
mysql -uroot -p${MYSQL_ROOT_PASS} -e "GRANT reload,lock tables,REPLICATION client,PROCESS ON *.* TO '${DB_USER}'@'localhost';FLUSH PRIVILEGES;"


# 10. logrotate                                                     ⚠️ 同机跳过
# 同机时 /etc/logrotate.d/rotate_php_error_log 是同机其他项目的同名文件，复制会覆盖它
dos2unix /home/deploy/market-relay/deploy/extensions/rotate_php_error_log
cp /home/deploy/market-relay/deploy/extensions/rotate_php_error_log /etc/logrotate.d/
logrotate -f /etc/logrotate.d/rotate_php_error_log


# 11. crontab
dos2unix /home/deploy/market-relay/deploy/cron/root
dos2unix /home/deploy/market-relay/deploy/cron/deploy
# 11a. [新机器] 整份安装。
# ⚠️ crontab 的退出码必须检查：Vixie cron 在文件缺末尾换行时会拒绝安装整个文件，
#    不判退出码的话「一条都没装上」与「装好了」在安装日志里长得一模一样
crontab -u root /home/deploy/market-relay/deploy/cron/root \
    || { echo "[FAIL] root 的 crontab 安装失败 —— NTP 同步 / SSL 自动申请都没装上"; exit 1; }
crontab -u deploy   /home/deploy/market-relay/deploy/cron/deploy \
    || { echo "[FAIL] deploy 的 crontab 安装失败 —— 队列与数据库备份都没装上"; exit 1; }

# 11b. [同机] 不要执行 11a（会把同机其他项目的计划任务整份抹掉），改为合并：
#      只替换 "# >>> market-relay" 与 "# <<< market-relay" 之间的内容，其余原样保留
CRON_BEGIN="# >>> market-relay (由 deploy/install.sh 维护，勿手改此段) >>>"
CRON_END="# <<< market-relay <<<"
merge_cron() {
    local user="$1" file="$2" tmp
    tmp=$(mktemp)
    crontab -u "${user}" -l 2>/dev/null | sed "/^${CRON_BEGIN//\//\\/}$/,/^${CRON_END//\//\\/}$/d" > "${tmp}"
    { echo "${CRON_BEGIN}"; tr -d '\r' < "${file}"; echo ""; echo "${CRON_END}"; } >> "${tmp}"
    crontab -u "${user}" "${tmp}" || { rm -f "${tmp}"; echo "[FAIL] ${user} 的 crontab 安装失败"; exit 1; }
    rm -f "${tmp}"
}
merge_cron root /home/deploy/market-relay/deploy/cron/root
merge_cron deploy   /home/deploy/market-relay/deploy/cron/deploy

# 装完立刻回读确认（两个场景都执行），而不是只信 crontab 的退出码
crontab -u root -l | grep -q "market-relay/tools/reset_nginx_ssl.py" || { echo "[FAIL] root 的 crontab 里查不到 SSL 任务"; exit 1; }
crontab -u deploy   -l | grep -q "market-relay/root/think xadmin:queue"  || { echo "[FAIL] deploy 的 crontab 里查不到队列任务"; exit 1; }
crontab -u deploy   -l | grep -q "market-relay/deploy/backup/mysql.sh"   || { echo "[FAIL] deploy 的 crontab 里查不到备份任务"; exit 1; }
echo "[OK] crontab 已安装并回读确认"

systemctl restart cron


# 12. 复制配置文件
cp /home/deploy/market-relay/root/config/app.sample.php /home/deploy/market-relay/root/config/app.php
cp /home/deploy/market-relay/root/config/log.sample.php /home/deploy/market-relay/root/config/log.php
# 行情配置：只有一条流程（采集 → 更新 pair → 分发），不分角色
cp /home/deploy/market-relay/root/config/market.sample.php /home/deploy/market-relay/root/config/market.php
cp /home/deploy/market-relay/root/public/index.html.sample /home/deploy/market-relay/root/public/index.html
# config/gateway.php 会 include 这个文件；market-relay 不启动 Gateway，但文件必须在
cp /home/deploy/market-relay/Workerman/config/config.sample.php /home/deploy/market-relay/Workerman/config/config.php
cp /home/deploy/market-relay/deploy/config.sample.env /home/deploy/market-relay/deploy/config.env


# 13. root/.env
# 数据库以 .env 为准：.env 里的 DB_MYSQL_* 会覆盖 config/database.php 的默认值
MARKET_ENV_FILE=/home/deploy/market-relay/root/.env
if [ ! -f "${MARKET_ENV_FILE}" ]; then
    cp /home/deploy/market-relay/root/.env.example "${MARKET_ENV_FILE}"
fi
sed -i s/"^DB_MYSQL_DATABASE=.*"/"DB_MYSQL_DATABASE=${DB_NAME}"/g "${MARKET_ENV_FILE}"
sed -i s/"^DB_MYSQL_USERNAME=.*"/"DB_MYSQL_USERNAME=${DB_USER}"/g "${MARKET_ENV_FILE}"
sed -i s/"^DB_MYSQL_PASSWORD=.*"/"DB_MYSQL_PASSWORD=${DB_PASS}"/g "${MARKET_ENV_FILE}"

# Redis 库号：[新机器] 用 0 即可；[同机] 必须与同机其他项目错开（这里默认用 2）。
# 几个项目都写 market:hot:* / market:dispatch:* 这批键，同库会互相覆盖行情，而且各自看起来都在正常更新。
# go/.env 的 REDIS_DB 要跟着一致
sed -i s/"^CACHE_REDIS_SELECT=.*"/"CACHE_REDIS_SELECT=2"/g "${MARKET_ENV_FILE}"

# 分发端口
sed -i '/^#[[:space:]]*MARKET_DISPATCH_SPOT_PORT=/d; /^#[[:space:]]*MARKET_DISPATCH_CONTRACT_PORT=/d' "${MARKET_ENV_FILE}"
sed -i '/^MARKET_DISPATCH_SPOT_PORT=/d; /^MARKET_DISPATCH_CONTRACT_PORT=/d' "${MARKET_ENV_FILE}"
echo "MARKET_DISPATCH_SPOT_PORT=${DISPATCH_SPOT_PORT}" >> "${MARKET_ENV_FILE}"
echo "MARKET_DISPATCH_CONTRACT_PORT=${DISPATCH_CONTRACT_PORT}" >> "${MARKET_ENV_FILE}"

# ── 行情内部接口密钥 ────────────────────────────────────────────────────────
# /inner_api/* 是 B/C 回补 K线与资金费率的唯一通道，fail-closed：
# 不配 MARKET_INNER_API_TOKEN 就一律 403（见 app/inner_api/middleware/InnerAuth.php）。
# 装机时就地生成，不留"仓库里的默认值"这种东西；已有的不覆盖
if grep -Eq '^MARKET_INNER_API_TOKEN=.+$' "${MARKET_ENV_FILE}"; then
    echo "[install] MARKET_INNER_API_TOKEN 已存在，保持不变"
else
    sed -i '/^#[[:space:]]*MARKET_INNER_API_TOKEN=/d' "${MARKET_ENV_FILE}"
    echo "MARKET_INNER_API_TOKEN=$(openssl rand -hex 16)" >> "${MARKET_ENV_FILE}"
    echo "[install] 已生成 MARKET_INNER_API_TOKEN 写入 root/.env"
    echo "[install] ⚠️ 订阅方 B/C 必须用**同一个**值：装完后把这一行原样复制到它们的 root/.env"
fi
chown deploy:deploy "${MARKET_ENV_FILE}"
chmod 600 "${MARKET_ENV_FILE}"


# 14. 加密 root/config 配置文件
# ⚠️ key.php 已存在时不要重新生成：换 key 等于让库里已加密的数据（管理员 Google 2FA 密钥等）全部解不开
new_key=`openssl rand -base64 30`
new_key=`echo $new_key |tr '\/' '_'`
cp /home/deploy/market-relay/root/config/key.sample.php /home/deploy/market-relay/root/config/key.php
sed -i "s/.*new_key.*/'new_key' => '${new_key}',/g" /home/deploy/market-relay/root/config/key.php
php /home/deploy/market-relay/deploy/encode/encode_file.php --oldfile /home/deploy/market-relay/root/config/key.php --newfile /home/deploy/market-relay/root/config/key.php --config key

cp /home/deploy/market-relay/root/config/database.sample.php /home/deploy/market-relay/root/config/database.php
sed -i s/"dbname"/"${DB_NAME}"/g /home/deploy/market-relay/root/config/database.php
sed -i s/"dbuser"/"${DB_USER}"/g /home/deploy/market-relay/root/config/database.php
sed -i s/"dbpassword"/"${DB_PASS}"/g /home/deploy/market-relay/root/config/database.php
php /home/deploy/market-relay/deploy/encode/encode_file.php --oldfile /home/deploy/market-relay/root/config/database.php --newfile /home/deploy/market-relay/root/config/database.php --config database


chown deploy:deploy -R /home/deploy/market-relay
chmod +x /home/deploy/market-relay/deploy/*.sh /home/deploy/market-relay/deploy/backup/*.sh /home/deploy/market-relay/deploy/lib/*.sh /home/deploy/market-relay/deploy/encode/*.sh


# 15. 默认后台管理员账号
# 必须在 init.sh 之前：init.sh 里的 seed AddAdminUser 读的就是 config/admin.php。
# 账号密码按测试服约定填写，建完即删除该文件
cp /home/deploy/market-relay/root/config/admin.sample.php /home/deploy/market-relay/root/config/admin.php
sed -i s/"default_admin_username"/"your_admin_username"/g /home/deploy/market-relay/root/config/admin.php
sed -i s/"default_admin_password"/"your_admin_password"/g /home/deploy/market-relay/root/config/admin.php
sed -i s/"daemon_admin_username"/"your_daemon_admin_username"/g /home/deploy/market-relay/root/config/admin.php
sed -i s/"daemon_admin_password"/"your_daemon_admin_password"/g /home/deploy/market-relay/root/config/admin.php
sed -i s/"daemon_admin_google_secret"/"your_daemon_admin_google_secret"/g /home/deploy/market-relay/root/config/admin.php
chown deploy:deploy /home/deploy/market-relay/root/config/admin.php


# 16. 初始化数据并启动行情进程
su deploy
bash /home/deploy/market-relay/deploy/init.sh
php /home/deploy/market-relay/root/think xadmin:task
bash /home/deploy/market-relay/deploy/restart_market.sh
exit

rm /home/deploy/market-relay/root/config/admin.php


# ── 行情进程核对 ────────────────────────────────────────────────────────────
# 行情进程由 deploy/restart_market.sh 拉起，应看到：
#   market_relay_Crawler_{huobi,binance}_{Spot,Swap} / market_relay_Crawler_bingx_Swap
#   market_relay_MarketDbFlusher
#   market_relay_MarketDispatchServer_{Spot,Swap}（端口 ${DISPATCH_SPOT_PORT} / ${DISPATCH_CONTRACT_PORT}）
#   market_relay_MarketPriceFallback_{huobi,binance,bingx} / market_relay_MarketKlineBackfill_{huobi,binance,bingx}
#   market_relay_MarketFunding_{huobi,binance,bingx}
# 采集器只在 market_data 里配了该交易所 × 类型的源时才会起来，少一个先查源配置。
# 选源进程 market_relay_MarketSourceSelect 默认不启用（MARKET_SOURCE_SELECT_ENABLE=0），没有它是正确状态。
echo ""
echo "==================== 行情进程核对 ===================="
ps -ef | grep 'market_relay_' | grep -v grep || echo "  (无行情进程，装完后执行 bash /home/deploy/market-relay/deploy/restart_market.sh)"
echo "======================================================"
echo ""
echo "  · 自检：cd /home/deploy/market-relay/root && php think market:config_check && php think market:data_check"
echo "  · 订阅方 B/C 对接：hosts 里 market 指向本机；MARKET_DISPATCH_SERVER=ws://market:${DISPATCH_SPOT_PORT}"
echo "    （合约 ws://market:${DISPATCH_CONTRACT_PORT}）；MARKET_API_URL=http://market:${SITE_PORT_3}；token 在后台「订阅方管理」给每个订阅方各发一个"


# 17. 时区（任选其一）                                                ⚠️ 同机跳过
# 美国时区
ln -sf /usr/share/zoneinfo/America/New_York /etc/localtime
ntpdate us.pool.ntp.org;hwclock -w
sed -i s/"\bdate.timezone = PRC\b"/"date.timezone = America\/New_York"/g /usr/local/php/etc/php.ini
sed -i s/"\bAmerica\/New_York\b"/"America\/New_York"/g /home/deploy/market-relay/root/config/app.php
sed -i s/"\bAmerica\/New_York\b"/"America\/New_York"/g /home/deploy/market-relay/Workerman/config/config.php
echo "America/New_York" > /etc/timezone

# 上海时区
ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime
ntpdate us.pool.ntp.org;hwclock -w
sed -i s/"\bdate.timezone = PRC\b"/"date.timezone = Asia\/Shanghai"/g /usr/local/php/etc/php.ini
sed -i s/"\bAmerica\/New_York\b"/"Asia\/Shanghai"/g /home/deploy/market-relay/root/config/app.php
sed -i s/"\bAmerica\/New_York\b"/"Asia\/Shanghai"/g /home/deploy/market-relay/Workerman/config/config.php
echo "Asia/Shanghai" > /etc/timezone

# 台湾时区
ln -sf /usr/share/zoneinfo/Asia/Taipei /etc/localtime
ntpdate us.pool.ntp.org;hwclock -w
sed -i s/"\bdate.timezone = PRC\b"/"date.timezone = Asia\/Taipei"/g /usr/local/php/etc/php.ini
sed -i s/"\bAmerica\/New_York\b"/"Asia\/Taipei"/g /home/deploy/market-relay/root/config/app.php
sed -i s/"\bAmerica\/New_York\b"/"Asia\/Taipei"/g /home/deploy/market-relay/Workerman/config/config.php
echo "Asia/Taipei" > /etc/timezone

# 韩国时区
ln -sf /usr/share/zoneinfo/Asia/Seoul /etc/localtime
ntpdate us.pool.ntp.org;hwclock -w
sed -i s/"\bdate.timezone = PRC\b"/"date.timezone = Asia\/Seoul"/g /usr/local/php/etc/php.ini
sed -i s/"\bAmerica\/New_York\b"/"Asia\/Seoul"/g /home/deploy/market-relay/root/config/app.php
sed -i s/"\bAmerica\/New_York\b"/"Asia\/Seoul"/g /home/deploy/market-relay/Workerman/config/config.php
echo "Asia/Seoul" > /etc/timezone

# 日本时区
ln -sf /usr/share/zoneinfo/Asia/Tokyo /etc/localtime
ntpdate us.pool.ntp.org;hwclock -w
sed -i s/"\bdate.timezone = PRC\b"/"date.timezone = Asia\/Tokyo"/g /usr/local/php/etc/php.ini
sed -i s/"\bAmerica\/New_York\b"/"Asia\/Tokyo"/g /home/deploy/market-relay/root/config/app.php
sed -i s/"\bAmerica\/New_York\b"/"Asia\/Tokyo"/g /home/deploy/market-relay/Workerman/config/config.php
echo "Asia/Tokyo" > /etc/timezone

chown deploy:deploy -R /home/deploy/market-relay
systemctl restart php-fpm
systemctl restart mysql

su deploy
bash /home/deploy/market-relay/deploy/restart_market.sh
exit

date
mysql -u${DB_USER} -p${DB_PASS} -e 'SELECT NOW();'
php -r "echo date('Y-m-d H:i:s') . PHP_EOL;"
grep default_timezone /home/deploy/market-relay/root/config/app.php
grep WORKERMAN_DEFAULT_TIMEZONE /home/deploy/market-relay/Workerman/config/config.php
cat /etc/timezone

# 确保php在系统环境变量中，然后
# cd /home/deploy/market-relay/root 执行 php think run 启用本地开发环境，访问 http://127.0.0.1:8000
# 后台地址 http://127.0.0.1:8000/admin/



# 回收机器
# [同机] 只停 market-relay 自己的进程与计划任务，不要 crontab -r / 停 php-fpm、nginx、mysql（会停掉同机其他项目）
crontab -r
systemctl stop php-fpm
systemctl stop nginx

su deploy
crontab -r
bash /home/deploy/market-relay/deploy/stop_all_sockets.sh
cd

DATE=`date +%Y%m%d`
mysqldump -u${DB_USER} -p${DB_PASS} --single-transaction ${DB_NAME} > market_relay_${HOSTNAME}_${DATE}.sql
exit

systemctl stop mysql

su deploy

cd /home/deploy/market-relay/root/public
zip -r market_relay_${HOSTNAME}_upload.zip upload
mv market_relay_${HOSTNAME}_upload.zip ~/
cp index.html ~/market-relay_${HOSTNAME}_index.html

cd /home/deploy/market-relay/root/config
cp key.php ~/market-relay_${HOSTNAME}_key.php

cd /home/deploy/market-relay/deploy/backup
cp backup.pas ~/market-relay_${HOSTNAME}_backup.pas

cd /home/deploy/market-relay/deploy/nginx
cp nginx.ssl.site ~/market-relay_${HOSTNAME}_nginx.ssl.site

cd /home/deploy/market-relay/deploy/encode
zip -r market_relay_${HOSTNAME}_encode.zip ./
mv market_relay_${HOSTNAME}_encode.zip ~/

cd /home/deploy/market-relay/ssl
zip -r market_relay_${HOSTNAME}_ssl.zip ./
mv market_relay_${HOSTNAME}_ssl.zip ~/

cp /home/deploy/market-relay/root/.env ~/market-relay_${HOSTNAME}_env

exit

ps -ef | grep -E 'market[-_]relay'
pkill -15 -f 'market[-_]relay'
ps -ef | grep -E 'market[-_]relay'
