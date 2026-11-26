<?php

namespace app\model;

use think\Model;

/**
 * 应用用户模型
 * Class AppUsers
 * @package app\model
 */
class AppUsers extends Model
{
    // 指定表名
    protected $table = 'app_users';

    // 自动时间戳
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_at';
    protected $updateTime = 'update_at';

    /**
     * 关联推荐人
     * @return \think\model\relation\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(static::class, 'parent_id')->bind([
            'parent_username' => 'username',
            'parent_nickname' => 'nickname',
        ]);
    }
}
