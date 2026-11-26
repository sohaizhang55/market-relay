<?php

declare(strict_types=1);

namespace app\business\controller;

use app\enum\CommonStatusEnum;
use app\enum\CommonWeekDayEnum;
use app\enum\MarketCalendarEnum;
use app\enum\MarketHolidayTypeEnum;
use app\enum\MarketSessionStateEnum;
use app\command\MarketSessionCheck;
use app\service\MarketSessionService;
use think\admin\Controller;
use think\admin\helper\QueryHelper;
use think\facade\Db;

/**
 * 交易日历管理
 *
 * 管的是传统市场标的的**计划性休市**（日历归属列非空）。24/7 加密标的
 * calendar_code 为空串，判定入口短路返回 OPEN，与本模块完全无关。
 *
 * ⚠️ 本模块只管数据与总开关，**不做判定**。判定在 app\service\MarketSessionService
 * （2026-09-23 落地），规则是「落在时段表某段内 ∧ ¬当日假日」。
 * 两张表（holiday 假日 / session 开市时段）都归本控制器管，改完最长 60 秒生效
 * （导入命令会主动清缓存，后台改则等缓存自然过期）。
 *
 * 日历本身（MIC / 中文名 / 时区）在 MarketCalendarEnum，不是数据表，所以没有日历的增删改。
 *
 * 设计依据：.agents/plan/market_calendar_module.md §三
 */
class MarketCalendar extends Controller
{
    /**
     * 节假日休市表
     * @var string
     */
    protected $holidayTable = 'market_calendar_holiday';

    /**
     * 开市时段表（周内固定规则）
     * @var string
     */
    protected $sessionTable = 'market_calendar_session';

    /**
     * 后台编辑过的行统一改成这个来源，避免被导入命令覆盖
     * @var string
     */
    protected const SOURCE_MANUAL = 'manual';

    /**
     * 导入命令写入的来源标记，只有这一类行会被重跑覆盖
     * @var string
     */
    protected const SOURCE_IMPORT = 'exchange_calendars';

    /**
     * 休市设置 —— 休市流程的总开关
     *
     * 关掉之后**拦截侧**（下单、做市 Bot）完全不查日历，走回原本的流程；
     * 而告警与 REST 兜底那批豁免不受影响，理由见模板里的说明与 MarketSessionService 的两套入口。
     *
     * 存进 system_config（sysconf 的 market_session.enabled），但判定侧**不用 sysconf 读** ——
     * 那边把整份配置缓存在进程级 sysvar 里，常驻进程要重启才看得到改动，
     * 详见 MarketSessionService::gateEnabled()。
     *
     * @auth true
     * @menu true
     */
    public function setting()
    {
        $this->_applyFormToken();

        if ($this->request->isGet()) {
            $this->title    = '休市设置';
            $this->statuses = CommonStatusEnum::labels();
            // 缺配置按「启用」显示，与 MarketSessionService::gateEnabled() 的兜底同口径
            $raw = sysconf('market_session.enabled');
            $this->gate = ($raw === null || $raw === '') ? CommonStatusEnum::ON->value : (int)$raw;
            // 模板只消费布尔，不在 .html 里比数字（Coding_Standards §10）
            $this->gateOn = $this->gate === CommonStatusEnum::ON->value;
            $this->lives  = $this->liveStates();
            $this->audit  = $this->auditState();
            return $this->fetch();
        }

        // 表单字段名刻意不带点号：PHP 会把 $_POST 键里的 . 转成 _，
        // 用 market_session.enabled 取回来的会是 null。显式取一个值再显式写入，
        // 也顺带避免了 foreach($post) 那种写法把意外字段一并写进配置表
        $status = (int)$this->request->post('market_session_enabled', 0);
        if (!in_array($status, [CommonStatusEnum::ON->value, CommonStatusEnum::OFF->value], true)) {
            $this->error('休市流程开关取值非法！');
        }

        sysconf('market_session.enabled', (string)$status);
        $label = $status === CommonStatusEnum::ON->value ? '启用' : '停用';
        sysoplog('业务配置管理', "修改休市流程开关为「{$label}」");
        $this->success("保存成功，休市流程已{$label}，最长 10 秒全站生效。");
    }

    /**
     * 「休市判定巡检」最近一次的结果，给设置页显示。
     *
     * 三种情况分开说，因为运维要采取的动作完全不同：
     *   巡检没跑    队列任务停了 -> 去查 xadmin:queue
     *   有冲突      日历配错/过期 -> 去改日历
     *   正常        什么都不用做
     *
     * 结果由 market:session_check 落在 Redis 里（TTL 1 小时），本方法只读不算。
     * 后台自己再跑一遍判定是没意义的：那样「后台说正常、巡检说有问题」时没人知道该信谁。
     *
     * @return array{level:string, title:string, detail:array, ago:string}
     */
    private function auditState(): array
    {
        $res = MarketSessionCheck::lastResult();

        if ($res === null) {
            return [
                'level'  => 'layui-bg-orange',
                'title'  => '巡检未运行',
                'detail' => ['最近 1 小时没有巡检记录。检查 xadmin:queue 队列进程是否在跑，'
                    . '或手工执行 php think market:session_check'],
                'ago'    => '-',
            ];
        }

        $ago  = self::agoText(time() - (int)$res['ts']);
        $hits = $res['hits'] ?? [];

        if ($hits === []) {
            return ['level' => 'layui-bg-green', 'title' => '正常', 'detail' => [], 'ago' => $ago];
        }

        return [
            'level'  => 'layui-bg-red',
            'title'  => count($hits) . ' 个源判休市却仍在收数据',
            'detail' => $hits,
            'ago'    => $ago,
        ];
    }

    /** 多久之前 */
    private static function agoText(int $sec): string
    {
        if ($sec < 60)   return $sec . ' 秒前';
        if ($sec < 3600) return intdiv($sec, 60) . ' 分钟前';
        return intdiv($sec, 3600) . ' 小时前';
    }

    /** 状态表里每本日历最多列几个行情源，其余折叠成「另 N 个」 */
    private const SOURCE_PREVIEW = 12;

    /**
     * 每本在用日历此刻开不开市 —— 给设置页的实时状态表。
     *
     * 只列 market_data 里真有源跟随的日历：68 本全列出来，60 多本没人用的会把有用的淹掉
     * （口径与 MarketImportCalendar 的覆盖体检一致）。
     *
     * 判定走 isOpen*（**不看总开关**）：这里回答的是「按日历此刻判什么」，
     * 而不是「现在能不能下单」—— 开关关掉时两者不一致，模板里单独提示。
     *
     * @return array<int,array>
     */
    private function liveStates(): array
    {
        // 空串 = 24/7 加密标的，判定入口直接短路，没有「休市」这回事，不占一行。
        // chunk 而不是 limit：与 MarketSessionService 同一套做法，读得全也带 LIMIT
        $grouped = [];
        Db::name('market_data')
            ->field('id,calendar_code,source_key')
            ->where('calendar_code', '<>', '')
            ->chunk(1000, function ($rows) use (&$grouped) {
                foreach ($rows as $row) {
                    $grouped[trim((string)$row['calendar_code'])][] = (string)$row['source_key'];
                }
            });
        ksort($grouped);

        $out = [];
        foreach ($grouped as $code => $keys) {
            $calendar = MarketCalendarEnum::tryFrom($code);
            $state    = MarketSessionService::state($code);
            $open     = $state === MarketSessionStateEnum::OPEN;

            // 源清单在 Controller 切好再给模板（Coding_Standards §10）。
            // 一本日历跟随几十个源时整张表会被撑开，所以只列前 SOURCE_PREVIEW 个
            $shown = array_slice($keys, 0, self::SOURCE_PREVIEW);

            $out[] = [
                'code'         => $code,
                'label'        => $calendar?->labelText() ?? '未知日历',
                'timezone'     => $calendar?->timezone() ?? '-',
                'local_time'   => $calendar ? self::localTime($calendar->timezone()) : '-',
                'sources'      => $shown,
                'source_count' => count($keys),
                'source_more'  => max(0, count($keys) - count($shown)),
                'state_text'   => $calendar === null ? '配置无效' : ($open ? '开市' : '休市'),
                // 休市是计划内的正常状态，给中性灰；红色留给「配置无效」那种真要人处理的
                'state_badge'  => $calendar === null ? 'layui-bg-red' : ($open ? 'layui-bg-green' : 'layui-bg-gray'),
                'invalid'      => $calendar === null,
            ];
        }

        return $out;
    }

    /**
     * 交易所本地此刻，给状态表显示用。
     *
     * 用 DateTime + 时区构造，**不要**写成 date('H:i', time() + offset)：
     * date() 按 PHP 默认时区格式化，再叠一次偏移就是两次换算，2026-09-25 实测差了 4 小时。
     */
    private static function localTime(string $timezone): string
    {
        try {
            return (new \DateTime('now', new \DateTimeZone($timezone)))->format('m-d H:i');
        } catch (\Throwable $e) {
            return '-';
        }
    }

    /**
     * 节假日休市
     * @auth true
     * @menu true
     */
    public function holiday()
    {
        $this->title = '节假日休市';
        $this->calendars = MarketCalendarEnum::selectOptions();
        $this->closeTypes = MarketHolidayTypeEnum::labels();
        $this->statuses = CommonStatusEnum::labels();

        $query = $this->_query($this->holidayTable);
        $query->like('title')->equal('calendar_code,close_type,status,source');
        $query->dateBetween('holiday_date');
        return $query->layTable();
    }

    /**
     * 假日列表的静态映射。
     *
     * Coding_Standards §10：静态文字/颜色一律在这里算好，模板只消费 _text / _color。
     *
     * calendar_code 认不出时必须标红 —— DB 里混进 MarketCalendarEnum 没有的 MIC，
     * 那一行永远匹配不上任何标的，表现为「配了假日但节假日照常开盘」。
     * 它和正常行长得一样正是这个错误难发现的原因，所以这里让它长得不一样。
     *
     * @param array $data
     */
    protected function _holiday_page_filter(array &$data)
    {
        $closeTypes = MarketHolidayTypeEnum::labels();

        foreach ($data as &$item) {
            $code = (string)($item['calendar_code'] ?? '');
            $calendar = MarketCalendarEnum::tryFrom($code);

            $item['calendar_text']  = $calendar ? $calendar->labelText() . ' (' . $code . ')' : $code . ' ⚠ 未知日历';
            $item['calendar_color'] = $calendar ? '' : 'color-red';
            $item['timezone_text']  = $calendar ? $calendar->timezone() : '-';

            $type = (int)($item['close_type'] ?? 0);
            $item['close_type_text']  = $closeTypes[$type] ?? (string)$type;
            $item['close_type_color'] = match ($type) {
                MarketHolidayTypeEnum::FULL_CLOSE->value => 'color-red',
                // 覆盖是唯一能把当天开市区间改**大**的类型，单独一色，便于巡检时一眼扫出来
                MarketHolidayTypeEnum::OVERRIDE->value   => 'color-blue',
                default                                  => 'color-orange',
            };

            // 全日休市不展示时间段，展示了反而让人以为那天还开一会儿
            $item['session_text'] = $type === MarketHolidayTypeEnum::FULL_CLOSE->value
                ? '全日休市'
                : $this->formatPartialSession($item);

            $item['source_text']  = ($item['source'] ?? '') === self::SOURCE_IMPORT ? '自动导入' : '人工录入';
            $item['source_color'] = ($item['source'] ?? '') === self::SOURCE_IMPORT ? 'color-desc' : '';
        }
        unset($item);
    }

    /**
     * 把时段调整日的 open_time / close_time 拼成一句人话。
     *
     * 两列都空是非法状态（表单已挡），真出现了要显式说出来而不是显示一个空格 ——
     * 那种行等于「什么也没改」，但看着像配好了。
     *
     * @param array $item 假日行
     * @return string
     */
    protected function formatPartialSession(array $item): string
    {
        $open  = trim((string)($item['open_time'] ?? ''));
        $close = trim((string)($item['close_time'] ?? ''));

        if ($open === '' && $close === '') return '⚠ 未设时间';
        if ($open === '') return '至 ' . $close . ' 收盘';
        if ($close === '') return $open . ' 起开盘';
        return $open . ' – ' . $close;
    }

    /**
     * 添加或编辑节假日
     * @auth true
     */
    public function holiday_add()
    {
        $this->title = input('id') ? '编辑节假日' : '添加节假日';
        $this->_form($this->holidayTable, 'holiday_form');
    }

    /**
     * 节假日表单校验。
     *
     * 这里每一条校验对应一种「配了但不生效」的静默失败，逐条的后果见
     * .agents/plan/market_calendar_module.md §3.3。共同点是：错了之后列表看着正常，
     * 只有到了那一天才发现节假日照常开盘。
     *
     * @param array $data
     */
    protected function _holiday_add_form_filter(array &$data)
    {
        if ($this->request->isGet()) {
            $data['close_type'] = isset($data['close_type'])
                ? (int)$data['close_type']
                : MarketHolidayTypeEnum::FULL_CLOSE->value;
            $data['status'] = isset($data['status']) ? (int)$data['status'] : CommonStatusEnum::ON->value;

            $this->calendars = MarketCalendarEnum::selectOptions();
            $this->closeTypes = MarketHolidayTypeEnum::labels();
            $this->statuses = CommonStatusEnum::labels();
            // 表单要按休市类型显隐时间输入框，模板里不许写裸数字（Coding_Standards §10）
            // 视图据此决定要不要显示时间输入框：只有全日休市不需要填时间，
            // 时段调整(2)与时段覆盖(3)都要。传「全日休市」而不是「时段调整」，新增类型时不必再改视图
            $this->fullCloseType = MarketHolidayTypeEnum::FULL_CLOSE->value;
        }

        if ($this->request->isPost()) {
            // 日历必须是枚举里认识的 MIC，否则这一行永远匹配不上任何标的
            $code = strtoupper(trim((string)($data['calendar_code'] ?? '')));
            if (MarketCalendarEnum::tryFrom($code) === null) {
                $this->error('交易日历非法，请从下拉中选择！');
            }
            $data['calendar_code'] = $code;

            $date = trim((string)($data['holiday_date'] ?? ''));
            if (!$this->isValidDate($date)) $this->error('休市日期格式不正确，应为 YYYY-MM-DD！');
            $data['holiday_date'] = $date;

            $type = (int)($data['close_type'] ?? 0);
            if (MarketHolidayTypeEnum::tryFrom($type) === null) $this->error('请选择休市类型！');

            $open  = trim((string)($data['open_time'] ?? ''));
            $close = trim((string)($data['close_time'] ?? ''));

            if ($type === MarketHolidayTypeEnum::FULL_CLOSE->value) {
                // 全日休市带着时间值，后来人读不懂那两个数到底生不生效，直接清掉
                $open = $close = '';
            } else {
                if ($open === '' && $close === '') {
                    $this->error('时段调整必须至少填写开盘或收盘时间，否则这一行什么也不会改变！');
                }
                if ($open !== '' && !$this->isValidTime($open)) $this->error('开盘时间格式不正确，应为 HH:MM（24 小时制）！');
                if ($close !== '' && !$this->isValidTime($close)) $this->error('收盘时间格式不正确，应为 HH:MM（24 小时制）！');
                if ($open !== '' && $close !== '' && $open >= $close) {
                    $this->error('收盘时间必须晚于开盘时间！');
                }
                // 与 MarketImportCalendar::normalizeRow 同一条规则：覆盖是整段替换，
                // 只给一头的话另一头没有边界 —— 沿用时段表就退化成裁剪，留空则区间不成立
                if ($type === MarketHolidayTypeEnum::OVERRIDE->value && ($open === '' || $close === '')) {
                    $this->error('时段覆盖是整段替换，开盘与收盘时间都必须填写！');
                }
            }
            $data['open_time'] = $open;
            $data['close_time'] = $close;

            // 同一本日历同一天只能有一条规则，否则判定取哪条取决于查询顺序。
            // 表里有唯一索引兜底，这里先挡是为了给出人话提示而不是一个 SQL 报错
            $map = [['calendar_code', '=', $code], ['holiday_date', '=', $date]];
            if (!empty($data['id'])) $map[] = ['id', '<>', $data['id']];
            if (Db::name($this->holidayTable)->where($map)->count() > 0) {
                $this->error('该日历在这一天已有休市规则，请直接编辑那一条！');
            }

            // 后台动过的行一律标人工，否则 market:import_calendar 重跑会把这次修改冲掉。
            // 代价是这一行从此不再跟随上游日历更新，这是「人工修改优先」的必然结果
            $data['source'] = self::SOURCE_MANUAL;

            if (empty($data['id'])) $data['create_ts'] = time();
            $data['update_ts'] = time();
        }
    }

    /**
     * 修改节假日状态
     *
     * status 语义对齐全站 CommonStatusEnum：1=启用 2=停用。显式挡非法值 ——
     * 列表开关只会发 1/2，但这个接口是暴露的，收到 0 会写进去而所有判定查询都是
     * where('status', 1)，表现为「关了但打不开」这种查不出原因的状态。
     * @auth true
     */
    public function holiday_state()
    {
        $status = (int)$this->request->post('status', 0);
        if (!in_array($status, [CommonStatusEnum::ON->value, CommonStatusEnum::OFF->value], true)) {
            $this->error('状态值非法，只接受 1(启用) 或 2(停用)！');
        }
        $this->_save($this->holidayTable);
    }

    /**
     * 删除节假日
     *
     * 删除是真删。一般情况下用「作废」（status=2）而不是删 —— 交易所撤销临时调休时
     * 留痕比删掉可查。删除留给「录错了一条从来没生效过的」。
     * @auth true
     */
    public function holiday_remove()
    {
        $this->_delete($this->holidayTable);
    }

    /**
     * 开市时段（周内固定规则）
     *
     * 本表是**开市白名单**：存的是「什么时候开」，休市是「没有任何一段覆盖」的自然结果。
     * 黄金配 5 行开市时段，周五 17:00 到周日 17:00 没有段覆盖，那 48 小时就是周末休市，
     * 不需要单独配一条「周末休市」规则。
     *
     * 排序按「星期 + 开盘时间」而不是 id：这张表是给人读的作息表，乱序的作息表没法核对。
     * @auth true
     * @menu true
     */
    public function session()
    {
        $this->title = '开市时段';
        $this->calendars = MarketCalendarEnum::selectOptions();
        $this->weekDays = CommonWeekDayEnum::labels();
        $this->statuses = CommonStatusEnum::labels();

        $query = $this->_query($this->sessionTable);
        $query->equal('calendar_code,open_week_day,status,source');
        return $query->order('calendar_code asc,open_week_day asc,open_time asc')->layTable();
    }

    /**
     * 时段列表的静态映射。
     *
     * Coding_Standards §10：静态文字/颜色一律在这里算好，模板只消费 _text / _color。
     *
     * duration_text 是算出来的、不存库。它的用处是让配错一眼可见 —— 时段配反或配成
     * 零长度时，列表直接显示异常时长，不用等到判定出问题才发现。
     *
     * @param array $data
     */
    protected function _session_page_filter(array &$data)
    {
        $weekDays = CommonWeekDayEnum::labels();

        foreach ($data as &$item) {
            $code = (string)($item['calendar_code'] ?? '');
            $calendar = MarketCalendarEnum::tryFrom($code);

            $item['calendar_text']  = $calendar ? $calendar->labelText() . ' (' . $code . ')' : $code . ' ⚠ 未知日历';
            $item['calendar_color'] = $calendar ? '' : 'color-red';
            $item['timezone_text']  = $calendar ? $calendar->timezone() : '-';

            $openWd  = (int)($item['open_week_day'] ?? 0);
            $closeWd = (int)($item['close_week_day'] ?? 0);
            $item['open_text']  = ($weekDays[$openWd] ?? '未知') . ' ' . (string)($item['open_time'] ?? '');
            $item['close_text'] = ($weekDays[$closeWd] ?? '未知') . ' ' . (string)($item['close_time'] ?? '');

            // 跨天段单独标出来：它是本表最容易被误删的一类 ——
            //「周四 17:00 到周五 17:00」看着像重复占了周四又占了周五
            $item['cross_text']  = $openWd !== $closeWd ? '跨天' : '';
            $item['cross_color'] = $openWd !== $closeWd ? 'color-orange' : '';

            $minutes = $this->sessionDuration($item);
            $item['duration_text']  = $minutes > 0 ? $this->formatDuration($minutes) : '⚠ 异常';
            $item['duration_color'] = $minutes > 0 ? '' : 'color-red';

            $item['source_text']  = ($item['source'] ?? '') === self::SOURCE_IMPORT ? '自动导入' : '人工录入';
            $item['source_color'] = ($item['source'] ?? '') === self::SOURCE_IMPORT ? 'color-desc' : '';
        }
        unset($item);
    }

    /**
     * 添加或编辑开市时段
     * @auth true
     */
    public function session_add()
    {
        $this->title = input('id') ? '编辑开市时段' : '添加开市时段';
        $this->_form($this->sessionTable, 'session_form');
    }

    /**
     * 时段表单校验。
     *
     * 每一条对应一种「配了但判定会出错」的静默失败，且都不是数据库约束能表达的：
     * 时段不重叠、长度合法，靠的全是这里。
     *
     * @param array $data
     */
    protected function _session_add_form_filter(array &$data)
    {
        if ($this->request->isGet()) {
            $data['open_week_day']  = isset($data['open_week_day']) ? (int)$data['open_week_day'] : CommonWeekDayEnum::MONDAY->value;
            $data['close_week_day'] = isset($data['close_week_day']) ? (int)$data['close_week_day'] : CommonWeekDayEnum::MONDAY->value;
            $data['status'] = isset($data['status']) ? (int)$data['status'] : CommonStatusEnum::ON->value;

            $this->calendars = MarketCalendarEnum::selectOptions();
            $this->weekDays = CommonWeekDayEnum::labels();
            $this->statuses = CommonStatusEnum::labels();
        }

        if ($this->request->isPost()) {
            $code = strtoupper(trim((string)($data['calendar_code'] ?? '')));
            if (MarketCalendarEnum::tryFrom($code) === null) {
                $this->error('交易日历非法，请从下拉中选择！');
            }
            $data['calendar_code'] = $code;

            $openWd  = (int)($data['open_week_day'] ?? 0);
            $closeWd = (int)($data['close_week_day'] ?? 0);
            if (CommonWeekDayEnum::tryFrom($openWd) === null)  $this->error('请选择开盘星期！');
            if (CommonWeekDayEnum::tryFrom($closeWd) === null) $this->error('请选择收盘星期！');

            $openTime  = trim((string)($data['open_time'] ?? ''));
            $closeTime = trim((string)($data['close_time'] ?? ''));
            if (!$this->isValidTime($openTime))  $this->error('开盘时间格式不正确，应为 HH:MM（24 小时制，两位补零）！');
            if (!$this->isValidTime($closeTime)) $this->error('收盘时间格式不正确，应为 HH:MM（24 小时制，两位补零）！');
            $data['open_time']  = $openTime;
            $data['close_time'] = $closeTime;

            // 开盘与收盘同一时刻：长度既能理解成 0 也能理解成整周，两种都不是运维想配的。
            // 不挡的话按跨周算会得到一条「全周开盘」的段，把该市场的休市判定整个废掉
            $start = CommonWeekDayEnum::minuteOfWeek($openWd, $openTime);
            $end   = CommonWeekDayEnum::minuteOfWeek($closeWd, $closeTime);
            if ($start === $end) {
                $this->error('开盘与收盘是同一时刻，这一段长度为零，请检查星期与时间！');
            }

            $this->assertSessionNoOverlap($code, $openWd, $openTime, $closeWd, $closeTime, (int)($data['id'] ?? 0));

            // 后台动过的行一律标人工，否则 market:import_calendar 重跑会把这次修改冲掉
            $data['source'] = self::SOURCE_MANUAL;

            if (empty($data['id'])) $data['create_ts'] = time();
            $data['update_ts'] = time();
        }
    }

    /**
     * 同一本日历内的时段不得重叠，重叠就报错。
     *
     * 为什么必须在周内分钟尺度上比、不能比 HH:MM 字符串：黄金那种「周日 17:00 →
     * 周一 17:00」的跨天段，两头的 HH:MM 完全相等，字符串比较得出的是「收盘不晚于开盘」。
     *
     * 为什么区间是**左闭右开** [start, end)：黄金相邻两段在 17:00 首尾相接
     * （周一段的收盘 == 周二段的开盘）。用闭区间的话 17:00 同时属于两段，
     * 25 行标准时段一行都存不进去。
     *
     * 只和 status=1 的行比：停用的时段不参与判定，自然也不该参与重叠检测，
     * 否则运维没法先停用旧段、再配新段。
     *
     * @param string $code
     * @param int    $openWd
     * @param string $openTime
     * @param int    $closeWd
     * @param string $closeTime
     * @param int    $excludeId 编辑时排除自己
     */
    protected function assertSessionNoOverlap(string $code, int $openWd, string $openTime, int $closeWd, string $closeTime, int $excludeId = 0): void
    {
        $news = $this->weekRanges($openWd, $openTime, $closeWd, $closeTime);
        $weekDays = CommonWeekDayEnum::labels();

        $map = [['calendar_code', '=', $code], ['status', '=', CommonStatusEnum::ON->value]];
        if ($excludeId > 0) $map[] = ['id', '<>', $excludeId];

        $rows = Db::name($this->sessionTable)->where($map)->select()->toArray();
        foreach ($rows as $row) {
            $olds = $this->weekRanges(
                (int)$row['open_week_day'], (string)$row['open_time'],
                (int)$row['close_week_day'], (string)$row['close_time']
            );
            foreach ($news as $a) {
                foreach ($olds as $b) {
                    // 左闭右开的重叠判据：a.start < b.end 且 b.start < a.end
                    if ($a[0] < $b[1] && $b[0] < $a[1]) {
                        $this->error(sprintf(
                            '与已有时段重叠：%s %s → %s %s，请先调整那一条！',
                            $weekDays[(int)$row['open_week_day']] ?? '未知', $row['open_time'],
                            $weekDays[(int)$row['close_week_day']] ?? '未知', $row['close_time']
                        ));
                    }
                }
            }
        }
    }

    /**
     * 把一段时段拆成 1–2 个周内分钟区间 [start, end)。
     *
     * 跨周的段（收盘偏移 <= 开盘偏移，如黄金周日 17:00 → 周一 17:00）在一维的
     * 0–10079 坐标上是断开的，必须拆成 [start, 10080) + [0, end) 两截才能做区间比较，
     * 否则「跨过周日午夜」那部分会被漏掉。
     *
     * @param int    $openWd
     * @param string $openTime
     * @param int    $closeWd
     * @param string $closeTime
     * @return array
     */
    protected function weekRanges(int $openWd, string $openTime, int $closeWd, string $closeTime): array
    {
        $start = CommonWeekDayEnum::minuteOfWeek($openWd, $openTime);
        $end   = CommonWeekDayEnum::minuteOfWeek($closeWd, $closeTime);

        if ($end > $start) return [[$start, $end]];
        return [[$start, CommonWeekDayEnum::MINUTES_PER_WEEK], [0, $end]];
    }

    /**
     * 一段时段的时长（分钟）。跨周段自动补一周。
     *
     * @param array $item 时段行
     * @return int 分钟；<=0 表示数据异常
     */
    protected function sessionDuration(array $item): int
    {
        $openTime  = (string)($item['open_time'] ?? '');
        $closeTime = (string)($item['close_time'] ?? '');
        if (!$this->isValidTime($openTime) || !$this->isValidTime($closeTime)) return 0;

        $start = CommonWeekDayEnum::minuteOfWeek((int)($item['open_week_day'] ?? 0), $openTime);
        $end   = CommonWeekDayEnum::minuteOfWeek((int)($item['close_week_day'] ?? 0), $closeTime);

        $diff = $end - $start;
        if ($diff <= 0) $diff += CommonWeekDayEnum::MINUTES_PER_WEEK;
        return $diff;
    }

    /**
     * 分钟数格式化成「6.5 小时」这类人话。
     *
     * 整小时不带小数（24 小时而不是 24.0 小时），半小时保留一位。
     *
     * @param int $minutes
     * @return string
     */
    protected function formatDuration(int $minutes): string
    {
        $hours = $minutes / 60;
        $text = floor($hours) == $hours ? (string)(int)$hours : number_format($hours, 1);
        return $text . ' 小时';
    }

    /**
     * 修改时段状态
     *
     * status 语义对齐全站 CommonStatusEnum：1=启用 2=停用。停用的时段不参与休市判定，
     * 也不参与重叠检测 —— 这让运维可以先停旧段再配新段。
     * @auth true
     */
    public function session_state()
    {
        $status = (int)$this->request->post('status', 0);
        if (!in_array($status, [CommonStatusEnum::ON->value, CommonStatusEnum::OFF->value], true)) {
            $this->error('状态值非法，只接受 1(启用) 或 2(停用)！');
        }
        $this->_save($this->sessionTable);
    }

    /**
     * 删除开市时段
     *
     * 删一段的后果比删一条假日重：假日少一条只是那天照常开盘，时段少一段是**那段时间
     * 永远判为休市**，该市场的一块作息直接消失。所以一般用停用而不是删除。
     * @auth true
     */
    public function session_remove()
    {
        $this->_delete($this->sessionTable);
    }

    /**
     * 校验 YYYY-MM-DD，且必须是真实存在的日期。
     *
     * 不能只用正则：2026-02-30 能过正则但不是真日期，落库后那条规则永远不会被命中。
     *
     * @param string $date
     * @return bool
     */
    protected function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return checkdate($m, $d, $y);
    }

    /**
     * 校验 HH:MM（24 小时制，必须两位补零）。
     *
     * 补零是硬要求：这两个值要和时段表的 HH:MM 做字符串比较，'9:30' 与 '09:30'
     * 字符串比大小的结果是反的（'9' > '0'），会静默把时间判反。
     *
     * @param string $time
     * @return bool
     */
    protected function isValidTime(string $time): bool
    {
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }
}
