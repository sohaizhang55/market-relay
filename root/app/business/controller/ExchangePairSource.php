<?php

declare(strict_types=1);

namespace app\business\controller;

use think\admin\Controller;
use think\admin\helper\QueryHelper;
use think\admin\service\AdminService;
use think\facade\Db;
use app\business\service\PairSourceService;
use app\enum\CommonStatusEnum;
use app\enum\MarketChannelEnum;
use app\enum\MarketDataTypeEnum;
use app\enum\MarketExchangeEnum;
use app\lib\MarketPublisher;
use app\lib\MarketSourceHealth;

/**
 * 交易对数据源绑定（主备切换的配置）
 *
 * 负责  exchange_pair_source 的增改删、启停，以及手动把交易对切到某个已绑定的源
 * 不负责  自动选源（选源进程的事）、源本身的断流阈值（原始行情页的事）
 *
 * 从「交易对」页每行的「数据源」按钮进入，按 pair_id 只看一个交易对的绑定。三条保护：
 *   · 当前生效源（exchange_pair.source_key）不能停用、不能删除：否则交易对正在用一个
 *     「没绑定」的源，选源进程按绑定选源时永远切不回它，要先切走再动
 *   · 同一交易对的源类型必须一致：合约交易对只能绑合约源，资金费率与标记价格才有意义
 *   · 同一交易对的优先级不能重复：重复时谁先谁后取决于查询顺序，等于没配
 * 绑定的源一旦建立不能改（编辑只改优先级、状态、备注），要换源就删掉重建。
 * @class ExchangePairSource
 */
class ExchangePairSource extends Controller
{
    private string $table = 'exchange_pair_source';

    /**
     * 某个交易对的数据源绑定
     * @auth true
     */
    public function index()
    {
        $pair = $this->pairOrFail((int)input('pair_id'));
        $this->pairId = (int)$pair['id'];
        $this->title  = '数据源 - ' . MarketPublisher::pairKey((string)$pair['symbol_code'], (int)$pair['type']);
        $this->_query($this->table)->layTable(function () {
        }, function (QueryHelper $query) use ($pair) {
            $query->where(['pair_id' => (int)$pair['id']])->order('priority asc, id asc');
        });
    }

    /**
     * 列表数据处理：所有文字与颜色在这里映射，模板只消费（Coding_Standards §10）
     * @param array $data
     */
    protected function _index_page_filter(array &$data)
    {
        $pair      = $this->pairOrFail((int)input('pair_id'));
        $active    = (string)($pair['source_key'] ?? '');
        $sources   = Db::name('market_data')->whereIn('source_key', array_column($data, 'source_key'))
            ->column('exchange, type, ticker_stale_sec, depth_stale_sec', 'source_key');
        $exchanges = MarketExchangeEnum::labels();
        $types     = MarketDataTypeEnum::labels();
        $wsPrice   = MarketSourceHealth::wsTsMap(MarketChannelEnum::PRICE);
        $wsDepth   = MarketSourceHealth::wsTsMap(MarketChannelEnum::DEPTH);
        $nowMs     = (int)(microtime(true) * 1000);
        $first     = true;

        foreach ($data as &$vo) {
            $sk  = (string)$vo['source_key'];
            $src = $sources[$sk] ?? null;

            // 排在第一的启用绑定就是主源；停用的不算
            $on = (int)$vo['status'] === CommonStatusEnum::ON->value;
            $vo['role_text']  = $on && $first ? '主源' : '备源';
            $vo['role_color'] = $on && $first ? 'layui-bg-blue' : 'layui-bg-gray';
            if ($on) $first = false;

            $vo['is_active']    = $sk === $active;
            $vo['active_text']  = $vo['is_active'] ? '当前在用' : '';
            $vo['status_on']    = $on;

            if ($src === null) {
                // 绑定的源在 market_data 里已经没有了（被删掉），这一行永远不会有行情
                $vo['source_text']  = $sk . ' ⚠ 源已不存在';
                $vo['source_color'] = 'color-red';
                $vo['exchange_text'] = $vo['type_text'] = '-';
                $vo['price_fresh_text'] = $vo['depth_fresh_text'] = '-';
                $vo['price_fresh_color'] = $vo['depth_fresh_color'] = 'color-desc';
                $vo['price_fresh_title'] = $vo['depth_fresh_title'] = '';
                $vo['ticker_stale_sec'] = $vo['depth_stale_sec'] = '-';
                continue;
            }

            $vo['source_text']   = $sk;
            $vo['source_color']  = '';
            $vo['exchange_text'] = $exchanges[$src['exchange']] ?? $src['exchange'];
            $vo['type_text']     = $types[(int)$src['type']] ?? (string)$src['type'];
            $vo['ticker_stale_sec'] = (int)$src['ticker_stale_sec'];
            $vo['depth_stale_sec']  = (int)$src['depth_stale_sec'];
            [$vo['price_fresh_text'], $vo['price_fresh_color'], $vo['price_fresh_title']]
                = MarketSourceHealth::label($wsPrice[$sk] ?? 0, (int)$src['ticker_stale_sec'], $nowMs);
            [$vo['depth_fresh_text'], $vo['depth_fresh_color'], $vo['depth_fresh_title']]
                = MarketSourceHealth::label($wsDepth[$sk] ?? 0, (int)$src['depth_stale_sec'], $nowMs);
        }
    }

    /**
     * 添加或编辑绑定
     * @auth true
     */
    public function add()
    {
        $this->title = input('id') ? '编辑数据源' : '添加数据源';
        $this->_form($this->table, 'form');
    }

    /**
     * 绑定表单。GET 准备下拉，POST 按白名单重建 $data 并校验三条保护规则
     * @param array $data
     */
    protected function _add_form_filter(array &$data)
    {
        $id  = (int)($data['id'] ?? 0);
        $row = $id > 0 ? Db::name($this->table)->where('id', $id)->find() : null;
        if ($id > 0 && !$row) $this->error('绑定不存在！');

        // 编辑时交易对与源都以库里为准，表单传什么都不认
        $pairId = $row ? (int)$row['pair_id'] : (int)($data['pair_id'] ?? input('pair_id'));
        $pair   = $this->pairOrFail($pairId);

        if ($this->request->isGet()) {
            $data['pair_id'] = $pairId;
            if (!$row) {
                $data['priority'] = (int)Db::name($this->table)->where('pair_id', $pairId)->max('priority') + 1;
                $data['status']   = CommonStatusEnum::ON->value;
            }
            $this->isEdit   = (bool)$row;
            $this->pairKey  = MarketPublisher::pairKey((string)$pair['symbol_code'], (int)$pair['type']);
            $this->sources  = $row ? [] : $this->sourceOptions($pairId);
            $this->statuses = CommonStatusEnum::labels();
            return;
        }

        $sourceKey = $row ? (string)$row['source_key'] : trim((string)($data['source_key'] ?? ''));
        if (!$row) {
            if (!isset($this->sourceOptions($pairId)[$sourceKey])) {
                $this->error('数据源不可选：不存在、已经绑定过，或与该交易对已有源的类型不一致！');
            }
        }

        $priority = trim((string)($data['priority'] ?? ''));
        if (!ctype_digit($priority) || (int)$priority < 1 || (int)$priority > 999) {
            $this->error('优先级必须是 1 ~ 999 的整数，越小越优先！');
        }
        $dup = Db::name($this->table)->where('pair_id', $pairId)->where('priority', (int)$priority)
            ->where('id', '<>', $id)->value('source_key');
        if ($dup) $this->error("优先级 {$priority} 已被 {$dup} 占用，同一交易对的优先级不能重复！");

        $status = (int)($data['status'] ?? 0);
        if (CommonStatusEnum::tryFrom($status) === null) $this->error('请选择状态！');
        if ($status === CommonStatusEnum::OFF->value && $sourceKey === (string)$pair['source_key']) {
            $this->error('这是交易对当前在用的源，不能停用。请先把交易对切到别的源！');
        }

        $data = [
            'pair_id'    => $pairId,
            'source_key' => $sourceKey,
            'priority'   => (int)$priority,
            'status'     => $status,
            'remark'     => mb_substr(trim((string)($data['remark'] ?? '')), 0, 255),
            'update_ts'  => time(),
        ];
        if ($row) {
            $data['id'] = $id;
        } else {
            $data['create_ts'] = time();
        }
    }

    /**
     * 启用 / 停用绑定
     * @auth true
     */
    public function state()
    {
        $status = (int)$this->request->post('status', 0);
        if (CommonStatusEnum::tryFrom($status) === null) {
            $this->error('状态值非法，只接受 1(启用) 或 2(停用)！');
        }
        $this->_save($this->table);
    }

    /**
     * 停用前挡掉当前在用的源
     * @param mixed $query
     * @param array $data
     */
    protected function _state_save_filter($query, array &$data)
    {
        if ((int)($data['status'] ?? 0) !== CommonStatusEnum::OFF->value) return;
        foreach (Db::name($this->table)->whereIn('id', str2arr((string)$this->request->post('id', '')))->select() as $row) {
            if ($this->isActive($row)) {
                $this->error("{$row['source_key']} 是交易对当前在用的源，不能停用。请先把交易对切到别的源！");
            }
        }
        $data['update_ts'] = time();
    }

    /**
     * 删除绑定
     * @auth true
     */
    public function remove()
    {
        $this->_delete($this->table);
    }

    /**
     * 删除前挡掉当前在用的源
     * @param mixed $query
     */
    protected function _remove_delete_filter($query)
    {
        // $query 是 DeleteHelper 包出来的模型查询，select() 出来是模型对象；按提交的 id 直接查表拿数组
        foreach (Db::name($this->table)->whereIn('id', str2arr((string)$this->request->post('id', '')))->select() as $row) {
            if ($this->isActive($row)) {
                $this->error("{$row['source_key']} 是交易对当前在用的源，不能删除。请先把交易对切到别的源！");
            }
        }
    }

    /**
     * 手动把交易对切到这条绑定的源
     *
     * 用于交易所公告维护前提前切走等场景。选源进程上线后，自动切换会按健康状态再改回来；
     * 想长期固定在某个源，应调整优先级而不是只切一下。
     * @auth true
     */
    public function activate()
    {
        $row = Db::name($this->table)->where('id', (int)$this->request->post('id'))->find();
        if (!$row) $this->error('绑定不存在！');
        if ((int)$row['status'] !== CommonStatusEnum::ON->value) $this->error('停用的源不能设为生效源，请先启用！');

        $ok = PairSourceService::switchTo((int)$row['pair_id'], (string)$row['source_key'],
            '后台手动切换（' . AdminService::getUserName() . '）');
        $ok ? $this->success('已切换，各采集进程约 3 秒内生效！') : $this->error('切换失败：交易对或绑定不存在！');
    }

    /** 交易对不存在就直接报错返回 */
    private function pairOrFail(int $pairId): array
    {
        $pair = $pairId > 0 ? Db::name('exchange_pair')->where('id', $pairId)->field('id, symbol_code, type, source_key')->find() : null;
        if (!$pair) $this->error('交易对不存在！');
        return $pair;
    }

    /** 这条绑定是不是它所属交易对当前在用的源 */
    private function isActive(array $row): bool
    {
        return (string)Db::name('exchange_pair')->where('id', (int)$row['pair_id'])->value('source_key') === (string)$row['source_key'];
    }

    /**
     * 新增绑定时可选的源：market_data 里还没绑到这个交易对的；交易对已有绑定时，只列同类型的。
     *
     * @return array<string,string> source_key => 下拉显示文字
     */
    private function sourceOptions(int $pairId): array
    {
        $bound = Db::name($this->table)->where('pair_id', $pairId)->column('source_key');
        $query = Db::name('market_data')->field('source_key, exchange, type')->order('exchange asc, source_key asc');
        if ($bound) {
            $types = Db::name('market_data')->whereIn('source_key', $bound)->column('type');
            if ($types) $query->whereIn('type', array_unique($types));
            $query->whereNotIn('source_key', $bound);
        }

        $exchanges = MarketExchangeEnum::labels();
        $typeNames = MarketDataTypeEnum::labels();
        $out = [];
        foreach ($query->limit(2000)->select() as $r) {
            $out[$r['source_key']] = sprintf('%s（%s · %s）', $r['source_key'],
                $exchanges[$r['exchange']] ?? $r['exchange'], $typeNames[(int)$r['type']] ?? $r['type']);
        }
        return $out;
    }
}
