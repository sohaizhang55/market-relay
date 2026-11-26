<?php

namespace GatewayClient;

/**
 * GatewayClient (Fixed for GatewayProtocol)
 * 
 * 用于 ThinkPHP 或其他 FPM 环境下向 GatewayWorker 服务发送数据的客户端
 * 严格遵守 GatewayWorker 的 28 字节包头 binary 协议
 */
class Gateway
{
    protected static $config = [];

    const CMD_ON_CONNECT                = 1;
    const CMD_ON_MESSAGE                = 3;
    const CMD_ON_CLOSE                  = 4;
    const CMD_SEND_TO_ONE               = 5;
    const CMD_SEND_TO_ALL               = 6;
    const CMD_KICK                      = 7;
    const CMD_DESTROY                   = 8;
    const CMD_UPDATE_SESSION            = 9;
    const CMD_GET_ALL_CLIENT_SESSIONS  = 10;
    const CMD_IS_ONLINE                 = 11;
    const CMD_BIND_UID                  = 12;
    const CMD_UNBIND_UID                = 13;
    const CMD_SEND_TO_UID               = 14;
    const CMD_GET_CLIENT_ID_BY_UID     = 15;
    const CMD_JOIN_GROUP                = 20;
    const CMD_LEAVE_GROUP               = 21;
    const CMD_SEND_TO_GROUP             = 22;
    const CMD_GET_CLIENT_SESSIONS_BY_GROUP = 23;
    const CMD_GET_CLIENT_COUNT_BY_GROUP = 24;
    const CMD_SELECT                    = 25;
    const CMD_GET_GROUP_ID_LIST         = 26;
    const CMD_UNGROUP                   = 27;
    const CMD_WORKER_CONNECT            = 200;
    const CMD_PING                      = 201;
    const CMD_GATEWAY_CLIENT_CONNECT    = 202;
    const CMD_GET_SESSION_BY_CLIENT_ID  = 203;
    const CMD_SET_SESSION               = 204;

    const FLAG_BODY_IS_SCALAR = 0x01;
    const FLAG_NOT_CALL_ENCODE = 0x02;

    public static function app($name = 'admin')
    {
        self::$config = config("gateway.{$name}");
        return __CLASS__;
    }

    public static function sendToAll($message, $exclude_client_id = null)
    {
        return self::sendToPublish([
            'cmd'               => self::CMD_SEND_TO_ALL,
            'body'              => $message,
            'exclude_client_id' => $exclude_client_id,
            'exclude'           => $exclude_client_id,
        ]);
    }

    public static function sendToClient($client_id, $message)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_SEND_TO_ONE,
            'connection_id' => $address_data['connection_id'],
            'body'          => $message,
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function closeClient($client_id, $message = '')
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_KICK,
            'connection_id' => $address_data['connection_id'],
            'body'          => $message,
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function destroyClient($client_id)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_DESTROY,
            'connection_id' => $address_data['connection_id'],
            'body'          => '',
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function isOnline($client_id)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return 0;

        $res = self::sendAndRecv([
            'cmd'           => self::CMD_IS_ONLINE,
            'connection_id' => $address_data['connection_id'],
        ], $address_data['local_ip'], $address_data['local_port']);
        
        return $res ? (int)$res['body'] : 0;
    }

    public static function sendToUid($uid, $message)
    {
        return self::sendToPublish([
            'cmd'      => self::CMD_SEND_TO_UID,
            'ext_data' => json_encode([$uid]),
            'body'     => $message,
        ]);
    }

    public static function sendToGroup($group, $message, $exclude_client_id = null)
    {
        $data = [
            'cmd'      => self::CMD_SEND_TO_GROUP,
            'ext_data' => json_encode([
                'group'   => (array)$group,
                'exclude' => $exclude_client_id,
            ]),
            'body'     => $message,
        ];
        return self::sendToPublish($data);
    }

    public static function joinGroup($client_id, $group)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_JOIN_GROUP,
            'local_ip'      => $address_data['local_ip'],
            'local_port'    => $address_data['local_port'],
            'connection_id' => $address_data['connection_id'],
            'ext_data'      => (string)$group,
            'body'          => '',
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function leaveGroup($client_id, $group)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_LEAVE_GROUP,
            'local_ip'      => $address_data['local_ip'],
            'local_port'    => $address_data['local_port'],
            'connection_id' => $address_data['connection_id'],
            'ext_data'      => (string)$group,
            'body'          => '',
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function bindUid($client_id, $uid)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_BIND_UID,
            'local_ip'      => $address_data['local_ip'],
            'local_port'    => $address_data['local_port'],
            'connection_id' => $address_data['connection_id'],
            'ext_data'      => (string)$uid,
            'body'          => '',
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function unbindUid($client_id, $uid)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_UNBIND_UID,
            'local_ip'      => $address_data['local_ip'],
            'local_port'    => $address_data['local_port'],
            'connection_id' => $address_data['connection_id'],
            'ext_data'      => (string)$uid,
            'body'          => '',
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function setSession($client_id, array $session)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_SET_SESSION,
            'connection_id' => $address_data['connection_id'],
            'body'          => $session,
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function updateSession($client_id, array $session)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        return self::sendToPublish([
            'cmd'           => self::CMD_UPDATE_SESSION,
            'connection_id' => $address_data['connection_id'],
            'body'          => $session,
        ], $address_data['local_ip'], $address_data['local_port']);
    }

    public static function getSession($client_id)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        $res = self::sendAndRecv([
            'cmd'           => self::CMD_GET_SESSION_BY_CLIENT_ID,
            'connection_id' => $address_data['connection_id'],
        ], $address_data['local_ip'], $address_data['local_port']);
        
        return $res ? $res['body'] : false;
    }

    public static function getClientIdByUid($uid)
    {
        $res = self::sendAndRecv([
            'cmd'      => self::CMD_GET_CLIENT_ID_BY_UID,
            'ext_data' => (string)$uid,
        ]);
        return $res ? $res['body'] : [];
    }

    public static function getUidByClientId($client_id)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return false;

        $res = self::sendAndRecv([
            'cmd'           => self::CMD_SELECT,
            'ext_data'      => json_encode([
                'fields' => ['uid'],
                'where'  => ['connection_id' => [$address_data['connection_id']]]
            ]),
        ], $address_data['local_ip'], $address_data['local_port']);
        
        if ($res && !empty($res['body'])) {
            foreach ($res['body'] as $local_ip => $data) {
                foreach ($data as $port => $items) {
                    foreach ($items as $info) {
                        return $info['uid'] ?? null;
                    }
                }
            }
        }
        return null;
    }

    public static function isUidOnline($uid)
    {
        return (bool)self::getClientIdByUid($uid);
    }

    public static function getGroupsByClientId($client_id)
    {
        $address_data = self::decodeClientId($client_id);
        if (!$address_data) return [];

        // 如果 local_ip 为 0，说明 Gateway 绑定的是 0.0.0.0，此时 sendAndRecv 传入 null 会自动使用配置的 register_host
        $target_ip = ($address_data['local_ip'] === 0) ? null : $address_data['local_ip'];

        $res = self::sendAndRecv([
            'cmd'           => self::CMD_SELECT,
            'ext_data'      => json_encode([
                'fields' => ['groups'],
                'where'  => ['connection_id' => [$address_data['connection_id']]]
            ]),
        ], $target_ip, $address_data['local_port']);
        
        if ($res && isset($res['body']) && is_array($res['body'])) {
            // 兼容性处理：递归或多层检查获取 groups 字段
            $data = $res['body'];
            
            // 1. 检查扁平结构 [conn_id => ['groups'=>...]]
            foreach ($data as $item) {
                if (isset($item['groups'])) {
                    $groups = $item['groups'];
                    return is_array($groups) ? array_values($groups) : array_filter(explode(',', (string)$groups));
                }
                // 2. 检查嵌套结构 [ip => [port => [conn_id => ['groups'=>...]]]]
                if (is_array($item)) {
                    foreach ($item as $subItem) {
                        if (isset($subItem['groups'])) {
                            $groups = $subItem['groups'];
                            return is_array($groups) ? array_values($groups) : array_filter(explode(',', (string)$groups));
                        }
                        if (is_array($subItem)) {
                            foreach ($subItem as $finalItem) {
                                if (isset($finalItem['groups'])) {
                                    $groups = $finalItem['groups'];
                                    return is_array($groups) ? array_values($groups) : array_filter(explode(',', (string)$groups));
                                }
                            }
                        }
                    }
                }
            }
        }
        return [];
    }

    public static function getAllClientSessions($group = '')
    {
        $res = self::sendAndRecv([
            'cmd'      => $group ? self::CMD_GET_CLIENT_SESSIONS_BY_GROUP : self::CMD_GET_ALL_CLIENT_SESSIONS,
            'ext_data' => (string)$group,
        ]);
        return $res ? $res['body'] : [];
    }

    public static function getClientCountByGroup($group = '')
    {
        $res = self::sendAndRecv([
            'cmd'      => self::CMD_GET_CLIENT_COUNT_BY_GROUP,
            'ext_data' => (string)$group,
        ]);
        return $res ? (int)$res['body'] : 0;
    }

    public static function getUidListByGroup($group)
    {
        $res = self::sendAndRecv([
            'cmd'           => self::CMD_SELECT,
            'ext_data'      => json_encode([
                'fields' => ['uid'],
                'where'  => ['groups' => (array)$group]
            ]),
        ]);
        
        $uid_map = [];
        if ($res && !empty($res['body'])) {
            foreach ($res['body'] as $local_ip => $data) {
                foreach ($data as $port => $items) {
                    foreach ($items as $info) {
                        if (!empty($info['uid'])) {
                            $uid_map[$info['uid']] = $info['uid'];
                        }
                    }
                }
            }
        }
        return array_values($uid_map);
    }

    public static function getAllGroupIdList()
    {
        $res = self::sendAndRecv([
            'cmd' => self::CMD_GET_GROUP_ID_LIST,
        ]);
        return $res ? $res['body'] : [];
    }

    public static function ungroup($group)
    {
        if (!$group) return false;
        return self::sendToPublish([
            'cmd'      => self::CMD_UNGROUP,
            'ext_data' => (string)$group,
        ]);
    }

    protected static function decodeClientId($client_id)
    {
        if (strlen($client_id) === 20 || strlen($client_id) === 32) {
            $data = unpack('Nlocal_ip/nlocal_port/Nconnection_id', pack('H*', $client_id));
            if ($data) return $data;
        }
        return false;
    }

    protected static function encode($data)
    {
        $body = $data['body'] ?? '';
        $flag = is_scalar($body) ? self::FLAG_BODY_IS_SCALAR : 0;
        if (!is_scalar($body)) {
            $body = serialize($body);
        }

        $ext_data = (string)($data['ext_data'] ?? '');
        $ext_len = strlen($ext_data);

        $header = pack(
            'NCNnNnNCnN',
            28 + $ext_len + strlen($body),
            $data['cmd'],
            $data['local_ip'] ?? 0,
            $data['local_port'] ?? 0,
            $data['client_ip'] ?? 0,
            $data['client_port'] ?? 0,
            $data['connection_id'] ?? 0,
            $flag,
            $data['gateway_port'] ?? 0,
            $ext_len
        );
        return $header . $ext_data . $body;
    }

    protected static function decode($buffer)
    {
        $data = unpack("Npack_len/Ccmd/Nlocal_ip/nlocal_port/Nclient_ip/nclient_port/Nconnection_id/Cflag/ngateway_port/Next_len", $buffer);
        $header_len = 28;
        if ($data['ext_len'] > 0) {
            $data['ext_data'] = substr($buffer, $header_len, $data['ext_len']);
            $body_raw = substr($buffer, $header_len + $data['ext_len']);
        } else {
            $data['ext_data'] = '';
            $body_raw = substr($buffer, $header_len);
        }

        if ($data['flag'] & self::FLAG_BODY_IS_SCALAR) {
            $data['body'] = $body_raw;
        } else {
            try {
                $data['body'] = unserialize($body_raw);
            } catch (\Throwable $e) {
                $data['body'] = $body_raw;
            }
        }
        return $data;
    }

    protected static function sendToPublish($data, $fixed_ip = null, $fixed_port = null)
    {
        if (empty(self::$config)) self::app('admin');

        $bin_data = self::encode($data);

        if ($fixed_ip && $fixed_port) {
            return self::socketSendBlocking(long2ip($fixed_ip) . ":{$fixed_port}", $bin_data);
        }

        $host = self::$config['register_host'] ?? '127.0.0.1';
        $startPort = self::$config['start_port'] ?? 2900;
        $count = self::$config['gateway_count'] ?? 1;
        $success = false;
        for ($i = 0; $i < $count; $i++) {
            if (self::socketSendBlocking("{$host}:" . ($startPort + $i), $bin_data)) {
                $success = true;
            }
        }
        return $success;
    }

    protected static function sendAndRecv($data, $fixed_ip = null, $fixed_port = null)
    {
        if (empty(self::$config)) self::app('admin');
        
        $bin_data = self::encode($data);
        
        $address = "";
        if ($fixed_ip && $fixed_port) {
            $address = "tcp://" . long2ip($fixed_ip) . ":{$fixed_port}";
        } else {
            $host = self::$config['register_host'] ?? '127.0.0.1';
            $startPort = self::$config['start_port'] ?? 2900;
            $address = "tcp://{$host}:{$startPort}";
        }

        $client = @stream_socket_client($address, $errno, $errmsg, 3);
        if (!$client) return false;

        $secretKey = self::$config['secret_key'] ?? '';
        if ($secretKey) {
            $auth_data = self::encode([
                'cmd'  => self::CMD_GATEWAY_CLIENT_CONNECT,
                'body' => json_encode(['secret_key' => $secretKey])
            ]);
            fwrite($client, $auth_data);
        }

        fwrite($client, $bin_data);
        
        // 先读取 4 字节长度
        $len_buffer = fread($client, 4);
        if (strlen($len_buffer) < 4) {
            fclose($client);
            return false;
        }
        
        $unpack_data = unpack("Nlen", $len_buffer);
        $len = $unpack_data['len'];
        
        // 读取剩余数据 (注意：标准协议 len 包含 header，简化协议 len 仅指 body)
        $remain_data = '';
        $read_len = 0;
        // 如果是标准协议包，len 至少 28，我们需要再读 len - 4 字节
        // 如果是简化协议包（如 SELECT），len 是 body 长度，我们需要读完 len 字节
        // 这里我们先尝试读完，如果是标准协议，之后再分拆
        
        // 由于 GatewayWorker 的 SELECT 返回是不带 28 字节头的，我们先读完 body
        while ($read_len < $len) {
            $buf = fread($client, $len - $read_len);
            if ($buf === "" || $buf === false) break;
            $remain_data .= $buf;
            $read_len += strlen($buf);
        }
        fclose($client);

        // 尝试反序列化（针对简化协议 SELECT 响应）
        try {
            $unserialized = @unserialize($remain_data);
            if ($unserialized !== false || $remain_data === serialize(false)) {
                return ['body' => $unserialized];
            }
        } catch (\Throwable $e) {}

        // 如果反序列化失败，说明可能是标准协议包，尝试 decode
        return self::decode($len_buffer . $remain_data);
    }

    protected static $instance_client = [];

    /**
     * @var array<string, float> 地址 => 熔断截止时间 (microtime)，冷却期内跳过重连，
     * 避免逐条消息阻塞等待 connect 超时。
     */
    protected static $failedUntil = [];

    /** 熔断冷却时间 (秒) */
    const CONNECT_FAIL_COOLDOWN = 3;

    /**
     * @var array<string,int> 推送统计，供 market:subscribe 的 stat 汇总输出。
     * 行情类推送已改走 app\lib\GatewayAsyncPublisher，这里只剩不可丢消息的阻塞路径：
     * blocking_fail 是"订单/余额推送真的没送到"，任何一次都要查，见 reportBlockingFail()
     */
    protected static $pushStat = ['blocking_fail' => 0];

    /** @var int 上一次写 gateway_send_fail 日志的秒级时刻，见 reportBlockingFail() */
    protected static $blockingFailLoggedAt = 0;

    /** @var int 自上次写日志以来累计的失败次数，见 reportBlockingFail() */
    protected static $blockingFailSinceLog = 0;

    /** 取推送统计；$reset=true 时同时清零 */
    public static function pushStats($reset = false)
    {
        $out = self::$pushStat;
        if ($reset) {
            foreach (self::$pushStat as $k => $_) self::$pushStat[$k] = 0;
        }
        return $out;
    }

    /**
     * 取得可用连接；失败返回 false。
     * 熔断：冷却期内直接判定失败，不再阻塞尝试连接
     * (GatewayWorker 不可用时，逐条消息都等 1 秒 connect 超时会拖垮调用方)
     */
    protected static function ensureClient($address)
    {
        if (isset(self::$instance_client[$address]) && is_resource(self::$instance_client[$address]) && !feof(self::$instance_client[$address])) {
            return self::$instance_client[$address];
        }

        if (isset(self::$failedUntil[$address]) && self::$failedUntil[$address] > microtime(true)) {
            return false;
        }

        $client = @stream_socket_client("tcp://$address", $errno, $errmsg, 1);
        if (!$client) {
            error_log("[GatewayClient] Connect to $address failed: $errmsg ($errno)");
            self::$failedUntil[$address] = microtime(true) + self::CONNECT_FAIL_COOLDOWN;
            unset(self::$instance_client[$address]);
            return false;
        }

        stream_set_timeout($client, 1);
        self::$instance_client[$address] = $client;
        unset(self::$failedUntil[$address]);

        // 认证包必须是这条连接上的第一批字节
        $secretKey = self::$config['secret_key'] ?? '';
        if ($secretKey) {
            @fwrite($client, self::encode([
                'cmd'  => self::CMD_GATEWAY_CLIENT_CONNECT,
                'body' => json_encode(['secret_key' => $secretKey]),
            ]));
        }

        return $client;
    }

    /**
     * 阻塞路径：php-fpm 请求与不可丢推送（订单/余额/绑定/加组）走这里。
     * 写失败后只重试一次且不 usleep、不递归。
     */
    protected static function socketSendBlocking($address, $bin_data, $isRetry = false)
    {
        $client = self::ensureClient($address);
        if ($client === false) return false;

        if (@fwrite($client, $bin_data) === false) {
            @fclose($client);
            unset(self::$instance_client[$address]);
            if ($isRetry) {
                // 重试也失败 = 这条**不可丢**消息真的丢了。
                // 所有调用方(pushSpotOrder / pushBalance / pushContractLiquidation / ...)
                // 都不检查返回值，所以不在这里记一笔的话，gateway 进程重启那几秒里
                // 丢掉的订单成交与余额推送在任何地方都不留痕迹。
                self::reportBlockingFail($address, strlen($bin_data));
                return false;
            }
            // 只重试一次，且不递归：原实现是 usleep(50000) + 调用自身，
            // 在 gateway "能 accept 但已卡死" 时会无限递归下去
            return self::socketSendBlocking($address, $bin_data, true);
        }

        return true;
    }

    /**
     * 记一次「不可丢消息发送失败」。
     *
     * ── 为什么要节流 ──────────────────────────────────────────────────────
     * gateway 一旦挂掉，**每一次**推送都会走到这里，而 business_log 配的是
     * realtime_write(同步 fsync)。逐条写等于把磁盘 IO 直接串进下单主流程 ——
     * 那就把一个"推送丢了"的问题升级成"下单变慢了"。
     * 一秒最多一条 + 带上这一秒里累计了多少条，既看得见也压得住。
     *
     * 事件流水那一份是给监控页用的（后台"行情链路监控"读 MarketMetrics::EVENT_KEY）；
     * 它走 Redis、失败自吞，不能反过来影响推送路径。
     */
    protected static function reportBlockingFail($address, $bytes)
    {
        self::$pushStat['blocking_fail']++;
        self::$blockingFailSinceLog++;

        $now = time();
        if ($now === self::$blockingFailLoggedAt) return;
        $n = self::$blockingFailSinceLog;
        self::$blockingFailLoggedAt = $now;
        self::$blockingFailSinceLog = 0;

        $msg = sprintf(
            '不可丢推送写入失败 address=%s bytes=%d，本秒累计 %d 次（订单/余额/清算/通知类推送已丢失）',
            $address, $bytes, $n
        );

        if (function_exists('business_log')) {
            business_log('gateway_send_fail', $msg, 'error');
        }
        if (class_exists('\app\lib\MarketMetrics')) {
            try {
                \app\lib\MarketMetrics::event('gateway_send_fail', $msg, [
                    'address' => (string)$address,
                    'n'       => $n,
                ]);
            } catch (\Throwable $e) {
                // 观测失败不能反噬推送路径
            }
        }
    }
}
