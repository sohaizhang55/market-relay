<?php

declare(strict_types=1);

namespace app\business\controller;

use think\admin\Controller;
use think\admin\helper\QueryHelper;
use think\facade\Db;
use app\enum\MarketExchangeEnum;
use app\enum\MarketDataTypeEnum;
use app\enum\MarketCalendarEnum;
use app\enum\MarketSessionStateEnum;
use app\enum\MarketChannelEnum;
use app\lib\MarketSourceHealth;
use app\service\MarketSessionService;

/**
 * 市场行情管理
 * @class Market
 */
class Market extends Controller
{
    /**
     * 市场行情列表
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '原始行情管理';
        $this->exchanges = MarketExchangeEnum::labels();
        $this->types = MarketDataTypeEnum::labels();
        // 不按 coin_category.status 过滤，口径同 CoinList::index
        $this->categories = Db::name('coin_category')->order('sort desc, id asc')->column('title', 'code');
        $this->_query("market_data")->layTable(function () {
        }, function (QueryHelper $query) {
            $query->like('symbol,source_key')->equal('exchange,type,category_code');
            $query->order('id asc');
        });
    }

    /**
     * 某本日历此刻的状态标签 —— [文字, badge 颜色类, 悬浮说明]
     *
     * 走 state()（**不看后台总开关**）：这一列回答的是「按交易日历此刻判什么」。
     * 开关停用时它与「实际能否下单」不一致，所以在悬浮说明里点破，别让人只看颜色下结论。
     *
     * 休市给中性灰不给红：它是计划内状态不是故障，红色留给「配置无效」那种真要人处理的，
     * 口径与「休市设置」页的状态卡片一致。
     */
    private static function sessionLabel(string $calendarCode): array
    {
        // 24/7 标的判定入口直接短路返回 OPEN，所以照实显示「开市」而不是破折号。
        // 现网 151 行都是这一类，满屏绿色反而让少数的灰（休市）和红（配置无效）更跳出来
        if ($calendarCode === '') {
            return ['开市', 'layui-bg-green', '24/7 标的，全天交易，没有休市'];
        }
        if (MarketCalendarEnum::tryFrom($calendarCode) === null) {
            return ['配置无效', 'layui-bg-red',
                'calendar_code 不在 MarketCalendarEnum 里，该源一律判休市'];
        }

        return MarketSessionService::state($calendarCode) === MarketSessionStateEnum::OPEN
            ? ['开市', 'layui-bg-green', '按交易日历此刻开市']
            : ['休市', 'layui-bg-gray', '按交易日历此刻休市 —— 计划内，不是故障'];
    }

    /**
     * 一路帧间隔统计的展示 —— [文字, 颜色类, 悬浮说明]，文字形如「61s / 10次」（最长间隔 / 超阈值次数）
     *
     * 统计由采集器算，本机没采这个源（采集器没跑、刚启动不到 10 分钟）时没有，不标红。
     *
     * @param ?array $st MarketSourceHealth::STATS_KEY 里这个源的值
     */
    private static function statLabel(?array $st, string $ch, string $name): array
    {
        if (!$st) {
            return ['-', 'color-desc', '还没有统计：采集器每 10 分钟落一次，刚启动不到 10 分钟或本机没在采这个源'];
        }
        $max   = (float)$st["{$ch}_max_gap_sec"];
        $times = (int)$st["{$ch}_stale_times"];
        $limit = (int)$st[$ch === 'price' ? 'ticker_stale_sec' : 'depth_stale_sec'];
        $title = sprintf('%s – %s：%s最长 %.1f 秒没来帧，超过断流阈值 %s %d 次',
            date('H:i', (int)$st['since']), date('H:i', (int)$st['until']), $name, $max,
            $limit > 0 ? "{$limit} 秒" : '（0 = 不判）', $times);
        return [sprintf('%.0fs / %d次', $max, $times), $times > 0 ? 'color-red' : 'color-green', $title];
    }

    /**
     * 列表数据处理
     * @param array $data
     */
    protected function _index_page_filter(array &$data)
    {
        $types = MarketDataTypeEnum::labels();
        $exchanges = MarketExchangeEnum::labels();
        $calendars = MarketCalendarEnum::labels();
        $categories = Db::name('coin_category')->column('title', 'code');
        $sessionCache = [];
        // 每页只读一次两个 HASH，逐行查表
        $wsPrice = MarketSourceHealth::wsTsMap(MarketChannelEnum::PRICE);
        $wsDepth = MarketSourceHealth::wsTsMap(MarketChannelEnum::DEPTH);
        $nowMs   = (int)(microtime(true) * 1000);
        // 采集器每 10 分钟落一次的帧间隔统计，调断流秒数时对着看
        $stats   = redis_client()?->hGetAll(MarketSourceHealth::STATS_KEY) ?: [];

        foreach ($data as &$item) {
            $st = json_decode((string)($stats[(string)($item['source_key'] ?? '')] ?? ''), true);
            foreach (['price' => '价格', 'depth' => '深度'] as $ch => $name) {
                [$item["{$ch}_stat_text"], $item["{$ch}_stat_color"], $item["{$ch}_stat_title"]] = self::statLabel($st, $ch, $name);
            }

            // WS 新鲜度：主备切换判断流用的就是这两个数，与 ticker_stale_sec / depth_stale_sec 比
            $sk = (string)($item['source_key'] ?? '');
            [$item['price_fresh_text'], $item['price_fresh_color'], $item['price_fresh_title']]
                = MarketSourceHealth::label($wsPrice[$sk] ?? 0, (int)($item['ticker_stale_sec'] ?? 0), $nowMs);
            [$item['depth_fresh_text'], $item['depth_fresh_color'], $item['depth_fresh_title']]
                = MarketSourceHealth::label($wsDepth[$sk] ?? 0, (int)($item['depth_stale_sec'] ?? 0), $nowMs);

            // 交易日历归属。空串是**有效取值**（24/7 无休市），不是"没填"，所以不显示 "-"。
            // 库里出现枚举认不出的 MIC 标红：那批行永远匹配不上任何假日数据，等于节假日照常开盘
            $calendarCode = (string)($item['calendar_code'] ?? '');
            if ($calendarCode === '') {
                $item['calendar_code_text']  = '24/7';
                $item['calendar_code_title'] = '全天交易，无休市';
                $item['calendar_code_color'] = 'layui-bg-gray';
            } elseif (isset($calendars[$calendarCode])) {
                $item['calendar_code_text']  = $calendarCode;
                $item['calendar_code_title'] = $calendars[$calendarCode];
                $item['calendar_code_color'] = 'layui-bg-blue';
            } else {
                $item['calendar_code_text']  = $calendarCode;
                $item['calendar_code_title'] = 'MarketCalendarEnum 里没有这本日历，该源永远查不到假日';
                $item['calendar_code_color'] = 'layui-bg-red';
            }

            // 此刻开不开市。同一本日历在一页里会出现多次，按 code 缓存一次判定
            if (!array_key_exists($calendarCode, $sessionCache)) {
                $sessionCache[$calendarCode] = self::sessionLabel($calendarCode);
            }
            [$item['session_text'], $item['session_badge'], $item['session_title']] = $sessionCache[$calendarCode];

            $category = (string)($item['category_code'] ?? '');
            $item['category_text'] = $categories[$category] ?? $category;

            $typeValue = $item['type'] ?? 1;
            $item['type_text'] = $types[$typeValue] ?? '现货';
            $exchange = $item['exchange'] ?? '';
            $item['exchange_text'] = $exchanges[$exchange] ?? $exchange;
            if (!empty($item['update_ts'])) {
                $ts = intval($item['update_ts']);
                if ($ts > 10000000000) $ts = intval($ts / 1000);
                $item['update_ts_text'] = date('Y-m-d H:i:s', $ts);
            } else {
                $item['update_ts_text'] = '-';
            }

            // 标记价格更新时间
            if (!empty($item['mark_ts'])) {
                $markTs = intval($item['mark_ts']);
                if ($markTs > 10000000000) $markTs = intval($markTs / 1000);
                $item['mark_ts_text'] = date('Y-m-d H:i:s', $markTs);
            } else {
                $item['mark_ts_text'] = '-';
            }

            // 资金费率采集时间 + 新鲜度标记。费率过期不是小事：下游资金费结算前会校验
            // 新鲜度，超阈值就整期跳过收费，所以这里直接把陈旧的标出来。
            // 阈值引用供数侧那个常量，避免两处各写一份数字后漂移
            if (!empty($item['funding_rate_ts'])) {
                $fundingTs = intval($item['funding_rate_ts']);
                if ($fundingTs > 10000000000) $fundingTs = intval($fundingTs / 1000);
                $item['funding_rate_ts_text'] = date('Y-m-d H:i:s', $fundingTs);
                $item['funding_stale'] = (time() - $fundingTs) > \app\command\MarketFunding::RATE_STALE_SECONDS;
            } else {
                $item['funding_rate_ts_text'] = '-';
                $item['funding_stale'] = false; // 从未采集过，由费率列自己显示 "-"，不重复标红
            }

            // 交易所自报的下次结算时间 (仅记录备用，不参与本平台结算窗口计算)
            if (!empty($item['exchange_next_funding_ts'])) {
                $nextTs = intval($item['exchange_next_funding_ts']);
                if ($nextTs > 10000000000) $nextTs = intval($nextTs / 1000);
                $item['exchange_next_funding_ts_text'] = date('Y-m-d H:i:s', $nextTs);
            } else {
                $item['exchange_next_funding_ts_text'] = '-';
            }

              if (!empty($item['market_ts'])) {
                $markTs = intval($item['market_ts']);
                if ($markTs > 10000000000) $markTs = intval($markTs / 1000);
                $item['market_ts_text'] = date('Y-m-d H:i:s', $markTs);
            } else {
                $item['market_ts_text'] = '-';
            }
        }
    }

    /**
     * 编辑行情源的断流阈值
     *
     * market_data 其余列由种子与采集器维护，这里只开放 ticker_stale_sec / depth_stale_sec 两列，
     * 表单过滤器里按白名单重建 $data，提交别的字段也写不进去。
     * @auth true
     */
    public function stale_edit()
    {
        $this->title = '编辑断流阈值';
        $this->_form('market_data', 'stale_form');
    }

    /**
     * 断流阈值表单：白名单两列，0 ~ 3600 的整数（0 = 这一路不判）
     * @param array $data
     */
    protected function _stale_edit_form_filter(array &$data)
    {
        if (!$this->request->isPost()) return;

        // SystemService::save 找不到这一行时会新插一行，所以必须先确认存在
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0 || !Db::name('market_data')->where('id', $id)->value('id')) {
            $this->error('只能编辑已有的行情源！');
        }

        $out = ['id' => $id];
        foreach (['ticker_stale_sec' => 'ticker', 'depth_stale_sec' => '深度'] as $field => $label) {
            $raw = trim((string)($data[$field] ?? ''));
            if ($raw === '' || !ctype_digit($raw) || (int)$raw > 3600) {
                $this->error("{$label}断流秒数必须是 0 ~ 3600 的整数（0 = 不判）！");
            }
            $out[$field] = (int)$raw;
        }
        $data = $out;
    }

    /**
     * 原始 K 线采集列表
     * @auth true
     * @menu true
     */
    public function source_kline()
    {

        $this->periods = [
            '60'    => '1分钟',
            '300'   => '5分钟',
            '900'   => '15分钟',
            '1800'  => '30分钟',
            '3600'  => '1小时',
            '14400' => '4小时',
            '86400' => '1天',
        ];

        $this->title = '原始 K 线采集';
        return $this->_query("market_source_kline")->layTable(function () {
            // 这里可以进行一些初始化操作
        }, function (QueryHelper $query) {
            $query->like('source_key')->equal('period');
            // 按 ts (K线真实时间) 排序，而不是 id (写入顺序)：断档回补写入的行 id 是新的，
            // 但 ts 是补录的历史时间，id desc 排序会把"新写入的旧数据"排到最前面，跟真实时间顺序对不上
            $query->order('ts desc');
        });
    }

    /**
     * 原始 K 线预览：单一 source_key 的 K 线走势图，用于排查采集数据本身是否正常
     * (比如断档回补前后对比、可疑蜡烛核实)，不经过 exchange_pair_kline 那层受控转换。
     * 不走独立菜单，从"原始 K 线采集"列表操作按钮进入 (带 source_key 直达)
     * @auth true
     */
    public function source_kline_preview()
    {
        $sourceKey = (string)$this->request->get('source_key', '');
        $this->sourceKey = $sourceKey;
        $this->title = '原始K线预览' . ($sourceKey ? " - {$sourceKey}" : '');
        $this->periods = [
            '60'    => '1分钟',
            '300'   => '5分钟',
            '900'   => '15分钟',
            '3600'  => '1小时',
            '14400' => '4小时',
            '86400' => '1天',
        ];

        return $this->fetch();
    }

    /**
     * 原始 K 线走势图数据 (echarts candlestick 格式)，供 source_kline_preview() 页面异步拉取
     * @auth true
     */
    public function source_kline_preview_kline()
    {
        $sourceKey = (string)$this->request->get('source_key', '');
        $period    = (string)$this->request->get('period', '900');
        $limit     = min(500, max(10, (int)$this->request->get('limit', 200)));

        $rows = \think\facade\Db::name('market_source_kline')
            ->where('source_key', $sourceKey)
            ->where('period', $period)
            ->order('ts desc')
            ->limit($limit)
            ->select()
            ->toArray();
        $rows = array_reverse($rows); // 按时间正序供图表消费

        $categories = [];
        $ohlc = [];
        $vols = [];
        foreach ($rows as $row) {
            $categories[] = date('m-d H:i', intval((int)$row['ts'] / 1000));
            $ohlc[] = [(float)$row['open'], (float)$row['close'], (float)$row['low'], (float)$row['high']];
            $vols[] = (float)$row['vol'];
        }

        return json(['categories' => $categories, 'ohlc' => $ohlc, 'vols' => $vols]);
    }

    // -------------------------------------------------------------------------
    // 原始层盘口深度 / 成交流水
    //
    // 与「交易对」那两个同名页面 (exchange\Pair::depth / depth_trades) 的区别是这里按 source_key 读：
    // market:depth:src_{source_key} / market:trade:src_{source_key}，
    // 存的就是交易所推过来的原样数据，口径与本页其它字段 (close/mark_price/funding_rate) 一致。
    //
    // 均不走独立菜单，从「原始行情管理」列表的操作按钮进入 (带 source_key 直达)。
    // -------------------------------------------------------------------------

    /**
     * 原始层盘口深度：买卖两侧按档位并排展示 (一行 = 一档)
     * @auth true
     */
    public function depth()
    {
        $this->title = '原始盘口深度';
        $sourceKey = $this->resolveSourceKeyFilter();

        // ajax 分支 (layTable 组件发起)：切换 source_key 只会重新拉表格数据、不会重渲染整页，
        // 所以缓存更新时间必须跟着这次响应一起回传，否则页面上的提示会一直停在首屏的值
        if ($this->isLayTableRequest()) {
            $rows = $this->buildRawDepthRows($sourceKey);
            return json(['code' => 0, 'msg' => '', 'count' => count($rows), 'data' => $rows, 'depth_at' => $this->getRawDepthAt($sourceKey)]);
        }

        $this->depthAt = $this->getRawDepthAt($sourceKey);

        return $this->fetch();
    }

    /**
     * 原始层成交流水 (最近 30 条)
     * @auth true
     */
    public function depth_trades()
    {
        $this->title = '原始成交流水';
        $sourceKey = $this->resolveSourceKeyFilter();

        if ($this->isLayTableRequest()) {
            $rows = $this->buildRawTradeRows($sourceKey);
            return json(['code' => 0, 'msg' => '', 'count' => count($rows), 'data' => $rows]);
        }

        return $this->fetch();
    }

    /**
     * 判断当前请求是否是 layTable 组件发起的数据请求
     * (写法与 exchange\Pair::isLayTableRequest() 一致)
     */
    private function isLayTableRequest(): bool
    {
        $output = strtolower($this->request->method() . '.' . $this->request->request('output', 'default'));
        return $output === 'get.layui.table';
    }

    /**
     * 解析 source_key 筛选项，并给模板准备下拉列表。
     * 与 exchange\Pair::resolvePairFilter() 同构，只是维度从 pair_id 换成 source_key。
     * 传入的 source_key 不在列表里 (比如已下线的行情源) 时回落到第一项，避免空页面。
     */
    private function resolveSourceKeyFilter(): string
    {
        $typeTexts = MarketDataTypeEnum::labels();
        $rows = \think\facade\Db::name('market_data')->order('type, id')->field('source_key, type, exchange, symbol')->select()->toArray();

        $this->sourceKeys = [];
        foreach ($rows as $row) {
            $key = (string)($row['source_key'] ?? '');
            if ($key === '') continue;
            $typeText = $typeTexts[$row['type']] ?? $row['type'];
            $this->sourceKeys[$key] = "({$typeText}) {$row['exchange']} {$row['symbol']}";
        }

        $sourceKey = (string)$this->request->get('source_key', '');
        if ($sourceKey === '' || !isset($this->sourceKeys[$sourceKey])) {
            $sourceKey = !empty($this->sourceKeys) ? (string)array_key_first($this->sourceKeys) : '';
        }
        $this->sourceKey = $sourceKey;

        return $sourceKey;
    }

    /**
     * 组装深度行：买卖并排，按档位对齐，某一侧档位少时该侧留空
     */
    private function buildRawDepthRows(string $sourceKey): array
    {
        if ($sourceKey === '') return [];
        $depth = \app\business\service\MarketService::getRawDepthBySourceKey($sourceKey);

        $bids = $depth['bids'] ?? [];
        $asks = $depth['asks'] ?? [];
        $rows = [];
        $max = max(count($bids), count($asks));
        for ($i = 0; $i < $max; $i++) {
            $rows[] = [
                'level'     => $i + 1,
                'bid_qty'   => $bids[$i][1] ?? '',
                'bid_price' => $bids[$i][0] ?? '',
                'ask_price' => $asks[$i][0] ?? '',
                'ask_qty'   => $asks[$i][1] ?? '',
            ];
        }
        return $rows;
    }

    /**
     * 组装成交流水行，附带方向文字/颜色映射
     */
    private function buildRawTradeRows(string $sourceKey): array
    {
        if ($sourceKey === '') return [];
        $trades = \app\business\service\MarketService::getRawTradesBySourceKey($sourceKey);

        foreach ($trades as &$t) {
            $isSell = ($t['direction'] ?? '') === 'sell';
            $t['direction_text']  = $isSell ? '卖出' : '买入';
            $t['direction_color'] = $isSell ? 'color-red' : 'color-green';
            $t['ts_text'] = date('Y-m-d H:i:s', intdiv((int)($t['ts'] ?? 0), 1000));
        }
        unset($t);
        return $trades;
    }

    /**
     * 深度快照的缓存时间，用于核对数据新鲜度
     */
    private function getRawDepthAt(string $sourceKey): string
    {
        if ($sourceKey === '') return '';
        $ts = (int)(\app\business\service\MarketService::getRawDepthBySourceKey($sourceKey)['ts'] ?? 0);
        return $ts > 0 ? date('Y-m-d H:i:s', intdiv($ts, 1000)) : '';
    }

    /**
     * 原始 K 线列表数据处理
     * @param array $data
     */
    protected function _source_kline_page_filter(array &$data)
    {

        $periods = [
            '60'    => '1分钟',
            '300'   => '5分钟',
            '900'   => '15分钟',
            '1800'  => '30分钟',
            '3600'  => '1小时',
            '14400' => '4小时',
            '86400' => '1天',
        ];
        
        foreach ($data as &$item) {

            $period = $item['period'] ?? '';
            $item['period_text'] = $periods[$period] ?? ($period ? $period . 's' : '-');
            // 处理 source_key 的友好展示
            $parts = explode('_', $item['source_key']);
            $item['exchange'] = $parts[0] ?? '';
            $item['symbol'] = $parts[1] ?? '';
            // 汇总周期（如火币日线）不是交易所原样推来的，由基础周期按 UTC 汇总，见 MarketHelper::klinePlan()
            $base = \app\lib\MarketHelper::klinePlan(strtolower($item['exchange']))['derived'][(int)$period] ?? null;
            $item['origin_text']  = $base === null ? '交易所' : '由' . ($periods[(string)$base] ?? $base . 's') . '汇总';
            $item['origin_color'] = $base === null ? 'color-desc' : 'color-blue';
            $item['ts_text'] = date('Y-m-d H:i:s', intval(($item['ts'] ?? 0) / 1000));
        }
    }
}
