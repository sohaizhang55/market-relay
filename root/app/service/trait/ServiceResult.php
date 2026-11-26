<?php

namespace app\service\trait;

/**
 * Service 统一返回格式 Trait
 */
trait ServiceResult
{
    /**
     * 返回成功格式
     * @param array|object $data 返回数据
     * @param mixed $msg 语言包 Key 或 [Key, Vars]
     * @return array [bool $status, mixed $msg, mixed $data]
     */
    protected function toSuccess($data = [], $msg = 'success'): array
    {
        return [true, $msg, $data];
    }

    /**
     * 返回失败格式
     * @param mixed $msg 语言包 Key 或 [Key, Vars]
     * @param array|object $data 额外数据
     * @return array [bool $status, mixed $msg, mixed $data]
     */
    protected function toError($msg = 'error', $data = []): array
    {
        return [false, $msg, $data];
    }
}
