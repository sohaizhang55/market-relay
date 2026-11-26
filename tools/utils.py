# coding: utf-8

import io
import os
import time
import logging
from logging import handlers
print('============================================')
print('ts:%s' % time.strftime('%Y-%m-%d %H:%M:%S'))

project_dir =  os.path.dirname(os.path.dirname((os.path.abspath(__file__))))
tools_dir = os.path.join(project_dir, 'tools')
logs_dir = os.path.join(project_dir, 'logs', 'tools')
env_file = os.path.join(project_dir, 'root', '.env')


def logger(fiel_name='tools.log'):
    fmt = "[%(levelname)s] [%(asctime)s] [%(name)s-%(funcName)s-%(lineno)d] %(message)s"
    datefmt = "%Y%m%d-%H:%M:%S"
    format_str = logging.Formatter(fmt, datefmt)
    # logs/ 被 gitignore，新机器上不存在，TimedRotatingFileHandler 不会自己建目录
    if not os.path.isdir(logs_dir):
        os.makedirs(logs_dir)
    log_file = os.path.join(logs_dir, fiel_name)

    log = logging.getLogger()
    th = handlers.TimedRotatingFileHandler(log_file, 'midnight', 1, 2)
    th.setFormatter(format_str)
    log.addHandler(th)
    log.setLevel(logging.INFO)
    return log


def read_env():
    """读取 root/.env 的 KEY=VALUE（忽略注释与 [section] 行），读不到返回空 dict"""
    env = {}
    if not os.path.exists(env_file):
        return env
    # .env 里有中文注释：不指定编码时，Windows 按 GBK 解、cron 下无 LANG 时按 ASCII 解，都会直接抛异常
    with io.open(env_file, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#') or line.startswith('[') or '=' not in line:
                continue
            key, value = line.split('=', 1)
            env[key.strip()] = value.strip().strip('"').strip("'")
    return env


def redisClinet():
    """
    与 PHP 端 redis_client() 连同一个 Redis 库：host/port/库号/密码都取自 root/.env 的 CACHE_REDIS_*。
    库号对不上时，后台写进去的 SSL 信号这边永远读不到，且没有任何报错。
    """
    import redis
    env = read_env()
    return redis.StrictRedis(
        host=env.get('CACHE_REDIS_HOST') or '127.0.0.1',
        port=int(env.get('CACHE_REDIS_PORT') or 6379),
        db=int(env.get('CACHE_REDIS_SELECT') or 0),
        password=env.get('CACHE_REDIS_PASSWORD') or None,
    )


def redis_key(name):
    """补上 Redis key 前缀 CACHE_PREFIX，与 PHP 侧 app\\lib\\RedisCacheDriver 写入的 key 对齐"""
    return read_env().get('CACHE_PREFIX', '') + name
