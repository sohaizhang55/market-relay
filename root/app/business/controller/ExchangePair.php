<?php

declare(strict_types=1);

namespace app\business\controller;

use think\admin\Controller;
use think\admin\helper\QueryHelper;
use think\admin\service\AdminService;
use app\business\service\MarketService;
use app\command\MarketDispatcher;
use app\command\MarketFunding;
use app\command\MarketSourceSelect;
use app\enum\CommonStatusEnum;
use app\enum\MarketDataTypeEnum;
use app\lib\MarketPublisher;
use app\lib\MarketWireProtocol;

/**
 * 交易对 (pair) 查看
 *
 * 采集到的源层行情按 exchange_pair.source_key 映射到 pair，
 * 订阅方按 {symbol_code}@{stream} 订阅、按帧里的 pair（{symbol_code}_{type}）对号入座。本页看的就是这一层的当前值，
 * 即 market:dispatch 正在分发出去的数据。交易对可在这里新增、编辑（规则见「新增 / 编辑」一节），不提供删除；
 * 每个交易对的数据源（主源 / 备源）在「数据源」按钮里管理，见 ExchangePairSource。
 * @class ExchangePair
 */
class ExchangePair extends Controller
{
    /**
     * 交易对列表
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '交易对';
        $this->types = MarketDataTypeEnum::labels();
        $this->statuses = CommonStatusEnum::labels();
        $this->_query('exchange_pair')->layTable(function () {
        }, function (QueryHelper $query) {
            $query->like('symbol_code,source_key')->equal('type,status');
            $query->order('type asc, sort desc, id asc');
        });
    }

    // -------------------------------------------------------------------------
    // 新增 / 编辑
    //
    // symbol_code 与 type 只在新增时填，之后锁死：两者拼成的 pair（btcusdt_2）是跨机身份，
    // 下游业务平台与订阅方都按它对号入座，改了等于凭空换成另一个交易对。
    // 数据源只能从 market_data 已有的源里选（新源仍走 database/market_data.sql + 重启采集器）；
    // 新增后要换源、加备源走每行的「数据源」。不提供删除：K 线等数据都按 pair_id 挂在它下面。
    // -------------------------------------------------------------------------

    /** 价格 / 数量精度上限，exchange_pair 金额列是 decimal(30,10) */
    private const PRECISION_MAX = 10;

    /**
     * 新增交易对
     * @auth true
     */
    public function add()
    {
        $this->title = '新增交易对';
        $this->_form('exchange_pair', 'form');
    }

    /**
     * 编辑交易对
     * @auth true
     */
    public function edit()
    {
        $this->title = '编辑交易对';
        $this->_form('exchange_pair', 'form');
    }

    /**
     * 新增 / 编辑表单。GET 准备下拉；POST 自己校验并落库（新增要和主源绑定同一个事务），不走框架的默认保存
     * @param array $data
     */
    protected function _form_filter(array &$data)
    {
        $id  = (int)($data['id'] ?? 0);
        $row = $id > 0 ? \think\facade\Db::name('exchange_pair')->where('id', $id)->find() : null;
        if ($id > 0 && !$row) $this->error('交易对不存在！');

        if ($this->request->isGet()) {
            $this->isEdit   = (bool)$row;
            $this->types    = MarketDataTypeEnum::labels();
            $this->statuses = CommonStatusEnum::labels();
            $this->sources  = $row ? [] : $this->sourceOptions();
            $this->pairKey  = $row ? MarketPublisher::pairKey((string)$row['symbol_code'], (int)$row['type']) : '';
            $this->typeText = $row ? ($this->types[(int)$row['type']] ?? (string)$row['type']) : '';
            $this->sourceText = $row ? ((string)($row['source_key'] ?? '') ?: '自发币（无数据源）') : '';
            if (!$row) {
                $data['type'] = MarketDataTypeEnum::SWAP->value;
                $data['status'] = CommonStatusEnum::ON->value;
                $data['precision_price'] = 2;
                $data['precision_amount'] = 4;
                $data['sort'] = 0;
            }
            return;
        }

        $fields = $this->validatedFields($data);
        $row ? $this->updatePair($row, $fields) : $this->createPair($data, $fields);
    }

    /**
     * 新增和编辑共用的字段校验，返回可直接写库的字段
     * @return array{symbol:string, base_coin:string, quote_coin:string, precision_price:int, precision_amount:int, sort:int, status:int}
     */
    private function validatedFields(array $data): array
    {
        $symbol = trim((string)($data['symbol'] ?? ''));
        if ($symbol === '' || mb_strlen($symbol) > 64) $this->error('展示名称必填，最多 64 个字符，如 BTC/USDT！');

        $coins = [];
        foreach (['base_coin' => '基础币种', 'quote_coin' => '计价币种'] as $key => $label) {
            $coins[$key] = strtoupper(trim((string)($data[$key] ?? '')));
            if ($coins[$key] === '' || !ctype_alnum($coins[$key]) || strlen($coins[$key]) > 32) {
                $this->error("{$label}必填，只能是字母和数字，最多 32 位！");
            }
        }

        $precision = [];
        foreach (['precision_price' => '价格精度', 'precision_amount' => '数量精度'] as $key => $label) {
            $v = trim((string)($data[$key] ?? ''));
            if (!ctype_digit($v) || (int)$v > self::PRECISION_MAX) {
                $this->error("{$label}必须是 0 ~ " . self::PRECISION_MAX . ' 的整数（小数位数）！');
            }
            $precision[$key] = (int)$v;
        }

        $sort = filter_var(trim((string)($data['sort'] ?? '0')), FILTER_VALIDATE_INT);
        if ($sort === false || abs($sort) > 999999) $this->error('排序必须是整数，越大越靠前！');

        $status = CommonStatusEnum::tryFrom((int)($data['status'] ?? 0));
        if ($status === null) $this->error('请选择状态！');

        return $coins + $precision + ['symbol' => $symbol, 'sort' => $sort, 'status' => $status->value];
    }

    /** 新增：交易对与主源绑定写在同一个事务里，然后清掉 source → pair 映射缓存 */
    private function createPair(array $data, array $fields): void
    {
        // 订阅方在 hello 里写的就是它（MarketWireProtocol::parseStream 会把输入归一成小写字母数字），
        // 这里直接要求归一后的形态，免得库里存一种、订阅方写另一种
        $code = trim((string)($data['symbol_code'] ?? ''));
        if ($code === '' || !ctype_alnum($code) || ctype_digit($code) || strlen($code) > 64 || $code !== strtolower($code)) {
            $this->error('交易对代码只能是小写字母和数字（不能全是数字），如 btcusdt！');
        }
        $type = MarketDataTypeEnum::tryFrom((int)($data['type'] ?? 0));
        if ($type === null) $this->error('请选择类型！');

        if (\think\facade\Db::name('exchange_pair')->where(['symbol_code' => $code, 'type' => $type->value])->count() > 0) {
            $this->error('交易对 ' . MarketPublisher::pairKey($code, $type->value) . ' 已存在！');
        }

        // 不选 = 自发币，没有行情
        $sourceKey = trim((string)($data['source_key'] ?? ''));
        if ($sourceKey !== '') {
            $srcType = \think\facade\Db::name('market_data')->where('source_key', $sourceKey)->value('type');
            if ($srcType === null) $this->error("数据源 {$sourceKey} 不存在！");
            if ((int)$srcType !== $type->value) $this->error('数据源的类型（现货 / 合约）必须和交易对一致！');
        }

        $now = time();
        $pairId = \think\facade\Db::transaction(function () use ($fields, $code, $type, $sourceKey, $now) {
            $pairId = (int)\think\facade\Db::name('exchange_pair')->insertGetId($fields + [
                'symbol_code' => $code,
                'type'        => $type->value,
                'source_key'  => $sourceKey === '' ? null : $sourceKey,
                'create_at'   => date('Y-m-d H:i:s', $now),
                'update_at'   => date('Y-m-d H:i:s', $now),
                'create_ts'   => $now,
                'update_ts'   => $now,
            ]);
            if ($sourceKey !== '') {
                \think\facade\Db::name('exchange_pair_source')->insert([
                    'pair_id' => $pairId, 'source_key' => $sourceKey, 'priority' => 1,
                    'status' => CommonStatusEnum::ON->value, 'remark' => '新增交易对时绑定的主源',
                    'create_ts' => $now, 'update_ts' => $now,
                ]);
            }
            return $pairId;
        });
        MarketService::clearPairsCache();

        $pairKey = MarketPublisher::pairKey($code, $type->value);
        sysoplog('交易对管理', sprintf('新增交易对 %s（id=%d，数据源 %s）：%s', $pairKey, $pairId,
            $sourceKey ?: '无', json_encode($fields, JSON_UNESCAPED_UNICODE)));
        $this->success($sourceKey === ''
            ? "已新增 {$pairKey}。没有数据源，不会有行情（自发币）。"
            : "已新增 {$pairKey}，约 3 秒内开始随 {$sourceKey} 更新行情。");
    }

    /** 编辑：只改 validatedFields() 里那几列，日志记下每列的前后值 */
    private function updatePair(array $row, array $fields): void
    {
        $changes = [];
        foreach ($fields as $key => $value) {
            if ((string)$row[$key] !== (string)$value) $changes[] = "{$key}: {$row[$key]} → {$value}";
        }
        $pairKey = MarketPublisher::pairKey((string)$row['symbol_code'], (int)$row['type']);
        if (!$changes) $this->success('没有改动。');

        \think\facade\Db::name('exchange_pair')->where('id', (int)$row['id'])
            ->update($fields + ['update_at' => date('Y-m-d H:i:s'), 'update_ts' => time()]);
        sysoplog('交易对管理', "编辑交易对 {$pairKey}（id={$row['id']}）：" . implode('；', $changes));
        $this->success("已保存 {$pairKey}。");
    }

    /**
     * 新增交易对可选的数据源：market_data 全部的源，标出类型和已被几个交易对用作生效源。
     * 一个源可以同时驱动多个交易对（MarketService::getPairsBySourceKey），所以已被用的也列出来
     *
     * @return array<string,string> source_key => 下拉显示文字
     */
    private function sourceOptions(): array
    {
        $used = \think\facade\Db::name('exchange_pair')->where('source_key', '<>', '')
            ->group('source_key')->column('count(*)', 'source_key');
        $typeNames = MarketDataTypeEnum::labels();
        $out = [];
        foreach (\think\facade\Db::name('market_data')->field('source_key, type')->order('type asc, source_key asc')->limit(5000)->select() as $r) {
            $n = (int)($used[$r['source_key']] ?? 0);
            $out[$r['source_key']] = sprintf('%s（%s%s）', $r['source_key'], $typeNames[(int)$r['type']] ?? $r['type'],
                $n > 0 ? "，已被 {$n} 个交易对使用" : '，未使用');
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // pair 层 K 线 / 盘口 / 成交 —— 都是分发给订阅方的那份
    //
    // 与「原始行情」页的同名页面（Market::source_kline / depth / depth_trades）对照看。
    // 除 K 线列表外都不走独立菜单，从交易对列表的按钮进入。
    // -------------------------------------------------------------------------

    /** K 线周期 => 显示名，与 MarketHelper::KLINE_PERIODS 对齐 */
    private const PERIOD_LABELS = [
        '60'    => '1分钟',
        '300'   => '5分钟',
        '900'   => '15分钟',
        '3600'  => '1小时',
        '14400' => '4小时',
        '86400' => '1天',
    ];

    /**
     * 交易对 K 线
     * @auth true
     * @menu true
     */
    public function kline()
    {
        $this->title   = '交易对 K 线';
        $this->periods = self::PERIOD_LABELS;
        $this->_query('exchange_pair_kline')->alias('k')
            ->join('exchange_pair p', 'k.pair_id = p.id')
            ->field('k.*, p.symbol_code, p.type')
            ->layTable(function () {
            }, function (QueryHelper $query) {
                $query->equal('k.pair_id#pair_id,k.period#period');
                // 按 ts（K 线时间）排序而不是 id（写入顺序）：断档回补写进来的行 id 新、ts 旧
                $query->order('k.ts desc');
            });
    }

    /**
     * K 线列表映射
     */
    protected function _kline_page_filter(array &$data)
    {
        $types     = MarketDataTypeEnum::labels();
        $typeColor = [MarketDataTypeEnum::SPOT->value => 'layui-bg-green', MarketDataTypeEnum::SWAP->value => 'layui-bg-blue'];
        foreach ($data as &$vo) {
            $type = (int)($vo['type'] ?? 0);
            $vo['type_text']   = $types[$type] ?? (string)$type;
            $vo['type_color']  = $typeColor[$type] ?? '';
            $vo['period_text'] = self::PERIOD_LABELS[(string)$vo['period']] ?? $vo['period'] . 's';
            $vo['ts_text']     = date('Y-m-d H:i:s', intdiv((int)$vo['ts'], 1000));
        }
        unset($vo);
    }

    /**
     * K 线走势图（单个交易对）
     * @auth true
     */
    public function kline_preview()
    {
        $pairId = $this->resolvePairFilter();
        $pair   = $pairId > 0 ? \think\facade\Db::name('exchange_pair')->where('id', $pairId)->field('symbol_code, type')->find() : null;
        $this->title   = 'K线预览' . ($pair ? ' - ' . MarketPublisher::pairKey((string)$pair['symbol_code'], (int)$pair['type']) : '');
        $this->periods = self::PERIOD_LABELS;
        return $this->fetch();
    }

    /**
     * K 线走势图数据（echarts candlestick 格式），供 kline_preview() 异步拉取
     * @auth true
     */
    public function kline_preview_data()
    {
        $pairId = (int)$this->request->get('pair_id', 0);
        $period = (string)$this->request->get('period', '900');
        $limit  = min(500, max(10, (int)$this->request->get('limit', 200)));

        $rows = array_reverse(\think\facade\Db::name('exchange_pair_kline')
            ->where('pair_id', $pairId)->where('period', $period)
            ->order('ts desc')->limit($limit)->select()->toArray());

        $categories = $ohlc = $vols = [];
        foreach ($rows as $row) {
            $categories[] = date('m-d H:i', intdiv((int)$row['ts'], 1000));
            // echarts candlestick 单点格式固定为 [open, close, low, high]
            $ohlc[] = [(float)$row['open'], (float)$row['close'], (float)$row['low'], (float)$row['high']];
            $vols[] = (float)$row['vol'];
        }
        return json(['categories' => $categories, 'ohlc' => $ohlc, 'vols' => $vols]);
    }

    /**
     * 盘口深度：买卖两侧按档位并排（一行一档）
     * @auth true
     */
    public function depth()
    {
        $this->title = '盘口深度';
        $pairId = $this->resolvePairFilter();
        $depth  = MarketService::getPairDepth($pairId);
        $depthAt = $depth['ts'] > 0 ? date('Y-m-d H:i:s', intdiv($depth['ts'], 1000)) : '';

        // 切换交易对只重拉表格、不重渲染整页，更新时间要跟着这次响应回传
        if ($this->isLayTableRequest()) {
            $rows = [];
            $max  = max(count($depth['bids']), count($depth['asks']));
            for ($i = 0; $i < $max; $i++) {
                $rows[] = [
                    'level'     => $i + 1,
                    'bid_qty'   => $depth['bids'][$i][1] ?? '',
                    'bid_price' => $depth['bids'][$i][0] ?? '',
                    'ask_price' => $depth['asks'][$i][0] ?? '',
                    'ask_qty'   => $depth['asks'][$i][1] ?? '',
                ];
            }
            return json(['code' => 0, 'msg' => '', 'count' => count($rows), 'data' => $rows, 'depth_at' => $depthAt]);
        }

        $this->depthAt = $depthAt;
        return $this->fetch();
    }

    /**
     * 成交流水（最近一窗）
     * @auth true
     */
    public function depth_trades()
    {
        $this->title = '成交流水';
        $pairId = $this->resolvePairFilter();

        if ($this->isLayTableRequest()) {
            $trades = MarketService::getPairTrades($pairId);
            foreach ($trades as &$t) {
                $isSell = ($t['direction'] ?? '') === 'sell';
                $t['direction_text']  = $isSell ? '卖出' : '买入';
                $t['direction_color'] = $isSell ? 'color-red' : 'color-green';
                $t['ts_text']         = date('Y-m-d H:i:s', intdiv((int)($t['ts'] ?? 0), 1000));
            }
            unset($t);
            return json(['code' => 0, 'msg' => '', 'count' => count($trades), 'data' => $trades]);
        }

        return $this->fetch();
    }

    /** 是否 layTable 组件发起的数据请求（写法同 Market::isLayTableRequest()） */
    private function isLayTableRequest(): bool
    {
        $output = strtolower($this->request->method() . '.' . $this->request->request('output', 'default'));
        return $output === 'get.layui.table';
    }

    /**
     * 解析 pair_id 筛选并给模板准备下拉（$pairs / $pairId），返回生效的 pair_id；
     * 传入的不在列表里时回落到第一个，避免空页面
     */
    private function resolvePairFilter(): int
    {
        $types = MarketDataTypeEnum::labels();
        $rows  = \think\facade\Db::name('exchange_pair')->where('status', CommonStatusEnum::ON->value)
            ->order('type, id')->field('id, type, symbol_code')->select()->toArray();

        $this->pairs = [];
        foreach ($rows as $row) {
            $this->pairs[$row['id']] = "{$row['id']} (" . ($types[(int)$row['type']] ?? $row['type']) . ") {$row['symbol_code']}";
        }

        $pairId = (int)$this->request->get('pair_id', 0);
        if (!$pairId || !isset($this->pairs[$pairId])) {
            $pairId = $this->pairs ? (int)array_key_first($this->pairs) : 0;
        }
        $this->pairId = $pairId;
        return $pairId;
    }

    /**
     * 解锁主备切换：清掉该交易对的锁与切走记录，选源进程下一轮起恢复自动切换。
     *
     * 切走记录要一起清：只删锁的话，窗口内的切走次数还在，解锁后第一次切走就又够数、立刻锁回去。
     * @auth true
     */
    public function unlock()
    {
        $id    = (int)$this->request->post('id');
        $redis = redis_client();
        if ($id <= 0 || !$redis) $this->error('参数错误或 Redis 不可用！');

        $pair = \think\facade\Db::name('exchange_pair')->where('id', $id)->field('symbol_code, type')->find();
        if (!$pair) $this->error('交易对不存在！');

        $redis->multi(\Redis::PIPELINE)
            ->hDel(MarketSourceSelect::LOCK_KEY, (string)$id)
            ->hDel(MarketSourceSelect::FAILOVER_KEY, (string)$id)
            ->exec();
        business_log('market_source_switch', sprintf('[%s] 解锁主备切换（%s）',
            MarketPublisher::pairKey((string)$pair['symbol_code'], (int)$pair['type']), AdminService::getUserName()), 'warning');
        $this->success('已解锁，选源进程下一轮起恢复自动切换！');
    }

    /**
     * 选源状态的展示 —— [文字, badge 颜色类（空 = 不用 badge）, 悬浮说明]
     *
     * 判断顺序有讲究：锁是人要处理的，放最前；单源与未启用说明「本来就不会切」；
     * 状态过期（选源进程 10 秒写一次，30 秒没更新）说明进程没在跑，此时它记的状态不可信。
     *
     * @param ?string $stateJson MarketSourceSelect::STATE_KEY 里这个交易对的值
     * @param ?string $lockTs    MarketSourceSelect::LOCK_KEY 里的锁定时刻（秒）
     * @param ?string $failJson  MarketSourceSelect::FAILOVER_KEY 里的切走时刻列表
     */
    private static function selectLabel(int $bindCount, bool $enabled, ?string $stateJson, ?string $lockTs, ?string $failJson, int $now): array
    {
        $window = (int)config('market.source_select.flap_window_sec', 3600);
        $fails  = count(array_filter((array)json_decode((string)$failJson, true), fn($t) => $now - (int)$t < $window));
        $failText = $fails ? "近 " . intdiv($window, 60) . " 分钟切走 {$fails} 次" : '';

        if ($lockTs !== null) {
            return ['已锁定', 'layui-bg-red', '锁定于 ' . date('Y-m-d H:i:s', (int)$lockTs)
                . "，{$failText}；不再自动切换，确认主源稳定后点「解锁」"];
        }
        if ($bindCount < 2) {
            return ['单源', '', '只绑了一个启用的源，没有可切的备源'];
        }
        if (!$enabled) {
            return ['未启用', 'layui-bg-gray', 'market.source_select.enable 未打开，不会自动切换（后台仍可手动切）'];
        }
        $state = json_decode((string)$stateJson, true);
        if (!is_array($state) || $now - (int)($state['at'] ?? 0) > 30) {
            return ['无数据', 'layui-bg-orange', '选源进程没在跑（或刚启动），此刻不会自动切换'];
        }
        if (!empty($state['all_down'])) {
            return ['全部不健康', 'layui-bg-red', '当前源断流，且没有健康的备源，保持不动'];
        }
        return ['正常', 'layui-bg-green', $failText];
    }

    /**
     * 列表数据处理：所有静态映射（文字、颜色、是否合约）都在这里完成，模板只消费（Coding_Standards §10）
     * @param array $data
     */
    protected function _index_page_filter(array &$data)
    {
        $types    = MarketDataTypeEnum::labels();
        $statuses = CommonStatusEnum::labels();
        $typeColor = [
            MarketDataTypeEnum::SPOT->value => 'layui-bg-green',
            MarketDataTypeEnum::SWAP->value => 'layui-bg-blue',
        ];
        $statusColor = [
            CommonStatusEnum::ON->value  => 'layui-bg-green',
            CommonStatusEnum::OFF->value => 'layui-bg-gray',
        ];
        // 超过这个秒数没更新就标红。与价格可用性判定同一个阈值，标红的 pair 在订阅方那边也会被判过期
        $stale = (int)config('market.stale_seconds', 300);
        $now   = time();

        // 本页交易对的启用绑定，按优先级排好：第一个是主源，用来判断当前是不是跑在备源上
        $binds = [];
        $rows  = \think\facade\Db::name('exchange_pair_source')->whereIn('pair_id', array_column($data, 'id'))
            ->where('status', CommonStatusEnum::ON->value)->order('priority asc, id asc')
            ->field('pair_id, source_key')->select();
        foreach ($rows as $r) $binds[(int)$r['pair_id']][] = (string)$r['source_key'];

        // 选源进程写的状态、锁与切走记录（见 MarketSourceSelect），每页各读一次
        $redis    = redis_client();
        $selState = $redis ? ($redis->hGetAll(MarketSourceSelect::STATE_KEY) ?: []) : [];
        $locks    = $redis ? ($redis->hGetAll(MarketSourceSelect::LOCK_KEY) ?: []) : [];
        $fails    = $redis ? ($redis->hGetAll(MarketSourceSelect::FAILOVER_KEY) ?: []) : [];
        $enabled  = MarketSourceSelect::enabled();

        // 订阅标识：端口决定现货 / 合约，stream 里只写裸 symbol（MarketWireProtocol::parseStream）
        $ports = $streamNames = [];
        foreach (array_keys($types) as $t) {
            $ports[$t] = MarketDispatcher::portOf((int)$t);
            $streamNames[$t] = MarketWireProtocol::streamNames((int)$t);
        }

        foreach ($data as &$vo) {
            $active = (string)($vo['source_key'] ?? '');
            $bound  = $binds[(int)$vo['id']] ?? [];
            $pid    = (string)$vo['id'];
            $vo['locked'] = isset($locks[$pid]);
            [$vo['select_text'], $vo['select_color'], $vo['select_title']] = self::selectLabel(
                count($bound), $enabled, $selState[$pid] ?? null, $locks[$pid] ?? null, $fails[$pid] ?? null, $now
            );
            $vo['source_count'] = count($bound);
            if ($active === '') {
                $vo['source_role_text'] = '';
                $vo['source_role_color'] = '';
            } elseif ($bound && $bound[0] === $active) {
                $vo['source_role_text'] = '主源';
                $vo['source_role_color'] = 'layui-bg-blue';
            } elseif (in_array($active, $bound, true)) {
                // 跑在备源上：主源出过问题或被手动切走，值得一眼看到
                $vo['source_role_text'] = '备源';
                $vo['source_role_color'] = 'layui-bg-orange';
            } else {
                $vo['source_role_text'] = '未绑定';
                $vo['source_role_color'] = 'layui-bg-red';
            }

            $type = (int)$vo['type'];
            $vo['pair']         = MarketPublisher::pairKey((string)$vo['symbol_code'], $type);
            $vo['stream_symbol'] = strtolower((string)$vo['symbol_code']);
            $vo['stream_port']   = $ports[$type] ?? 0;
            $vo['streams_title'] = implode(' ', array_map(fn($n) => $vo['stream_symbol'] . '@' . $n, $streamNames[$type] ?? []));
            $vo['type_text']    = $types[$type] ?? (string)$type;
            $vo['type_color']   = $typeColor[$type] ?? '';
            $vo['is_swap']      = $type === MarketDataTypeEnum::SWAP->value;
            $vo['status_text']  = $statuses[(int)$vo['status']] ?? (string)$vo['status'];
            $vo['status_color'] = $statusColor[(int)$vo['status']] ?? '';

            // 没有数据源的是自发币，不会有行情，不算故障
            $vo['source_text'] = (string)($vo['source_key'] ?? '') !== '' ? (string)$vo['source_key'] : '自发币（无数据源）';

            // 最新价：从未更新过显示 -；按价格精度显示，避免低价币被截成 0
            $marketTs = (int)($vo['market_ts'] ?? 0);
            $precision = max(0, (int)$vo['precision_price']);
            $vo['has_price']  = $marketTs > 0;
            $vo['price_text'] = $marketTs > 0 ? number_format((float)$vo['last_price'], $precision, '.', '') : '-';

            // 24h 涨跌：涨绿跌红，与前台一致
            $change = (float)($vo['change'] ?? 0);
            $vo['change_text']  = $marketTs > 0 ? sprintf('%+.2f%%', $change) : '-';
            $vo['change_color'] = $change > 0 ? 'color-green' : ($change < 0 ? 'color-red' : 'color-desc');

            // 行情时间与新鲜度
            $sec = $marketTs > 10000000000 ? intdiv($marketTs, 1000) : $marketTs;
            $vo['market_ts_text'] = $sec > 0 ? date('Y-m-d H:i:s', $sec) : '-';
            $vo['market_stale']   = $sec > 0 && ($now - $sec) > $stale;
            $vo['market_color']   = $sec === 0 ? 'color-desc' : ($vo['market_stale'] ? 'color-red' : '');

            // 标记价格与资金费率只有合约有
            $markTs = (int)($vo['mark_ts'] ?? 0);
            $vo['mark_price_text'] = $vo['is_swap'] && $markTs > 0
                ? number_format((float)$vo['mark_price'], $precision, '.', '') : '-';

            $fundingTs = (int)($vo['funding_rate_ts'] ?? 0);
            if ($vo['is_swap'] && $fundingTs > 0) {
                $rate = (float)$vo['funding_rate'] * 100;
                $vo['funding_text']  = sprintf('%+.4f%%', $rate);
                // 正费率 = 多头付空头（对多头不利）标红，与交易所惯例一致
                $vo['funding_color'] = $rate > 0 ? 'color-red' : ($rate < 0 ? 'color-green' : 'color-desc');
                $fundingSec = $fundingTs > 10000000000 ? intdiv($fundingTs, 1000) : $fundingTs;
                $vo['funding_at']    = date('Y-m-d H:i:s', $fundingSec);
                // 超过 1 小时没更新标红，阈值与「原始行情」页同一个常量
                $vo['funding_at_color'] = ($now - $fundingSec) > MarketFunding::RATE_STALE_SECONDS ? 'color-red' : '';
            } else {
                $vo['funding_text']  = '-';
                $vo['funding_color'] = 'color-desc';
                $vo['funding_at']    = '-';
                $vo['funding_at_color'] = 'color-desc';
            }
        }
    }
}
