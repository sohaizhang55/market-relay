<?php

declare(strict_types=1);

/**
 * 业务模块中间件配置
 */
return [
    // 登录鉴权 (必须在最前)
    \app\business\middleware\AuthCheck::class,
    // 全局操作日志审计
    \app\business\middleware\OperationLog::class,
];
