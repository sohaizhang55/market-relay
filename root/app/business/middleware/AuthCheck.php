<?php

declare(strict_types=1);

namespace app\business\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 后台登录鉴权中间件
 */
class AuthCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        $isLogged = (bool)session('user.id');

        if (!$isLogged) {
            $loginUrl = url('/admin/login')->build();

            // AJAX 请求返回 JSON，由前端 JS 处理跳转
            if ($request->isAjax()) {
                return Response::create([
                    'code' => 0,
                    'info' => '登录超时，请重新登录',
                    'url'  => $loginUrl,
                ], 'json', 401);
            }

            // 普通请求直接跳转登录页
            return redirect($loginUrl);
        }

        return $next($request);
    }
}
