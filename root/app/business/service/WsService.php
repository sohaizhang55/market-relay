<?php

namespace app\business\service;

use GatewayClient\Gateway;

/**
 * Business (Admin) 模块专用 WebSocket 推送服务
 */
class WsService
{
    // 定义统一的消息类型常量，方便业务后期扩展
    const TYPE_NOTICE = 'system_notice';
    const TYPE_ALERT  = 'admin_alert';

    /**
     * 推送管理员系统通知
     */
    public static function pushNotice($uid, $message, $unreadCount = null)
    {
        self::push($uid, self::TYPE_NOTICE, [
            'msg'          => $message,
            'unread_count' => $unreadCount,
            'time'         => date('H:i:s')
        ]);
    }

    /**
     * 向所有在线管理员执行广播推送 (高效率)
     */
    public static function broadcastNoticeToAdmins($message)
    {
        self::pushToAll(self::TYPE_NOTICE, [
            'msg'  => $message,
            'time' => date('H:i:s')
        ]);
    }

    /**
     * 内部统一推送封装 (Admin 专用)
     */
    private static function push($uid, $type, $data)
    {
        $payload = [
            'type' => $type,
            'data' => $data,
        ];
        // 强制使用 admin app 实例进行推送
        Gateway::app('admin')::sendToUid($uid, json_encode($payload));
    }


    /**
     * 内部统一推送封装 (Admin 专用)
     */
    private static function pushToAll($type, $data)
    {
        $payload = [
            'type' => $type,
            'data' => $data,
        ];
        // 强制使用 admin app 实例进行推送
        Gateway::app('admin')::sendToAll(json_encode($payload));
    }

}
