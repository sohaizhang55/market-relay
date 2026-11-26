<?php

declare(strict_types=1);

/**
 * 内部接口模块中间件配置：所有 /inner_api/* 接口统一鉴权，见 InnerAuth
 */
return [
    \app\inner_api\middleware\InnerAuth::class,
];
