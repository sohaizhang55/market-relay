<?php

declare(strict_types=1);

namespace app\common\log\driver;

use think\log\driver\File;

/**
 * 自定义 JSON 日志驱动
 * 使得写入的 JSON 记录在一层，不再被 msg 嵌套
 */
class Json extends File
{
    /**
     * 获取主日志文件名
     * @access protected
     * @return string
     */
    protected function getMasterLogFile(): string
    {
        $destination = parent::getMasterLogFile();

        // 当没有定义 single 独立配置，且处于 CLI 命令行环境时，自动追加 _cli 后缀
        // 从而将 Web的 20260404.log 与 CLI的 20260404_cli.log 分离
        if (empty($this->config['single']) && PHP_SAPI == 'cli') {
            return preg_replace('/\.log$/', '_cli.log', $destination);
        }

        return $destination;
    }

    /**
     * 获取独立日志文件名
     * @access protected
     * @param string $path 日志目录
     * @param string $type 日志类型
     * @return string
     */
    protected function getApartLevelFile(string $path, string $type): string
    {
        $destination = parent::getApartLevelFile($path, $type);

        if (empty($this->config['single']) && PHP_SAPI == 'cli') {
            // 将类似 20260404_error.log 替换为 20260404_cli_error.log
            return preg_replace('/_([a-zA-Z0-9]+)\.log$/', '_cli_$1.log', $destination);
        }

        return $destination;
    }

    /**
     * 日志写入接口
     * @access public
     * @param array $log 日志信息
     * @return bool
     */
    public function save(array $log): bool
    {
        $destination = $this->getMasterLogFile();

        $path = dirname($destination);
        !is_dir($path) && mkdir($path, 0755, true);

        $messages = [];

        // 日志信息封装
        foreach ($log as $record) {
            $type = $record->type;
            $msg  = $record->message;
            $time = $record->time->format($this->config['time_format']);

            $filename = $destination;
            if (true === $this->config['apart_level'] || in_array($type, $this->config['apart_level'])) {
                // 独立记录的日志级别
                $filename = $this->getApartLevelFile($path, $type);
            }

            if (!isset($messages[$filename])) {
                $messages[$filename] = [];
            }

            // 自定义组装 flat JSON
            $content = [
                'time' => $time,
                'type' => $type,
                // 'path' => $path,
                // 'destination' => $destination,
                // 'single' => $single,
            ];
            if (is_array($msg)) {
                // 合并数组内容到顶层
                $content = array_merge($content, $msg);
            } else {
                $content['msg'] = $msg;
            }

            $messages[$filename][] = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        foreach ($messages as $filename => $message) {
            $this->write($message, $filename);
        }

        return true;
    }
}
