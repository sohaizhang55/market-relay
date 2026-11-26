<?php

declare(strict_types=1);

namespace app\business\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 业务后台操作日志中间件
 * @class OperationLog
 * @package app\business\middleware
 */
class OperationLog
{
    /**
     * 处理请求
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // 只记录 POST 请求，避免 GET 请求塞满日志
        if ($request->isPost()) {
            $this->record($request);
        }

        return $response;
    }

    /**
     * 记录操作日志
     * @param Request $request
     */
    protected function record(Request $request)
    {
        $app = app()->http->getName();
        $controller = $request->controller();
        $action = $request->action();

        // 尝试通过反射获取方法注释作为日志行为名称
        $title = $this->getActionTitle($app, $controller, $action);

        // 整理日志详情
        $content = [
            'url'    => $request->url(),
            'method' => $request->method(),
            'ip'     => $request->ip(),
            'param'  => $request->post(),
        ];

        // 调用系统日志函数
        sysoplog($title, json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // --- 临时测试：操作行为触发实时通知推送 ---
        // 排除掉自动化的 API 请求，防止循环推送
        $exclude = ['api.notice', 'api.task'];
        if (($uid = session('user.id')) && !in_array(strtolower($controller), $exclude)) {
            \app\business\service\AdminNoticeService::instance()->push(
                (int)$uid,
                "操作提醒: {$title}",
                "你刚才在 " . date('H:i:s') . " 执行了: " . $request->url(),
                'info'
            );
        }
    }

    /**
     * 获取操作标题
     * @param string $app 模块名
     * @param string $controller 控制器名
     * @param string $action 方法名
     * @return string
     */
    protected function getActionTitle(string $app, string $controller, string $action): string
    {
        try {
            // 获取类名
            $className = "app\\{$app}\\controller\\" . str_replace('.', '\\', $controller);
            if (!class_exists($className)) return "{$controller}/{$action}";

            $reflect = new \ReflectionMethod($className, $action);
            $comment = $reflect->getDocComment();

            if ($comment) {
                // 简单正则提取第一行有效文字
                if (preg_match('/\* ([^@\s\*]+)/u', $comment, $matches)) {
                    return trim($matches[1]);
                }
            }
        } catch (\Throwable $e) {
            // 反射失败回退到节点名称
        }

        return "{$controller}/{$action}";
    }
}
