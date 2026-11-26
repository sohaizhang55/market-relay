#!/usr/bin/env python
#-*- coding: utf-8 -*-

import os
import datetime
import time
import sys
import utils
import types
import json
try:
    import urlparse
except:
    # Python 3 写法
    from urllib.parse import urlparse

logger = utils.logger(os.path.basename(__file__) + '.log') 
project_dir =  os.path.dirname(os.path.dirname((os.path.abspath(__file__))))  

redis = utils.redisClinet()
 
 
nginx_ssl_template_file = os.path.join(project_dir, 'deploy/nginx', 'nginx.ssl.site.sample')
nginx_ssl_file = os.path.join(project_dir, 'deploy/nginx', 'nginx.ssl.site')
nginx_template_file = os.path.join(project_dir, 'deploy/nginx', 'nginx.site.sample')
nginx_file = os.path.join(project_dir, 'deploy/nginx', 'nginx.site')
gen_templat_file = os.path.join(project_dir, 'ssl', 'gen_cert.sh.sample')
gen_file = os.path.join(project_dir, 'ssl', 'gen_cert.sh')
ssl_dir = os.path.join(project_dir, 'ssl')
signed_file = os.path.join(project_dir, 'ssl', 'signed.crt') 
# 动态生成项目前缀，确保 Redis Key 与 PHP 后台匹配
project_prefix = os.path.basename(project_dir)
# redis_key() 补 Redis key 前缀 CACHE_PREFIX：PHP 侧 redis_client() 写入时自动带上，这里要手动对齐
ssl_conf_cache_key = utils.redis_key('%s_ssl_config' % project_prefix)
run_lock_key = utils.redis_key('%s_rest_ssl_lock' % project_prefix)
# 手动触发按当前配置重签：redis-cli set {CACHE_PREFIX}{project_prefix}_reload_ssl_conf 1
reload_ssl_conf_key = utils.redis_key('%s_reload_ssl_conf' % project_prefix)

# 手动输入域名
# set market-relay_ssl_config "{\"change_ssl_domain\": 1, \"ssl_domain\": \"www.example.com\"}"

suc_domain_ts_key = utils.redis_key('%s_ssl_suc_domain_ts' % project_prefix)

def get_domain_ts(domain):
    ts = redis.hget(suc_domain_ts_key, domain)
    if not ts:
        return 0
    return int(ts)

def set_domain_ts(domain):
    redis.hset(suc_domain_ts_key, domain, int(time.time()))

def get_run_lock(): 
    ret = redis.setnx(run_lock_key, 1)
    if not ret:
        logger.error("is running... ret:%s" % ret)
        return
    redis.expire(run_lock_key, 600)
    return ret
 

def get_real_domain(ssl_domain):
    if not ssl_domain:
        return ssl_domain
    if ssl_domain.startswith('http://'):
        ssl_domain = ssl_domain[7:]
    if ssl_domain.startswith('https://'):
        ssl_domain = ssl_domain[8:] 
    sls = ssl_domain.split(':')
    ssl_domain = sls[0]
    ssl_domain = ssl_domain.split('/')[0]
    return ssl_domain.strip()

def copy_file(src, dst, domain2replace, append=False):
    if not os.path.exists(src):
        logger.error('invalid src:%s' % src)
        return False 
    f = open(src, 'r')
    content = f.read()
    content = content.decode('utf-8')
    f.close()
    for domain, replace in domain2replace.items():
        content = content.replace(replace, domain)
    if append:
        f = open(dst, 'a+') 
        f.write('\n')
        f.write('\n')
        logger.info('suc append domain2replace:%s to dst:%s' % (domain2replace, dst))
    else:
        f = open(dst, 'w+')
        logger.info('suc copy domain2replace:%s to dst:%s' % (domain2replace, dst))
    f.write(content.encode('utf-8'))
    f.close()
    return True
  

def get_ssl_conf():
    conf = redis.get(ssl_conf_cache_key) 
    if not conf:
        logger.info('== ssl conf not found')
        return
    val = json.loads(conf)
    return val

def set_ssl_conf(val):
    if isinstance(val, dict):
        val = json.dumps(val) 
    logger.info('set_ssl_conf set conf:%s' % val)
    redis.set(ssl_conf_cache_key, val)

def get_domain_list():
    val = get_ssl_conf()
    if not val:
        return []
    reset_ssl_domain = val.get('change_ssl_domain')
    ssl_domain = val.get('ssl_domain')
    if not reset_ssl_domain:
        return [] 
    if not ssl_domain:
        return []
    logger.info('reset ssl_domain:%s' % (ssl_domain)) 
    domain_list = ssl_domain.split(',')
    domain_list = [get_real_domain(d) for d in domain_list if d]
    return domain_list 

def get_last_suc_domain_lsit():
    val = get_ssl_conf()
    if not val:
        return [] 
    ssl_domain = val.get('suc_ssl_domain') 
    logger.info('last suc_ssl_domain:%s' % (ssl_domain)) 
    if not ssl_domain:
        return []
    domain_list = ssl_domain.split(',')
    domain_list = [get_real_domain(d) for d in domain_list if d]
    return domain_list 
 
def do_nginx_site(domain_list): 
    if not domain_list:
        return  
    logger.info("do_nginx_site:%s" % domain_list)
    domain2replace = {}
    for idx, domain in enumerate(domain_list):
        replace = "@SERVER_NAME_%s@" % (idx + 1)
        domain2replace[domain] = replace
    ret = copy_file(nginx_template_file, nginx_file, domain2replace)
    if not ret:
        return
    # 动态匹配项目部署路径。nginx.ssl.site 生成在 deploy/nginx/ 下，nginx.conf 的 include 也指向那里
    # （见 install.sh）；原先拼的是 deploy/nginx.ssl.site，少一级目录，sed 永远匹配不上
    deploy_dir = os.path.join(project_dir, 'deploy', 'nginx').replace('/', '\\/')
    cmd = '''sed -i s/".*include.*%s\\/nginx.ssl.site.*$"/"#include %s\\/nginx.ssl.site;"/g /usr/local/nginx/conf/nginx.conf''' % (deploy_dir, deploy_dir)
    logger.info("cmd:%s" % cmd)
    os.system(cmd)
    
    cmd = "/usr/local/nginx/sbin/nginx -s reload"
    logger.info("cmd:%s" % cmd)
    os.system(cmd)
    return True

def do_nginx_site_ssl(domain_list):
    if not domain_list:
        return  
    suc_domain = []
    last_suc_domain_list = get_last_suc_domain_lsit()
    logger.info("last_suc_domain_list:%s" % last_suc_domain_list)
    for domain in domain_list:
        logger.info("domain:%s start gen===================" % (domain))
        if domain in last_suc_domain_list:
            last_ts = get_domain_ts(domain)
            if int(time.time()) - last_ts > 86400 * 30:
                logger.info("domain:%s, already suc, but expire last_ts:%s" % (domain, last_ts))
            else:
                suc_domain.append(domain)
                logger.info("domain:%s, already suc, last_ts:%s" % (domain, last_ts))
                continue
        ret = copy_file(gen_templat_file, gen_file, {domain: '@SERVER_NAME@'})
        if not ret:
            continue
        os.chdir(os.path.join(project_dir, 'ssl'))
        cmd = "cd %s" % ssl_dir 
        logger.info("domain:%s cmd:%s" % (domain, cmd))
        os.system(cmd) 
        cmd = "sh gen_cert.sh"
        logger.info("domain:%s cmd:%s" % (domain, cmd))
        os.system(cmd) 
        with open(signed_file, 'r') as f:
            c = f.read()
        if not c: 
            logger.error('gen ssl failed, invalid signed_file domain:%s' % domain)
            continue 
        logger.info("domain:%s gen ssl cert suc" % (domain))
        suc_domain.append(domain)
        logger.info("domain:%s end gen===================" % (domain))
        time.sleep(1)
    suc_domain_list = []
    for domain in domain_list:
        if domain not in suc_domain:
            continue
        suc_domain_list.append(domain)
        set_domain_ts(domain)
    logger.info("this time suc_domain_list:%s" % suc_domain_list)
    if not suc_domain_list:
        logger.error("this time no suc_domain_list")
        return
    append = False
    for domain in suc_domain_list:
        ret = copy_file(nginx_ssl_template_file, nginx_ssl_file, {domain: '@SERVER_NAME@'}, append)
        if not ret:
            continue
        append = True
        
    deploy_dir = os.path.join(project_dir, 'deploy', 'nginx').replace('/', '\\/')
    cmd = '''sed -i s/".*#.*include.*%s\\/nginx.ssl.site.*$"/"include %s\\/nginx.ssl.site;"/g /usr/local/nginx/conf/nginx.conf''' % (deploy_dir, deploy_dir)
    logger.info("cmd:%s" % (cmd))
    os.system(cmd) 
    cmd = "/usr/local/nginx/sbin/nginx -s reload"
    logger.info("cmd:%s" % (cmd))
    os.system(cmd)  
    return suc_domain_list
 
def do_ssl_status(domain_list, suc_domain_list): 
    if not suc_domain_list:
        return
    for idx, domain in enumerate(domain_list):
        if domain not in suc_domain_list:
            continue
        match = "SERVER_NAME_%s" % (idx + 1) 
        cmd = '''sed -i '/%s/{n;s/#.*rewrite/rewrite/;}' %s''' % (match, nginx_file)
        logger.info("domain:%s, cmd:%s" % (domain, cmd))
        os.system(cmd)

    cmd = "/usr/local/nginx/sbin/nginx -s reload"
    logger.info("cmd:%s" % cmd)
    os.system(cmd)
    return True 

def update_ssl_done(suc_domain_list):
    redis.delete(run_lock_key) 
    logger.info("update_ssl_done")
    val = get_ssl_conf()
    if not val:
        return 
    if suc_domain_list: 
        suc_domain_list = ','.join(suc_domain_list)
    else:
        suc_domain_list = ''
    val['change_ssl_domain'] = 0
    val['suc_ssl_domain'] = suc_domain_list
    set_ssl_conf(val)
 

def reload_conf(): 
    reload_ssl_conf = redis.get(reload_ssl_conf_key) in (b'1', '1')
    redis.set(reload_ssl_conf_key, '0')
    logger.info("%s:%s" % (reload_ssl_conf_key, reload_ssl_conf))
    if not reload_ssl_conf:
        return
    val = get_ssl_conf()
    if not val:
        return   
    ssl_domain = val.get('ssl_domain') 
    logger.info('reset ssl_domain:%s' % (ssl_domain)) 
    domain_list = ssl_domain.split(',')
    domain_list = [get_real_domain(d) for d in domain_list if d] 

    if not get_run_lock():
        return
    if not do_nginx_site(domain_list):
        return
    suc_domain_list = do_nginx_site_ssl(domain_list)  
    do_ssl_status(domain_list, suc_domain_list)  
    update_ssl_done(suc_domain_list)

def main():  
    domain_list = get_domain_list()
    if not domain_list:
        return
    if not get_run_lock():
        return
    if not do_nginx_site(domain_list):
        return
    suc_domain_list = do_nginx_site_ssl(domain_list)  
    do_ssl_status(domain_list, suc_domain_list)  
    update_ssl_done(suc_domain_list)
 

if __name__ == "__main__": 
    logger.info("start run------------------------------------")
    main() 
    reload_conf()
