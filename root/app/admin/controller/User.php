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

namespace app\admin\controller;

use think\admin\Controller;

use think\admin\helper\QueryHelper;
use think\admin\model\SystemAuth;
use think\admin\model\SystemBase;
use think\admin\model\SystemUser;
use think\admin\service\AdminService;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use app\lib\GoogleAuthenticator;

/**
 * 系统用户管理.
 * @class User
 */
class User extends Controller
{
    /**
     * 系统用户管理.
     * @auth true
     * @menu true
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function index()
    {
        $this->type = $this->get['type'] ?? 'index';
        SystemUser::mQuery()->layTable(function () {
            $this->title = '系统用户管理';
            $this->bases = SystemBase::items('身份权限');
        }, function (QueryHelper $query) {
            // 加载对应数据列表
            $query->where(['is_deleted' => 0, 'status' => intval($this->type === 'index')]);

            // 关联用户身份资料
            /* @var \think\model\Relation|\think\db\Query $query */
            $query->with(['userinfo' => static function ($query) {
                $query->field('code,name,content');
            }]);

            // 数据列表搜索过滤
            $query->equal('status,usertype')->dateBetween('login_at,create_at');
            $query->like('username|nickname#username,contact_phone#phone,contact_mail#mail');
        });
    }

    /**
     * 添加系统用户.
     * @auth true
     */
    public function add()
    {
        SystemUser::mForm('form');
    }

    /**
     * 编辑系统用户.
     * @auth true
     */
    public function edit()
    {
        $this->_checkInput();
        SystemUser::mForm('form');
    }

    /**
     * 修改用户密码
     * @auth true
     */
    public function pass()
    {
        $this->_checkInput();
        $this->_applyFormToken();
        if ($this->request->isGet()) {
            $this->verify = false;
            SystemUser::mForm('pass');
        } else {
            $data = $this->_vali([
                'id.require' => '用户ID不能为空！',
                'password.require' => '登录密码不能为空！',
                'repassword.require' => '重复密码不能为空！',
                'repassword.confirm:password' => '两次输入的密码不一致！',
            ]);
            $user = SystemUser::mk()->findOrEmpty($data['id']);
            if ($user->isExists() && $user->save(['password' => md5($data['password'])])) {
                // 修改密码同步事件处理
                $this->app->event->trigger('PluginAdminChangePassword', [
                    'uuid' => $data['id'],
                    'pass' => $data['password'],
                ]);
                sysoplog('系统用户管理', "修改用户[{$data['id']}]密码成功");
                $this->success('密码修改成功，请使用新密码登录！', '');
            } else {
                $this->error('密码修改失败，请稍候再试！');
            }
        }
    }

    /**
     * 修改用户状态
     * @auth true
     */
    public function state()
    {
        $this->_checkInput();
        SystemUser::mSave($this->_vali([
            'status.in:0,1' => '状态值范围异常！',
            'status.require' => '状态值不能为空！',
        ]));
    }

    /**
     * 删除系统用户.
     * @auth true
     */
    public function remove()
    {
        $this->_checkInput();
        SystemUser::mDelete();
    }

    /**
     * Google 2FA 绑定验证
     * @auth true
     */
    public function google()
    {
        $id = AdminService::getUserId();
        if (empty($id)) $this->error('用户登录超时！');

        $user = SystemUser::mk()->findOrEmpty($id);
        if ($user->isEmpty()) $this->error('用户不存在！');

        if ($this->request->isGet()) {
            $google = new GoogleAuthenticator();
            // 如果已绑定，则显示解绑逻辑，否则显示绑定逻辑
            if ($user->getAttr('google_auth_secret')) {
                $this->is_bind = true;
            } else {
                $this->is_bind = false;
                // 从 session 获取临时密钥，防止刷新页面导致二维码更换
                $sessKey = "google_auth_secret_{$id}";
                $secret = session($sessKey) ?: $google->createSecret();
                session($sessKey, $secret);

                $this->secret = $secret;
                $this->qrcode = $google->getQRCodeGoogleUrl($user->getAttr('username'), $secret, sysconf('site_name') ?: 'market-relay');
            }
            $this->fetch();
        } else {
            $code = input('code', '');
            if (empty($code)) $this->error('动态验证码不能为空！');

            $google = new GoogleAuthenticator();
            $sessKey = "google_auth_secret_{$id}";
            $action = input('action', '');

            if ($action === 'unbind') {
                // 解绑逻辑：需要验证当前动态码
                $secret = decrypt_key($user->getAttr('google_auth_secret'));
                if ($google->verifyCode($secret, $code, 1)) {
                    $user->save(['google_auth_secret' => '']);
                    sysoplog('系统用户管理', "用户[{$id}]解绑 Google 2FA 成功");
                    $this->success('解绑成功！', '');
                }
                $this->error('验证码错误，解绑失败！');
            } else {
                // 绑定逻辑
                $secret = session($sessKey);
                if (empty($secret)) $this->error('操作超时，请重新扫码！');

                if ($google->verifyCode($secret, $code, 1)) {
                    // 加密存储密钥
                    $user->save(['google_auth_secret' => encrypt_key($secret)]);
                    session($sessKey, null);
                    sysoplog('系统用户管理', "用户[{$id}]绑定 Google 2FA 成功");
                    $this->success('绑定成功！', '');
                }
                $this->error('验证码错误，绑定失败！');
            }
        }
    }

    /**
     * 表单数据处理.
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    protected function _form_filter(array &$data)
    {
        if ($this->request->isPost()) {
            // 检查资料是否完整
            empty($data['username']) && $this->error('登录账号不能为空！');
            if ($data['username'] !== AdminService::getSuperName()) {
                empty($data['authorize']) && $this->error('未配置权限！');
            }
            // 处理上传的权限格式
            $data['authorize'] = arr2str($data['authorize'] ?? []);
            if (empty($data['id'])) {
                // 检查账号是否重复
                $map = ['username' => $data['username'], 'is_deleted' => 0];
                if (SystemUser::mk()->where($map)->count() > 0) {
                    $this->error('账号已经存在，请使用其它账号！');
                }
                // 新添加的用户密码与账号相同
                $data['password'] = md5($data['username']);
            } else {
                unset($data['username']);
            }
        } else {
            // 权限绑定处理
            $data['authorize'] = str2arr($data['authorize'] ?? '');
            $this->auths = SystemAuth::items();
            $this->bases = SystemBase::items('身份权限');
            $this->super = AdminService::getSuperName();
        }
    }

    /**
     * 检查输入变量.
     */
    private function _checkInput()
    {
        $ids = str2arr(input('id', ''));
        if (in_array('10000', $ids)) {
            $this->error('系统超级账号禁止操作！');
        }
        if (in_array(strval(AdminService::getUserId()), $ids)) {
            $this->error('不能在用户管理中操作自己的账号！');
        }
    }
}
