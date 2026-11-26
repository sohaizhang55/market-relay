<?php

declare(strict_types=1);
/**
 * +----------------------------------------------------------------------
 * | ThinkAdmin Plugin for ThinkAdmin
 * +----------------------------------------------------------------------
 * | 版权所有 2014~2026 ThinkAdmin [ thinkadmin.top ]
 * +----------------------------------------------------------------------
 * | 官方网站: https://thinkadmin.top
 * +----------------------------------------------------------------------
 * | 开源协议 ( https://mit-license.org )
 * | 免责声明 ( https://thinkadmin.top/disclaimer )
 * | 会员特权 ( https://thinkadmin.top/vip-introduce )
 * +----------------------------------------------------------------------
 * | gitee 代码仓库：https://gitee.com/zoujingli/ThinkAdmin
 * | github 代码仓库：https://github.com/zoujingli/ThinkAdmin
 * +----------------------------------------------------------------------
 */
return [
    // 默认日志通道
    'default' => 'file',
    // 日志记录级别
    'level' => [],
    // 日志类型记录的通道
    'type_channel' => [],
    // 关闭全局日志写入
    'close' => false,
    // 全局日志处理 支持闭包
    'processor' => null,
    // 日志通道列表
    'channels' => [
        'file' => [
            // 日志记录方式
            'type' => \app\common\log\driver\Json::class, // json log
            // 'type' => 'File', // text line log
            // 日志保存目录
            'path' => '',
            // 单文件日志写入
            'single' => false,
            // 独立日志级别
            'apart_level' => [],
            // 每个文件大小 ( 200兆 / 200MB )
            'file_size' => 209715200,
            // 日志日期格式 ( 2026-03-27 19:20:30 )
            'time_format' => 'Y-m-d H:i:s',
            // 最大日志文件数量
            'max_files' => 100,
            // 使用JSON格式记录
            'json' => true,
            // 日志处理
            'processor' => null,
            // 日志输出格式化
            'format' => '', // json=true
            // 'format' => '[%s][%s] %s', // json=false
            // 是否实时写入
            'realtime_write' => false,
        ],
    ],
];
