<?php

declare(strict_types=1);

namespace app\business\controller;

use app\enum\CommonStatusEnum;
use think\admin\Controller;
use think\admin\helper\QueryHelper;
use think\facade\Db;

/**
 * 产品类型管理
 * 币种 (coin_list.category_code) 归属的类型，前端按交易类型 → 产品类型 → 产品分组展示
 */
class CoinCategory extends Controller
{
    /**
     * 绑定数据表
     * @var string
     */
    protected $dbTable = 'coin_category';

    /**
     * 产品类型列表
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '产品类型管理';
        $this->_query($this->dbTable)->layTable(function () {
            $this->statusList = CommonStatusEnum::labels();
        }, function (QueryHelper $query) {
            $query->like('title|code#name')->equal('status');
        });
    }

    /**
     * 添加产品类型
     * @auth true
     */
    public function add()
    {
        $this->title = '添加产品类型';
        $this->_form($this->dbTable, 'form');
    }

    /**
     * 编辑产品类型
     * @auth true
     */
    public function edit()
    {
        $this->title = '编辑产品类型';
        $this->_form($this->dbTable, 'form');
    }

    /**
     * 修改产品类型状态
     * @auth true
     */
    public function state()
    {
        $this->_save($this->dbTable);
    }

    /**
     * 表单数据处理
     * @param array $data
     */
    protected function _form_filter(array &$data)
    {
        if ($this->request->isGet()) {
            $data['title_lang'] = json_decode($data['title_langs'] ?? '', true) ?: [];
            $data['status'] = isset($data['status']) ? (int)$data['status'] : CommonStatusEnum::ON->value;
            $this->langList = get_lang_list();
            $this->statusList = CommonStatusEnum::labels();
        }

        if ($this->request->isPost()) {
            $data['code'] = strtolower(trim((string)($data['code'] ?? '')));
            if ($data['code'] === '' || !preg_match('/^[a-z][a-z0-9_]{1,19}$/', $data['code'])) {
                $this->error('类型代码须为 2-20 位小写字母、数字或下划线，且以字母开头！');
            }
            if (empty($data['title'])) $this->error('类型标题不能为空！');

            $map = [['code', '=', $data['code']]];
            if (!empty($data['id'])) $map[] = ['id', '<>', $data['id']];
            if (Db::name($this->dbTable)->where($map)->count() > 0) {
                $this->error('类型代码已存在！');
            }

            // 已被引用的类型不允许改 code，否则 coin_list / market_data 的 category_code 会悬空
            if (!empty($data['id'])) {
                $oldCode = Db::name($this->dbTable)->where('id', $data['id'])->value('code');
                if ($oldCode !== null && $oldCode !== $data['code']) {
                    if (Db::name('coin_list')->where('category_code', $oldCode)->count() > 0) {
                        $this->error('该类型下已有币种，不能修改类型代码！');
                    }
                    if (Db::name('market_data')->where('category_code', $oldCode)->count() > 0) {
                        $this->error('该类型下已有行情源，不能修改类型代码！');
                    }
                }
            }

            $langsMap = array_filter(
                (array)($data['title_lang'] ?? []),
                fn($v) => trim((string)$v) !== ''
            );
            $data['title_langs'] = !empty($langsMap) ? json_encode($langsMap, JSON_UNESCAPED_UNICODE) : null;
            unset($data['title_lang']);

            if (empty($data['id'])) {
                $data['create_ts'] = time();
            }
            $data['update_ts'] = time();
        }
    }
}
