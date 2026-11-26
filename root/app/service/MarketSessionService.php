<?php

declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use app\enum\CommonStatusEnum;
use app\enum\MarketCalendarEnum;
use app\enum\MarketHolidayTypeEnum;
use app\enum\MarketSessionStateEnum;

/**
 * 休市判定 —— 给定一本交易日历与一个时刻，回答「此刻能不能交易」
 *
 * 纯函数：f(calendar_code, ts) -> OPEN | CLOSED。输入全在内存，一次库都不查 ——
 * 消费方里有 500ms 循环 + 批量仓位（checkStopOrders / checkLiquidation），每仓查两次库扛不住。
 *
 * ── 缓存的是日历数据，不是判定结果 ────────────────────────────────────────
 * 这是本服务最容易被改错的地方。「是否休市」是 f(日历, now) 的派生值，每分钟都在变；
 * 把它缓存下来就必须有个进程去定时刷新，于是凭空多出两个故障模式：
 *   刷新周期决定边界精度 —— 开盘边界精确到分钟（09:30:00），扫表刷新做不到
 *   刷新进程挂掉 = 状态冻结在旧值 = 休市期间显示开市，而且是**静默的**
 * 缓存日历数据则没有这些问题：它只在 market:import_calendar 跑完时变，判定永远是现算的。
 *
 * 分层沿用 MarketService 的既有模式：进程内一级(3s) + Redis 二级(60s)。
 * 一本日历的数据量很小（时段最多几十行、假日两年 20-30 行），整本一起缓存。
 *
 * ── 判定规则（与 tools/verify_calendar_rule.py 必须零偏差）────────────────
 *   (1) 此刻落在时段表某段内吗？否 -> CLOSED
 *   (2) 该段的【收盘日】有 close_type=1 的假日吗？是 -> CLOSED（整段不开）
 *   (3) 该段的收盘日有 close_type=2 的假日吗？把本段裁剪到 [open_time, close_time]
 *       —— 上下限是绝对时刻：open_time 绑开盘日、close_time 绑收盘日
 *   close_type=3 (OVERRIDE) 则是整段**替换**成 [open_time, close_time]，可以比常规更长。
 *
 * 假日按**收盘日**归属，不是按自然日 —— 交易所的「一天」不从午夜算
 * （黄金一个交易日是「前日 17:00 -> 当日 17:00」）。改成按开盘日归属，2026 年会错 56 小时，
 * 其中约 1/4 是「不该开却放行」。理由全文见 market_calendar_session 迁移的类注释。
 *
 * ⚠️ 改动本类的判定逻辑后**必跑** `python tools/verify_calendar_rule.py`，偏差必须为 0。
 *
 * 影响面
 *   配置  无 —— 日历随代码走（migration + seed），判定不读配置
 *   消费  阶段 3.3 起的各豁免与拦截点（见 .agents/plan/market_session_phase3.md §5.5 落点表）
 *   落地  读 market_calendar_session / market_calendar_holiday，只读不写
 *   缓存  Redis market:session:cal:{code}（日历数据，TTL 60s）
 *
 * 设计依据：.agents/plan/market_session_phase3.md §3.2
 */
class MarketSessionService
{
    /** 进程内一级缓存有效期（秒） */
    private const LOCAL_TTL = 3;

    /** Redis 二级缓存有效期（秒）；兜底防止 clearCache() 未被触发时的长期脏数据 */
    private const REDIS_TTL = 60;

    /** Redis key 前缀 */
    private const REDIS_PREFIX = 'market:session:cal:';

    /**
     * 往前回溯几天找「可能覆盖此刻」的开盘日。
     *
     * 一段最长跨 7 天（span 取模 7，再加上 end <= start 时补的整周），所以 7 天必然够；
     * 取 8 是留一天余量，代价只是每次判定多循环一轮空转。
     */
    private const LOOKBACK_DAYS = 8;

    /** 同一个 calendar_code 的「日历用不了」告警最多多少秒报一次 */
    private const WARN_INTERVAL = 300;

    /** @var array<string, array{data: array, expire: int}> 进程内缓存：calendar_code -> 日历数据 */
    private static array $localCache = [];

    /** @var array<string, int> 告警节流：calendar_code -> 上次上报时刻 */
    private static array $warnedAt = [];

    /** market_data 的 source_key -> calendar_code 映射有效期（秒），见 calendarOfSource() */
    private const SOURCE_MAP_TTL = 60;

    /** @var array{map: array<string,string>, expire: int}|null source_key -> calendar_code，整表缓存，只在进程内 */
    private static ?array $sourceMap = null;

    /** @var array{map: array<int,string>, expire: int}|null pair_id -> source_key，同上 */
    private static ?array $pairMap = null;

    /** 后台休市开关在 system_config 里的坐标（type / name），对应 sysconf('market_session.enabled') */
    private const GATE_TYPE = 'market_session';
    private const GATE_NAME = 'enabled';

    /** 开关的进程内缓存有效期（秒）。要够短 —— 它是出事时用来止血的，不是调优项 */
    private const GATE_TTL = 10;

    /**
     * 本类全部查询的单批行数（Coding_Standards §12：SELECT 必须带 LIMIT）。
     *
     * 四处读取都走 chunk：每批带 LIMIT 满足条款，又能读全 —— 直接 `limit(N)` 截断的话，
     * 少掉的若是假日，方向就是「节假日照常开市」，是本模块通篇在躲的那个。
     * 框架的 chunk 按主键游标翻页（`where id > lastId`）而非 offset，读取期间有插入也不会重复或漏。
     *
     * 1000 是相对现网数据量（单本日历 20-30 行、market_data 151 行、exchange_pair 百量级）
     * 一次就读完的值，翻页逻辑只在数据涨上去之后才真正生效。
     */
    private const CHUNK_SIZE = 1000;

    /** @var array{on: bool, expire: int}|null 开关状态缓存 */
    private static ?array $gate = null;


    /**
     * 判定某本日历此刻（或指定时刻）是开市还是休市。
     *
     * @param string   $calendarCode ISO 10383 MIC，空串 = 24/7 标的，直接 OPEN
     * @param int|null $ts           UTC 秒级时间戳，null = 此刻
     */
    public static function state(string $calendarCode, ?int $ts = null): MarketSessionStateEnum
    {
        $calendarCode = trim($calendarCode);

        // 空串 = 24/7 加密标的，短路返回 OPEN，不查任何表。
        // 零回归的关键：现网全部标的走的都是这条路径。
        if ($calendarCode === '') {
            return MarketSessionStateEnum::OPEN;
        }

        $calendar = MarketCalendarEnum::tryFrom($calendarCode);
        if ($calendar === null) {
            // 认不出的日历判 CLOSED + 告警，口径见 MarketCalendarEnum 类注释
            self::warnUnusable($calendarCode);
            return MarketSessionStateEnum::FALLBACK;
        }

        $ts ??= time();

        try {
            $data = self::calendar($calendarCode);
        } catch (\Throwable $e) {
            // 库/Redis 不可用时判 CLOSED 而不是 OPEN：拿不准一律往安全方向倒
            business_log('market_session', "[Session] {$calendarCode} 日历读取失败，按 CLOSED 处理: " . $e->getMessage(), 'error');
            return MarketSessionStateEnum::FALLBACK;
        }

        if ($data['sessions'] === []) {
            // 有 MIC 但一条时段都没有 = 导入没跑过或被清空。时段表是开市白名单，
            // 空白名单的自然结果就是全时段休市，这里只是把它说破并留痕。
            self::warnUnusable($calendarCode, '该日历没有任何开市时段（导入跑过吗）');
            return MarketSessionStateEnum::FALLBACK;
        }

        return self::judge($data, $calendar->timezone(), $ts);
    }

    /** state() 的布尔快捷式 */
    public static function isOpen(string $calendarCode, ?int $ts = null): bool
    {
        return self::state($calendarCode, $ts)->tradable();
    }

    /**
     * 按行情源判定 —— 消费方手里大多只有 source_key，没有 calendar_code。
     *
     * 日历归属挂在 market_data（源维度），不在 exchange_pair（pair 维度）：每台机的 pair 集合
     * 不同，挂 pair 会让三机各算各的；同一标的从不同源拿数据作息也可能不同（XAU 从 CME 拿跟
     * CMES，XAUT 从币安拿是 24/7）。依据见 market_calendar_overview.md §4.12。
     */
    public static function stateBySourceKey(string $sourceKey, ?int $ts = null): MarketSessionStateEnum
    {
        return self::state(self::calendarOfSource($sourceKey), $ts);
    }

    /** stateBySourceKey() 的布尔快捷式 */
    public static function isOpenBySourceKey(string $sourceKey, ?int $ts = null): bool
    {
        return self::stateBySourceKey($sourceKey, $ts)->tradable();
    }

    /**
     * source_key -> calendar_code。查不到返回空串（= 24/7 走 OPEN），**不是 CLOSED**。
     *
     * 两种「拿不到日历」语义不同，不能混成一种：
     *   market_data 里没这一行      没配过休市 -> 空串 -> OPEN（保持现状）
     *   有 calendar_code 但认不出    明确配了一本用不了的日历 -> state() 判 CLOSED + 告警
     * 前者判 CLOSED 会把一次数据不一致升级成全线停摆，而它在现网是常态 ——
     * market_data 与 exchange_pair 本来就不是一一对应（§4.12：151 行源里 77 行还没有 pair 绑定）。
     *
     * 整表缓存：market_data 是「一个源一行」的注册表，全项目没有一处 INSERT，
     * 行由 database/market_data.sql 种子预置，运行期只按 source_key UPDATE 刷价格。
     */
    public static function calendarOfSource(string $sourceKey): string
    {
        $sourceKey = trim($sourceKey);
        if ($sourceKey === '') {
            return '';
        }

        return self::sourceMap()[$sourceKey] ?? '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 消费入口（tradable*）—— **所有**消费方都用这一套，不要直接调 isOpen*
    //
    //   tradable*   看开关。开关关掉时恒为 true，全站精确回到「没有休市功能」的状态。
    //   isOpen*     不看开关的裸判定，只留给后台展示与调试（「抛开开关，这本日历此刻判什么」）。
    //
    // 曾经想过让告警侧走 isOpen*（开关只管拦截，告警继续按日历抑制），2026-09-24 否掉：
    // 关开关最常见的动机就是**日历判错了**，那时还让告警继续信这份日历，
    // 等于「因为它不可信而关掉它，却仍然用它屏蔽告警」—— 该时段行情真坏了没人知道。
    // 代价是开关关掉后休市期间会重新报「行情价格异常」，但那只在日历正确且确实休市时发生，
    // 而那种情况下本来就没有理由关开关。宁可语义简单可预期。
    //
    // 做成两个名字而不是让每个调用点自己写 `gateEnabled() && isOpen()`：
    // 以后新增消费点的人用 tradable* 就自动带上开关，漏写这种错靠命名防，不靠记性。
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 本站此刻需不需要做休市判定 —— 消费方可以据此整段跳过。
     *
     * 两种「不需要」：开关关掉，或者一个源都没配日历（现网绝大多数是 24/7 加密标的）。
     */
    public static function active(): bool
    {
        return self::gateEnabled() && self::anyCalendarConfigured();
    }

    /** 拦截侧：这个 pair 现在能不能交易。开关关掉时恒为 true（当作开市） */
    public static function tradableByPairId(int $pairId, ?int $ts = null): bool
    {
        return !self::gateEnabled() || self::isOpenByPairId($pairId, $ts);
    }

    /** 拦截侧：这个行情源现在能不能交易。开关关掉时恒为 true */
    public static function tradableBySourceKey(string $sourceKey, ?int $ts = null): bool
    {
        return !self::gateEnabled() || self::isOpenBySourceKey($sourceKey, $ts);
    }

    /**
     * 后台的休市流程总开关。关掉之后拦截侧完全不查休市，走回原本的流程。
     *
     * ⚠️ **不走 sysconf()**：SystemService::get() 把整份配置塞进 sysvar('think.admin.config')，
     * 那是进程级静态变量、一旦填充永不过期。Workerman 常驻进程（撮合 / 做市 / 风控）第一次读完
     * 就再也不查库，后台点了关也要重启才生效 —— 那就不叫应急开关了。
     * 所以这里直接查表，只认 system_config 的一行，10 秒内全站生效。
     *
     * 缺配置 = 开启：默认关闭会让「装好了但没人配过」表现为休市功能**静默失效**，
     * 而这个模块通篇在躲的就是静默。后台页面保存时会显式写入 1 或 2。
     */
    public static function gateEnabled(): bool
    {
        $now = time();
        if (self::$gate !== null && self::$gate['expire'] > $now) {
            return self::$gate['on'];
        }

        try {
            $raw = Db::name('system_config')
                ->where('type', self::GATE_TYPE)
                ->where('name', self::GATE_NAME)
                ->value('value');
        } catch (\Throwable $e) {
            // 库抖动时保持上一次的结论，没读到过就按开启。不缓存这个结果，下一轮重试
            return self::$gate['on'] ?? true;
        }

        $on = ($raw === null || $raw === '')
            ? true
            : (int)$raw === CommonStatusEnum::ON->value;

        self::$gate = ['on' => $on, 'expire' => $now + self::GATE_TTL];
        return $on;
    }

    /**
     * 按 pair 判定 —— 下单、撮合、做市这些落点手里只有 pair_id。
     *
     * 多一跳 pair_id -> source_key -> calendar_code：日历不能挂在 pair 上（§4.12，每台机
     * pair 集合不同），但消费方确实大多只有 pair_id，映射这一跳省不掉，只能缓存。
     */
    public static function stateByPairId(int $pairId, ?int $ts = null): MarketSessionStateEnum
    {
        if ($pairId <= 0) {
            return MarketSessionStateEnum::OPEN;
        }
        return self::stateBySourceKey(self::pairMap()[$pairId] ?? '', $ts);
    }

    /** stateByPairId() 的布尔快捷式 */
    public static function isOpenByPairId(int $pairId, ?int $ts = null): bool
    {
        return self::stateByPairId($pairId, $ts)->tradable();
    }

    /**
     * pair_id -> source_key 的整表映射，进程内缓存 SOURCE_MAP_TTL 秒。
     *
     * 整表而不是按需 whereIn：消费方多是常驻进程里的高频循环（做市 10s、风控 500ms），
     * 按需查会把每一轮都变成一次 IO。exchange_pair 行数是百量级，整表很轻。
     *
     * @return array<int,string>
     */
    private static function pairMap(): array
    {
        $now = time();
        if (self::$pairMap !== null && self::$pairMap['expire'] > $now) {
            return self::$pairMap['map'];
        }

        $out = [];
        try {
            // field 必须带主键：chunk 靠它翻页
            Db::name('exchange_pair')->field('id,source_key')
                ->chunk(self::CHUNK_SIZE, function ($rows) use (&$out) {
                    foreach ($rows as $row) {
                        $out[(int)$row['id']] = (string)$row['source_key'];
                    }
                });
        } catch (\Throwable $e) {
            business_log('market_session', '[Session] exchange_pair 源映射读取失败: ' . $e->getMessage(), 'error');
            return [];
        }

        self::$pairMap = ['map' => $out, 'expire' => $now + self::SOURCE_MAP_TTL];

        return $out;
    }

    /**
     * 全库有没有任何源配了日历。
     *
     * 给消费方一个整段跳过的短路：一个都没配就等于全是 24/7 标的，
     * 逐行判定纯属空转（现网绝大多数是加密标的）。映射已缓存，本方法几乎零成本。
     */
    public static function anyCalendarConfigured(): bool
    {
        foreach (self::sourceMap() as $code) {
            if ($code !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * source_key -> calendar_code 的整表映射，进程内缓存 SOURCE_MAP_TTL 秒。
     *
     * 读不到时返回空数组（= 全部当 24/7），不抛也不判 CLOSED —— 理由见 calendarOfSource()。
     *
     * @return array<string,string>
     */
    private static function sourceMap(): array
    {
        $now = time();
        if (self::$sourceMap !== null && self::$sourceMap['expire'] > $now) {
            return self::$sourceMap['map'];
        }

        $map = [];
        try {
            Db::name('market_data')->field('id,source_key,calendar_code')
                ->chunk(self::CHUNK_SIZE, function ($rows) use (&$map) {
                    foreach ($rows as $row) {
                        $map[(string)$row['source_key']] = trim((string)($row['calendar_code'] ?? ''));
                    }
                });
        } catch (\Throwable $e) {
            business_log('market_session', '[Session] market_data 日历映射读取失败: ' . $e->getMessage(), 'error');
            return [];
        }

        self::$sourceMap = ['map' => $map, 'expire' => $now + self::SOURCE_MAP_TTL];

        return $map;
    }

    /**
     * 判定主体 —— 与 tools/verify_calendar_rule.py 的 rule_open_minutes() 逐条对应。
     *
     * 那边是「枚举全年所有 OPEN 分钟」，这边是「给定一个时刻反查」，方向相反但规则同一套：
     * 校验脚本正向遍历每个开盘日，本方法则从此刻往前回溯 LOOKBACK_DAYS 天找候选开盘日。
     */
    private static function judge(array $data, string $timezone, int $ts): MarketSessionStateEnum
    {
        $zone   = new \DateTimeZone($timezone);
        $moment = (new \DateTimeImmutable('@' . $ts))->setTimezone($zone);

        // 本地自然日的零点。DST 跳变日某些本地时刻不存在（如春季的 02:30），PHP 会自动推到
        // 存在的那一刻 —— 与校验脚本 ZoneInfo 的处理一致，且交易时段极少落在跳变点上。
        $midnight = $moment->setTime(0, 0);

        for ($offset = 0; $offset <= self::LOOKBACK_DAYS; $offset++) {
            $openDay = $offset === 0 ? $midnight : $midnight->modify("-{$offset} days");
            $dow     = (int)$openDay->format('N');   // ISO 1=周一 .. 7=周日

            foreach ($data['sessions'] as $row) {
                if ((int)$row['open_week_day'] !== $dow) {
                    continue;
                }

                $span     = ((int)$row['close_week_day'] - (int)$row['open_week_day'] + 7) % 7;
                $closeDay = $span === 0 ? $openDay : $openDay->modify("+{$span} days");

                $start = self::atTime($openDay, (string)$row['open_time']);
                $end   = self::atTime($closeDay, (string)$row['close_time']);

                // 同星期且收盘不晚于开盘 = 跨整周（如周一 17:00 -> 周一 17:00），补 7 天。
                // 必须在应用假日之前做，与校验脚本同序。
                if ($end <= $start) {
                    $end = $end->modify('+7 days');
                }

                // (2)(3) 假日按本段【收盘日】归属
                $holiday = $data['holidays'][$closeDay->format('Y-m-d')] ?? null;
                $floor   = null;
                $cap     = null;

                if ($holiday !== null) {
                    if ((int)$holiday['close_type'] === MarketHolidayTypeEnum::FULL_CLOSE->value) {
                        continue;                       // 整段不开
                    }
                    if ((string)$holiday['open_time'] !== '') {
                        $floor = self::atTime($openDay, (string)$holiday['open_time']);
                    }
                    if ((string)$holiday['close_time'] !== '') {
                        $cap = self::atTime($closeDay, (string)$holiday['close_time']);
                    }
                    // OVERRIDE：整段**替换**而不是裁剪，可以让区间变长（XTAE 逾越节前的周五延后收盘）。
                    // 两头必定都有值 —— 只给一头的行被 MarketImportCalendar 挡在导入之外。
                    if ((int)$holiday['close_type'] === MarketHolidayTypeEnum::OVERRIDE->value
                        && $floor !== null && $cap !== null) {
                        $start = $floor;
                        $end   = $cap;
                    }
                }

                // 区间左闭右开 [start, end)：相邻两段在同一时刻首尾相接，闭区间会让该时刻同属两段
                if ($moment < $start || $moment >= $end) {
                    continue;
                }
                if ($floor !== null && $moment < $floor) {
                    continue;
                }
                if ($cap !== null && $moment >= $cap) {
                    continue;
                }

                return MarketSessionStateEnum::OPEN;
            }
        }

        return MarketSessionStateEnum::CLOSED;
    }

    /** 把 HH:MM 贴到某个本地自然日上 */
    private static function atTime(\DateTimeImmutable $day, string $hhmm): \DateTimeImmutable
    {
        [$h, $m] = array_pad(explode(':', $hhmm, 2), 2, '0');
        return $day->setTime((int)$h, (int)$m);
    }

    /**
     * 取一本日历的时段与假日，三层回落：进程内 -> Redis -> MySQL。
     *
     * @return array{sessions: array<int,array>, holidays: array<string,array>}
     */
    private static function calendar(string $code): array
    {
        $now    = time();
        $cached = self::$localCache[$code] ?? null;
        if ($cached !== null && $cached['expire'] > $now) {
            return $cached['data'];
        }

        $redis = redis_client();
        if ($redis) {
            $json = $redis->get(self::REDIS_PREFIX . $code);
            if (is_string($json) && $json !== '') {
                $data = json_decode($json, true);
                if (is_array($data) && isset($data['sessions'], $data['holidays'])) {
                    self::$localCache[$code] = ['data' => $data, 'expire' => $now + self::LOCAL_TTL];
                    return $data;
                }
            }
        }

        $data = self::loadFromDb($code);

        if ($redis) {
            $redis->setex(self::REDIS_PREFIX . $code, self::REDIS_TTL, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        self::$localCache[$code] = ['data' => $data, 'expire' => $now + self::LOCAL_TTL];

        return $data;
    }

    /**
     * 从库里读一本日历。两张表都只取 status=1 —— 停用的行不参与判定（见两张表的列注释）。
     *
     * 假日按日期做成 map，判定时 O(1) 命中，不必每段遍历一遍假日列表。
     *
     * @return array{sessions: array<int,array>, holidays: array<string,array>}
     */
    private static function loadFromDb(string $code): array
    {
        $on = CommonStatusEnum::ON->value;

        // field 必须带主键：chunk 靠它翻页
        $sessions = [];
        Db::name('market_calendar_session')
            ->where('calendar_code', $code)
            ->where('status', $on)
            ->field('id,open_week_day,open_time,close_week_day,close_time')
            ->chunk(self::CHUNK_SIZE, function ($rows) use (&$sessions) {
                foreach ($rows as $row) {
                    $sessions[] = $row;
                }
            });

        $holidays = [];
        Db::name('market_calendar_holiday')
            ->where('calendar_code', $code)
            ->where('status', $on)
            ->field('id,holiday_date,close_type,open_time,close_time')
            ->chunk(self::CHUNK_SIZE, function ($rows) use (&$holidays) {
                foreach ($rows as $row) {
                    // holiday_date 取回来可能带时间部分，统一切成 Y-m-d 与判定侧的 format('Y-m-d') 对齐
                    $holidays[substr((string)$row['holiday_date'], 0, 10)] = $row;
                }
            });

        return ['sessions' => $sessions, 'holidays' => $holidays];
    }

    /**
     * 「这本日历用不了」的告警，按 calendar_code 节流。
     *
     * 判定函数会被 500ms 循环高频调用，不节流的话一本配错的日历就能把日志刷爆 ——
     * 而刷爆的日志等于没有日志，正是这个模块反复在躲的形状。
     */
    private static function warnUnusable(string $code, string $why = 'MarketCalendarEnum 里没有这本日历'): void
    {
        $now = time();
        if (($now - (self::$warnedAt[$code] ?? 0)) < self::WARN_INTERVAL) {
            return;
        }
        self::$warnedAt[$code] = $now;

        business_log('market_session', "[Session] calendar_code={$code} {$why}，该标的一律判休市。"
            . '需要交易请先在 MarketCalendarEnum 建好对应日历并导入数据。', 'error');
    }

    /** 清掉某本（或全部）日历的缓存 —— 导入命令写完数据后调用 */
    public static function clearCache(string $code = ''): void
    {
        $redis = redis_client();

        if ($code === '') {
            self::$localCache = [];
            self::$sourceMap  = null;
            self::$pairMap    = null;
            if ($redis) {
                $keys = $redis->keys(self::REDIS_PREFIX . '*');
                if ($keys) {
                    $redis->del($keys);
                }
            }
            return;
        }

        unset(self::$localCache[$code]);
        if ($redis) {
            $redis->del(self::REDIS_PREFIX . $code);
        }
    }
}
