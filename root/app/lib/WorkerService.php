<?php

namespace app\lib;

/**
 * Workerman 统一加载与配置服务
 */
class WorkerService
{
    /**
     * 初始化外部 Workerman 依赖并设置全局配置
     */
    public static function init(string $name = 'workerman')
    {
        $autoload = __DIR__ . '/../../../Workerman/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        if (class_exists(\Workerman\Worker::class)) {
            \Workerman\Worker::$pidFile    = runtime_path() . $name . '.pid';
            \Workerman\Worker::$logFile    = runtime_path() . 'log/' . $name . '.log';
            \Workerman\Worker::$stdoutFile = runtime_path() . 'log/' . $name . '_stdout.log';
        }
    }
}
