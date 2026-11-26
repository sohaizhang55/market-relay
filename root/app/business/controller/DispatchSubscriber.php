<?php

declare(strict_types=1);

namespace app\business\controller;

use app\business\service\DispatchSubscriberService;
use app\enum\CommonStatusEnum;
use think\admin\helper\QueryHelper;

/**
 * 订阅方管理：给每个订阅方发 token，管分发鉴权开关，看订阅方最后从哪个 IP 来
 *
 * 订阅方在 WS 握手头与 /inner_api/* 请求头带 X-Inner-Token。任何改动都立即重写 Redis 快照
 * （DispatchSubscriberService::publish()），分发进程与 inner_api 最长 5 秒内生效，不用重启。
 * 最后来源 IP 先记在 Redis，列表页直接读；market:subscriber_sync 每 60 秒落库一次。
 */
class DispatchSubscriber extends Base
{
    protected $dbTable = 'dispatch_subscriber';

    /**
     * 订阅方管理
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '订阅方管理';
        $this->authOn = DispatchSubscriberService::instance()->authEnabled();
        $this->_query($this->dbTable)->order('id desc')
            ->layTable(function () {
            }, function (QueryHelper $query) {
                $query->like('name')->equal('status');
            });
    }

    /** 列表映射：token 打码、启停、最后来源（Redis 里比库里新的优先） */
    protected function _index_page_filter(array &$data)
    {
        $seen = DispatchSubscriberService::instance()->seenMap();
        foreach ($data as &$vo) {
            $token = (string)$vo['token'];
            // 页面只显示打码，完整 token 留在行数据里给「复制」按钮用（本页有 @auth 权限控制）
            $vo['token_text'] = strlen($token) > 10 ? substr($token, 0, 6) . '****' . substr($token, -4) : '****';
            $vo['status_on'] = (int)$vo['status'] === CommonStatusEnum::ON->value;
            foreach (['ws', 'api'] as $via) {
                $ip = (string)$vo["last_{$via}_ip"];
                $ts = (int)$vo["last_{$via}_ts"];
                $live = $seen[(int)$vo['id']][$via] ?? null;
                if ($live !== null && $live['ts'] > $ts) {
                    $ip = $live['ip'];
                    $ts = $live['ts'];
                }
                // 只给文字与颜色 class，标签在模板里拼：layui 表格会把数据里的 HTML 转义成纯文本
                $vo["last_{$via}_text"]  = $ts > 0 ? $ip : '从未';
                $vo["last_{$via}_color"] = $ts > 0 ? '' : 'color-desc';
                $vo["last_{$via}_time"]  = $ts > 0 ? date('m-d H:i:s', $ts) : '';
            }
        }
        unset($vo);
    }

    /**
     * 添加订阅方
     * @auth true
     */
    public function add()
    {
        $this->title = '添加订阅方';
        $this->_form($this->dbTable, 'form');
    }

    /**
     * 编辑订阅方
     * @auth true
     */
    public function edit()
    {
        $this->title = '编辑订阅方';
        $this->_form($this->dbTable, 'form');
    }

    /**
     * 启停订阅方
     * @auth true
     */
    public function state()
    {
        $this->_save($this->dbTable);
    }

    /**
     * 删除订阅方
     * @auth true
     */
    public function remove()
    {
        $this->_delete($this->dbTable);
    }

    /**
     * 重置订阅方 token，旧 token 立即作废
     * @auth true
     */
    public function reset()
    {
        $id = (int)$this->request->post('id', 0);
        $name = $this->app->db->name($this->dbTable)->where('id', $id)->value('name');
        if ($name === null) $this->error('订阅方不存在！');

        $svc = DispatchSubscriberService::instance();
        $this->app->db->name($this->dbTable)->where('id', $id)->update([
            'token' => $svc->genToken(), 'update_at' => date('Y-m-d H:i:s'), 'update_ts' => time(),
        ]);
        [$ok, $msg] = $svc->publish();
        sysoplog('行情分发', "重置订阅方「{$name}」(id={$id}) 的 token");
        $ok ? $this->success('token 已重置，旧 token 最长 5 秒后失效。请在列表里复制新 token 给订阅方。')
            : $this->error("token 已重置，但{$msg}，60 秒内由 market:subscriber_sync 补发");
    }

    /**
     * 分发鉴权开关
     * @auth true
     */
    public function setting()
    {
        $this->_applyFormToken();
        $svc = DispatchSubscriberService::instance();

        if ($this->request->isGet()) {
            $this->title    = '分发鉴权开关';
            $this->statuses = CommonStatusEnum::labels();
            $this->current  = $svc->authEnabled() ? CommonStatusEnum::ON->value : CommonStatusEnum::OFF->value;
            return $this->fetch();
        }

        // 字段名不带点号：PHP 会把 $_POST 键里的 . 转成 _
        $status = (int)$this->request->post('auth_enabled', 0);
        [$ok, $msg] = $svc->setAuthEnabled($status);
        if (!$ok) $this->error($msg);
        $label = $status === CommonStatusEnum::ON->value ? '开启' : '关闭';
        sysoplog('行情分发', "修改分发鉴权开关为「{$label}」");
        $this->success("保存成功，分发鉴权已{$label}，最长 5 秒生效。");
    }

    /** 表单：新增时生成 token；token 与最后来源只读，提交里带了也丢掉 */
    protected function _form_filter(array &$data)
    {
        if ($this->request->isPost()) {
            $data['name'] = trim((string)($data['name'] ?? ''));
            if ($data['name'] === '') $this->error('订阅方名称不能为空！');
            foreach (['token', 'last_ws_ip', 'last_ws_at', 'last_ws_ts', 'last_api_ip', 'last_api_at', 'last_api_ts'] as $k) {
                unset($data[$k]);
            }
            $data['update_at'] = date('Y-m-d H:i:s');
            $data['update_ts'] = time();
            if (empty($data['id'])) {
                $data['token'] = DispatchSubscriberService::instance()->genToken();
                $data['create_ts'] = time();
            }
        } else {
            $this->statuses = CommonStatusEnum::labels();
        }
    }

    protected function _form_result(bool $result, array $data = [])
    {
        if ($result === false) return;
        DispatchSubscriberService::instance()->publish();
        sysoplog('行情分发', (empty($data['id']) ? '添加' : '编辑') . "订阅方「{$data['name']}」");
    }

    protected function _save_result(bool $result)
    {
        if ($result === false) return;
        DispatchSubscriberService::instance()->publish();
        sysoplog('行情分发', sprintf('订阅方 id=%s 改为「%s」', $this->request->post('id', ''),
            CommonStatusEnum::labels()[(int)$this->request->post('status', 0)] ?? '-'));
    }

    protected function _delete_result(bool $result)
    {
        if ($result === false) return;
        DispatchSubscriberService::instance()->publish();
        sysoplog('行情分发', '删除订阅方 id=' . $this->request->post('id', ''));
    }
}
