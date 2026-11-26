<?php

declare(strict_types=1);

namespace app\enum;

use app\enum\lib\Label;

/**
 * 交易日历（ISO 10383 MIC）—— 标的跟哪一套交易作息走
 *
 * 70 个 case：68 本是 exchange_calendars 4.13.2 里全部真实交易所日历，值是 MIC；
 * 另 2 本 BINGX_* 是数据源的手写作息（不是 MIC），见该组注释。
 *
 * ── 为什么是全量，不是「用到哪本加哪本」 ──────────────────────────────
 * 「enum 里有这个 case」与「这本日历有数据」必须是同一件事。
 * 只建用到的 case，运维在后台给标的选一本没数据的日历时，判定第一步
 * 「此刻落在时段表某段内吗」就过不去 —— 该标的**永远判休市**，而后台看上去是配好了的。
 * 全量建 case + 全量导数据之后这个陷阱不存在：`market:import_calendar` 一次把 68 本
 * 的假日与时段都灌进去，enum 里能选到的每一本都有完整数据。
 *
 * 代价只有导出耗时与 JSON 体积。健康体检只遍历 market_data 里**真有行情源跟随**的日历，
 * 所以 60 多本没人用的不会产生任何额外告警。
 *
 * ── 库里有、这里刻意没有的 3 本 ───────────────────────────────────
 * 不是漏了。少一本的后果是那批标的节假日照常开盘 —— 最难发现的方向，所以逐条记在案：
 *
 *   24/5 · 24/7    伪日历。24/7 做成 case 会与「calendar_code 空串 = 24/7 加密标的」的
 *                  短路路径打架，让「无休市」与「有一本空日历」在类型上分不开
 *   us_futures     Quantopian 的合成日历（CFE/CME/ICE 的并集），不是真实交易所也不是 MIC
 *
 * ⚠ XSGO 的**上游数据是错的**（库里硬编码的收盘时间），已在导出侧处理，见该 case 注释。
 * verify_calendar_rule.py 对它恒报 80h FAIL —— 那是「库错了」不是「我们错了」，脚本无从区分。
 * （XTAE 曾是真正「表达不了」的那一个，2026-09-18 新增 close_type=3 后已解决。）
 *
 * ── Label 有两套命名逻辑，是有意的 ────────────────────────────────
 * 前 6 本（XNYS / CMES / IEPA / XHKG / XKRX / XSHG）叫「美股作息」「CME 商品作息」这样的
 * **作息名**，刻意不叫交易所名 —— 因为它们各自覆盖多个挂牌地，叫「纽约证券交易所」会把人
 * 往上市地引。其余 60 本没有这种通行的作息叫法，只能用交易所中文名。
 * 新增标的归类时以类注释为准，别只看 Label。
 *
 * ⚠ **值是 MIC，语义却是「一套作息」，两者不等价** —— 这是本枚举最容易踩的坑。
 * `XNYS` 不表示「在纽交所上市」：AAPL 挂 NASDAQ、SPY 挂 NYSE Arca，一样用 XNYS，
 * 因为三家作息完全相同。
 *
 * **新增标的的归类依据是「作息表是否相同」，不是「在哪个交易所上市」。**
 * 作息不同就必须各走各的，哪怕假日完全一样 —— IEPA 这本就是这么来的：Brent（BZ）在 ICE
 * 交易，假日与 CMES 一天不差，当初据此归到 CMES，但它每天晚开 2 小时，用 CMES 时段每年
 * 有 506 小时把没开盘判成开盘。2026-09-17 新开 IEPA 并把 BZ 迁过去修掉。
 * 核对假日相同**不足以**共用一本日历，时段要逐条一起比。
 *
 * **空串不是 case**。日历归属列取空串表示 24/7 加密标的，在判定入口短路返回 OPEN，
 * 根本走不到这里。做成 case 会让「无休市」和「有一本空日历」在类型上分不开。
 *
 * 一个 MIC 覆盖多个真实挂牌地是有意的，但**必须假日与时段都相同**才可以合并。
 * 2026-09-15 / 2026-09-17 用 exchange_calendars 4.13.2 实测：
 *   XNYS ≡ XNAS                假日相同、时段相同 → 可共用（AAPL 挂 NASDAQ、SPY 挂 Arca）
 *   CMES ≡ COMEX ≡ NYMEX       假日相同、时段相同 → 可共用（黄金白银铜在 COMEX，原油天然气铂钯在 NYMEX）
 *   CMES ≢ IEPA(ICE)           全日休市 6 天一天不差，但**时段差 2 小时** → 各存一本
 * 这三条是库自己的别名表印证的：它把作息相同的交易所做成同一本的别名
 * （CMES <- CBOT CME COMEX NYMEX，XNYS <- ARCX BATS NASDAQ NYSE OOTC XASE XNAS），
 * 照正名走即可。
 *
 * tryFrom() 返回 null（DB 里出现本枚举没有的值）时按**告警 + 判 CLOSED** 处置（2026-09-23 定案）：
 * 该标的下不了单会被当场报障；反过来当 24/7 就是「节假日照常开盘」，本模块通篇要躲的那个方向。
 *
 * 影响面
 *   配置  无 —— 日历随代码走（market_session_module.md §六-4），刻意不做成可编辑配置
 *   消费  休市判定（阶段三）· business/MarketCalendar 后台 · market:import_calendar
 *   校验  MarketCalendar::_holiday_add_form_filter · MarketImportCalendar
 *   落地  market_data.calendar_code（源维度，取值随 database/market_data.sql 种子落库）
 *         · market_calendar_holiday.calendar_code · market_calendar_session.calendar_code
 *   前端  无 —— 未开 EXPORT_API，MIC 不对前端暴露
 *
 * 设计依据：.agents/plan/market_calendar_module.md §2.1 / §2.2
 */
enum MarketCalendarEnum: string
{
    use BaseEnum;

    // ── 美洲 10 ──────────────────────────────────────────────────

    #[Label('美股作息')]
    case XNYS = 'XNYS';

    #[Label('CME 商品作息')]
    case CMES = 'CMES';

    /**
     * ICE 商品作息 —— 目前只有 Brent 原油（BZ）跟它走，但必须独立成一本。
     *
     * 与 CMES 的**全日休市完全相同**（2026–2027 实测 6 天一天不差），差别全在时段：
     *   换算到纽约时间   CMES 周日 18:00 开 → 周一 18:00 收   24 小时
     *                    IEPA 周日 20:00 开 → 周一 18:00 收   22 小时   ← 晚开 2 小时
     * 253 个交易日 × 2 小时 = 每年 506 小时，全部是「没开盘却判开市」的方向。
     *
     * 假日与 CMES 重复存一份是有意付的代价：要共用得拆成 calendar_code + session_code
     * 两列，但半日市（CMES 15 天 vs IEPA 12 天 —— 黑五、平安夜 CME 提前收、ICE 照常）
     * 属于时段维度却存在假日表里，拆开后同一张表要按 close_type 分两种归属规则，
     * 判定每查一行都得先看类型。净收益只有 3 条/年，不值。依据见
     * .agents/plan/market_calendar_overview.md §5.3。
     *
     * ⚠ 严格说 Brent 在 ICE Futures **Europe**（IFEU），而本 MIC 是 ICE Futures **US**，
     * 库里没有 IFEU。所以这仍是近似 —— 但比套 CMES 准得多。
     */
    #[Label('ICE 商品作息')]
    case IEPA = 'IEPA';

    #[Label('CBOE 期货交易所')]
    case XCBF = 'XCBF';

    #[Label('多伦多证券交易所')]
    case XTSE = 'XTSE';

    #[Label('巴西证券交易所')]
    case BVMF = 'BVMF';

    #[Label('墨西哥证券交易所')]
    case XMEX = 'XMEX';

    #[Label('布宜诺斯艾利斯证券交易所')]
    case XBUE = 'XBUE';

    /** 波哥大。库给的时区是 America/New_York 而不是 America/Bogota —— 照库的值走，别「修正」 */
    #[Label('哥伦比亚证券交易所')]
    case XBOG = 'XBOG';

    #[Label('利马证券交易所')]
    case XLIM = 'XLIM';

    /**
     * 圣地亚哥。⚠ **上游库的数据是错的**，不是本模块表达不了 —— 与 XTAE 不是同一类问题。
     *
     * exchange_calendar_xsgo.py 把收盘硬编码成「1980-2050 每年 3 月切 16:00、11 月切 17:00」，
     * 是写死的不是观测值（版权头 2019 Quantopian，adhoc_holidays 停在 2022）。
     * 2026-09-18 三方核对：股票市场现已全年 09:30-16:00，那个 17:00 属于固定收益市场
     * （Renta Fija），很可能是被按到了股票日历上。
     *
     * 导出侧按「这条想把区间改**大**」丢弃该批记录（168 -> 8 条），判据见
     * tools/export_exchange_calendars.py 的 STALE_UPSTREAM —— 整个导出流程里**唯一**
     * 不以 exchange_calendars 为准的地方，门槛是必须有官方或多个独立来源的核对结论。
     *
     * ⚠ verify_calendar_rule.py 对本日历恒报 80h FAIL：它的真值取自库，而库正是错的那一方，
     * 脚本无从区分。**看到这个 FAIL 不要去改判定逻辑。**
     *
     * ⚠ 另有一处未修：2026-07-20 起连续交易从 09:30 提前到 09:05（nuam/Nasdaq Eqlipse 上线），
     * 库仍是 09:30。方向是误拦（判休市、实际开市），安全但确实错了。
     *
     * 核查依据与「换时区基准解决不了」的实测见 .agents/plan/market_calendar_overview.md §4.9
     * —— 那里同时纠正了一个曾经写在本注释里的错误因果，别再按 DST 去猜原因。
     */
    #[Label('圣地亚哥证券交易所')]
    case XSGO = 'XSGO';

    // ── 欧洲 36 ──────────────────────────────────────────────────

    #[Label('伦敦证券交易所')]
    case XLON = 'XLON';

    #[Label('法兰克福证券交易所')]
    case XFRA = 'XFRA';

    #[Label('德国 Xetra')]
    case XETR = 'XETR';

    #[Label('欧洲期货交易所')]
    case XEUR = 'XEUR';

    #[Label('欧洲能源交易所')]
    case XEEE = 'XEEE';

    #[Label('杜塞尔多夫证券交易所')]
    case XDUS = 'XDUS';

    #[Label('汉堡证券交易所')]
    case XHAM = 'XHAM';

    #[Label('斯图加特证券交易所')]
    case XSTU = 'XSTU';

    #[Label('泛欧交易所巴黎')]
    case XPAR = 'XPAR';

    #[Label('泛欧交易所阿姆斯特丹')]
    case XAMS = 'XAMS';

    #[Label('泛欧交易所布鲁塞尔')]
    case XBRU = 'XBRU';

    #[Label('泛欧交易所里斯本')]
    case XLIS = 'XLIS';

    #[Label('都柏林证券交易所')]
    case XDUB = 'XDUB';

    #[Label('米兰证券交易所')]
    case XMIL = 'XMIL';

    #[Label('马德里证券交易所')]
    case XMAD = 'XMAD';

    #[Label('瑞士证券交易所')]
    case XSWX = 'XSWX';

    #[Label('维也纳证券交易所')]
    case XWBO = 'XWBO';

    #[Label('卢森堡证券交易所')]
    case XLUX = 'XLUX';

    #[Label('斯德哥尔摩证券交易所')]
    case XSTO = 'XSTO';

    #[Label('奥斯陆证券交易所')]
    case XOSL = 'XOSL';

    #[Label('哥本哈根证券交易所')]
    case XCSE = 'XCSE';

    #[Label('赫尔辛基证券交易所')]
    case XHEL = 'XHEL';

    #[Label('冰岛证券交易所')]
    case XICE = 'XICE';

    #[Label('塔林证券交易所')]
    case XTAL = 'XTAL';

    #[Label('里加证券交易所')]
    case XRIS = 'XRIS';

    #[Label('维尔纽斯证券交易所')]
    case XLIT = 'XLIT';

    #[Label('华沙证券交易所')]
    case XWAR = 'XWAR';

    #[Label('布拉格证券交易所')]
    case XPRA = 'XPRA';

    #[Label('布拉迪斯拉发证券交易所')]
    case XBRA = 'XBRA';

    #[Label('布达佩斯证券交易所')]
    case XBUD = 'XBUD';

    #[Label('布加勒斯特证券交易所')]
    case XBSE = 'XBSE';

    #[Label('贝尔格莱德证券交易所')]
    case XBEL = 'XBEL';

    #[Label('萨格勒布证券交易所')]
    case XZAG = 'XZAG';

    #[Label('卢布尔雅那证券交易所')]
    case XLJU = 'XLJU';

    #[Label('雅典证券交易所')]
    case ASEX = 'ASEX';

    #[Label('伊斯坦布尔证券交易所')]
    case XIST = 'XIST';

    #[Label('莫斯科证券交易所')]
    case XMOS = 'XMOS';

    // ── 亚洲 15 ──────────────────────────────────────────────────

    #[Label('港股作息')]
    case XHKG = 'XHKG';

    #[Label('韩股作息')]
    case XKRX = 'XKRX';

    /**
     * 沪深两市共用这一本：深交所在 exchange_calendars 里没有独立日历（没有 XSHE/SZSE），
     * 因为两市交易时间相同、法定假日由国务院统一公布。深市标的一样填 XSHG。
     *
     * ⚠ 这个「沪深相同」目前只有公开事实撑着，**库内无法交叉验证** —— 库把作息相同的
     * 交易所做成别名（CMES 有 4 个别名、XNYS 有 7 个），却没给 XSHG 配 SZSE 别名，
     * 更像是库没对深市做过判断，而不是判断过认为相同。这正是 Brent 出错的同一种形状。
     * 上深市标的前应拿深交所官方日历核一遍，重点是调休补班的周六。
     *
     * ⚠ 这本日历的假日**只能逐年更新**：中国法定假日不是规则推导的，是国务院每年年底
     * 公布下一年安排，库里也只能一年一录（4.13.2 录到 2026-12-31）。美股那几本能提前
     * 好几年，这本不行 —— 跨年前没重导的话，新一年会变成「有开市时段、零假日」，
     * 春节照常开盘。到期告警见 MarketImportCalendar::healthCheck()。
     * 同样逐年录入的还有 XBOM 与 XSES。
     */
    #[Label('A 股作息')]
    case XSHG = 'XSHG';

    #[Label('东京证券交易所')]
    case XTKS = 'XTKS';

    #[Label('台湾证券交易所')]
    case XTAI = 'XTAI';

    #[Label('新加坡证券交易所')]
    case XSES = 'XSES';

    #[Label('马来西亚证券交易所')]
    case XKLS = 'XKLS';

    #[Label('泰国证券交易所')]
    case XBKK = 'XBKK';

    #[Label('印尼证券交易所')]
    case XIDX = 'XIDX';

    #[Label('菲律宾证券交易所')]
    case XPHS = 'XPHS';

    #[Label('孟买证券交易所')]
    case XBOM = 'XBOM';

    #[Label('巴基斯坦证券交易所')]
    case XKAR = 'XKAR';

    /** 利雅得。周日至周四交易（周五周六休市），周日段占该星期 95%，不是异常数据 */
    #[Label('沙特证券交易所')]
    case XSAU = 'XSAU';

    /**
     * 特拉维夫。周一至周四 09:59-17:15、周五 09:59-13:50（安息日前提前收），按星期看本地作息恒定，
     * 以色列 DST 由 Asia/Tel_Aviv 自动处理 —— 与 XSGO 不是同一类问题（那边是**上游数据错**，
     * 这边是**上游数据对、我们的语义不够用**）。
     *
     * 唯一装不下的是：逾越节前的那个周五收盘**延后**到 14:15，而 close_type=2 是收盘上限裁剪、
     * 只能提前不能延后，min(13:50, 14:15) 会把那条记录整条吃掉，每年误拦 25 分钟。
     *
     * ✅ 2026-09-18 新增 close_type=3 (OVERRIDE) 解决，见 MarketHolidayTypeEnum。
     * 走新 case 而不是放开 close_type=2 的方向：后者会让 1000+ 行假日数据的配错后果
     * 从误拦变成放行下单，而 OVERRIDE 的爆炸半径只有明确标成 3 的那几行（68 本两年合计 2 条）。
     */
    #[Label('特拉维夫证券交易所')]
    case XTAE = 'XTAE';

    #[Label('阿斯塔纳国际交易所')]
    case AIXK = 'AIXK';

    #[Label('塞浦路斯证券交易所')]
    case XCYS = 'XCYS';

    // ── 大洋洲 / 非洲 / 大西洋 5 ──────────────────────────────────

    #[Label('澳大利亚证券交易所')]
    case XASX = 'XASX';

    #[Label('新西兰证券交易所')]
    case XNZE = 'XNZE';

    #[Label('约翰内斯堡证券交易所')]
    case XJSE = 'XJSE';

    #[Label('百慕大证券交易所')]
    case XBDA = 'XBDA';

    // ── BingX 手写 2 ───────────────────────────────────────────────
    //
    // BingX 的 TradFi 永续（NC 前缀）有自己的作息，2026-10-21 用 REST 1m K 线实测：
    // 周五收盘锚定美国当地时间（冬令时、夏令时两季的 UTC 时刻差 1 小时，当地时刻不变），
    // 所以与交易所日历一样存当地时间 + 时区。
    //
    // ⚠ 这本日历下发给订阅方（inner_api/pair/pairs 的 calendar_code），是订阅方判断能否下单的唯一依据：
    // 判开市而 BingX 已停盘 = 按冻结价格放行下单。所以每一处取舍都偏向「宁可多判休市」。
    // BingX 改过作息（外汇周日开盘 2026 年内先后在 UTC 23 / 18 / 19 点），无公告可依，
    // 每年续假日时重测一遍周五收盘与周日开盘。
    // 时段与假日直接写在 database/market_calendar_holiday.json（导出脚本不在本仓库）。

    /**
     * 外汇 9 对：纽约 17:00 -> 次日 17:00，周五 16:45 收盘（实测最后一根 1m 为当地 16:44）。
     * 周日实测 15:10 左右开盘，日历仍取外汇惯例 17:00，开盘前那段判休市（偏保守）。
     * 美国假日照常交易（7/3、劳动节实测）；圣诞、元旦实测停盘，按全天休市录入。
     */
    #[Label('BingX 外汇作息')]
    case BINGX_FX = 'BINGX_FX';

    /**
     * 取暖油：芝加哥 17:10 -> 次日 15:50（比 CME 两头各少 10 分钟，实测）。
     * 假日照抄 CMES —— 7/3、劳动节实测 BingX 收盘晚于 CMES 的 12:00，按 12:00 截断只会早关。
     */
    #[Label('BingX 能源作息')]
    case BINGX_ENERGY = 'BINGX_ENERGY';

    /**
     * IANA 时区 —— 假日表与时段表存本地时间，换算成 UTC 全靠这个值。
     *
     * 存本地时间 + 时区而不是存 UTC，是为了 DST 零维护：美股本地时间恒为 09:30-16:00
     * 夏冬不变，变的只有 UTC 偏移（13:30-20:00 ↔ 14:30-21:00），换算交给 DateTimeZone。
     * 存 UTC 则每年 DST 切换日（美国 3 月第 2 / 11 月第 1 个周日）要人工切表。
     *
     * 这个前提对本枚举的 68 本里 67 本成立 —— 2026-09-17 逐本实测过开收盘的本地取值分布。
     * 唯一的反例是 XSGO：它的本地收盘有两个值，换任何时区基准都消不掉（见该 case 注释）。
     *
     * 取值一律照抄 exchange_calendars，**不要按常识「修正」**：XBOG 是波哥大却给
     * America/New_York、XDUS / XHAM 是德国交易所却给 Europe/Brussels —— 都是库的原值，
     * 改了会与导出数据对不上（导入时会拿 JSON 头的 timezone 与本方法交叉核对并告警）。
     *
     * match 不写 default：新增 case 忘了配时区会当场抛 UnhandledMatchError，
     * 比在表里留一个空 timezone 列早得多 —— 后者只有查到那一行才出事，
     * 而那一行多半就是刚上的新市场。
     *
     * @return string IANA 时区标识，如 America/New_York
     */
    public function timezone(): string
    {
        return match ($this) {
            // 美洲
            self::XNYS => 'America/New_York',
            self::CMES => 'America/Chicago',
            // ICE 在纽约时区，与 CMES 差一个时区 —— 「都是美国商品」不等于同一时区
            self::IEPA => 'America/New_York',
            self::XCBF => 'America/Chicago',
            self::XTSE => 'America/Toronto',
            self::BVMF => 'America/Sao_Paulo',
            self::XMEX => 'America/Mexico_City',
            self::XBUE => 'America/Argentina/Buenos_Aires',
            self::XBOG => 'America/New_York',
            self::XLIM => 'America/Lima',
            self::XSGO => 'America/Santiago',
            // 欧洲
            self::XLON => 'Europe/London',
            self::XFRA => 'Europe/Berlin',
            self::XETR => 'Europe/Berlin',
            self::XEUR => 'Europe/Berlin',
            self::XEEE => 'Europe/Berlin',
            self::XDUS => 'Europe/Brussels',
            self::XHAM => 'Europe/Brussels',
            self::XSTU => 'Europe/Berlin',
            self::XPAR => 'Europe/Paris',
            self::XAMS => 'Europe/Amsterdam',
            self::XBRU => 'Europe/Brussels',
            self::XLIS => 'Europe/Lisbon',
            self::XDUB => 'Europe/Dublin',
            self::XMIL => 'Europe/Rome',
            self::XMAD => 'Europe/Madrid',
            self::XSWX => 'Europe/Zurich',
            self::XWBO => 'Europe/Vienna',
            self::XLUX => 'Europe/Luxembourg',
            self::XSTO => 'Europe/Stockholm',
            self::XOSL => 'Europe/Oslo',
            self::XCSE => 'Europe/Copenhagen',
            self::XHEL => 'Europe/Helsinki',
            self::XICE => 'Atlantic/Reykjavik',
            self::XTAL => 'Europe/Tallinn',
            self::XRIS => 'Europe/Riga',
            self::XLIT => 'Europe/Vilnius',
            self::XWAR => 'Europe/Warsaw',
            self::XPRA => 'Europe/Prague',
            self::XBRA => 'Europe/Bratislava',
            self::XBUD => 'Europe/Budapest',
            self::XBSE => 'Europe/Bucharest',
            self::XBEL => 'Europe/Belgrade',
            self::XZAG => 'Europe/Zagreb',
            self::XLJU => 'Europe/Ljubljana',
            self::ASEX => 'Europe/Athens',
            self::XIST => 'Europe/Istanbul',
            self::XMOS => 'Europe/Moscow',
            // 亚洲
            self::XHKG => 'Asia/Hong_Kong',
            self::XKRX => 'Asia/Seoul',
            self::XSHG => 'Asia/Shanghai',
            self::XTKS => 'Asia/Tokyo',
            self::XTAI => 'Asia/Taipei',
            self::XSES => 'Asia/Singapore',
            self::XKLS => 'Asia/Kuala_Lumpur',
            self::XBKK => 'Asia/Bangkok',
            self::XIDX => 'Asia/Jakarta',
            self::XPHS => 'Asia/Manila',
            self::XBOM => 'Asia/Kolkata',
            self::XKAR => 'Asia/Karachi',
            self::XSAU => 'Asia/Riyadh',
            self::XTAE => 'Asia/Tel_Aviv',
            self::AIXK => 'Asia/Almaty',
            self::XCYS => 'Asia/Nicosia',
            // 大洋洲 / 非洲 / 大西洋
            self::XASX => 'Australia/Sydney',
            self::XNZE => 'Pacific/Auckland',
            self::XJSE => 'Africa/Johannesburg',
            self::XBDA => 'Atlantic/Bermuda',
            // BingX 手写：BINGX_ENERGY 借 CMES 的假日，时区必须与之相同
            self::BINGX_FX => 'America/New_York',
            self::BINGX_ENERGY => 'America/Chicago',
        };
    }

    /**
     * 这本日历有没有假日。false = 按设计节假日照常开市，导入体检不把「一条假日都没有」当问题。
     *
     * 现有每一本都有假日（2026-10-21 随 AllTick 下线删掉了 4 本无假日的手写作息）；
     * 以后接入「节假日照常开市」的数据源作息时，在这里把它列出来。
     */
    public function hasHolidays(): bool
    {
        return true;
    }

    /**
     * 后台下拉用的「中文名 (MIC)」映射，如 XNYS => 美股作息 (XNYS)。
     *
     * 光给中文名，运维对不上 coin_list 里存的 MIC；光给 MIC 又看不懂，所以两个都给。
     *
     * 66 项不分组也够用：表单与筛选的 select 都带 `lay-search`，中文名和 MIC 都能搜到。
     *
     * @return array<string,string>
     */
    public static function selectOptions(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->labelText() . ' (' . $case->value . ')';
        }
        return $options;
    }
}
