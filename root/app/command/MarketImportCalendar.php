<?php

declare(strict_types=1);

namespace app\command;

use app\enum\CommonStatusEnum;
use app\enum\CommonWeekDayEnum;
use app\enum\MarketCalendarEnum;
use app\enum\MarketHolidayTypeEnum;
use app\service\MarketSessionService;
use think\admin\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 导入节假日休市日历 —— 把 tools/export_exchange_calendars.py 的产出灌进 market_calendar_holiday
 *
 * 为什么要一个命令而不是 seed：假日每年都要续，seed 是「装机跑一次」的语义。本命令幂等，
 * 可以随部署反复执行，明年续了数据重跑一遍即可。
 *
 * ── 只碰自己写的行 ──────────────────────────────────────────────────────────
 * 只覆盖 source='exchange_calendars' 的行。运维在后台手工加的临时调休（source='manual'）
 * 一律跳过并报数 —— 交易所临时调休往往比库版本更新，冲掉人工修改比漏导数据更难发现。
 *
 * ── 不静默 ─────────────────────────────────────────────────────────────────
 * JSON 里认不出的 MIC、非法日期、时段调整却没给时间，全部逐条 warning 后跳过，
 * 不是 continue 了事。这批错误的共同点是「导入看着成功了，但那些行永远匹配不上任何标的」。
 *
 * ── 孤儿行清理：会改数据，一共五道防护 ──────────────────────────────────────
 * upsert 删不掉任何东西，所以上游改口后旧行会永久留在库里（时段表尤其危险：改开盘时刻
 * 会新增一条而旧的还在，两段取并集就是多放开一截 = 休市期间放行下单）。清理补上这一环，
 * 代价是它是本命令唯一会「凭 JSON 里没有」就改动既有数据的地方，因此层层设限：
 *
 *   1  只动 source='exchange_calendars' 的行            运维手工加的临时调休绝不碰
 *   2  只在本次 JSON 出现过的 calendar_code 内           --mic 只导一本时不会波及其余 67 本
 *   3  假日只在 JSON 的 years 区间内                     导 2026-2027 不会动 2025 的历史
 *   4  作废比例 > PRUNE_MAX_RATIO 只报告不执行           导出少导一批时不自动抹掉好数据
 *   5  本次有非法行（bad > 0）则整个跳过                 JSON 坏了时「没有」不等于「上游删了」
 *
 * 只有第 4 道能用 --force-prune 放宽，其余四道恒生效。作废是 status=2 不是物理删除，
 * 改错了一条 SQL 就能恢复。--dry-run 会完整报告将要作废哪些行而不写库。
 *
 * ── 退出码 ─────────────────────────────────────────────────────────────────
 * 0 = 通过；1 = 有问题（体检异常、孤儿清理被阈值挡下、一行都没导入成功）。
 * deploy/update.sh 与 deploy/market_health.sh 靠退出码把问题收进 PROBLEMS。
 *
 * 用法:
 *   php think market:import_calendar                                    # 默认读 database/market_calendar_holiday.json
 *   php think market:import_calendar --file database/xxx.json           # 指定数据文件
 *   php think market:import_calendar --dry-run                          # 只报告不写库，含孤儿清理预演
 *   php think market:import_calendar --force-prune                      # 放宽第 4 道阈值，上游大幅修正后人工确认再用
 *   php think market:import_calendar --dry-run --force-prune            # 先看放宽后会作废哪些行
 *
 * ⚠️ **本命令不写 market_data.calendar_code**（哪个源跟哪本日历）—— 那一列随
 * database/market_data.sql 种子落库。本命令末尾的覆盖体检要读它，种子没跑过的库体检恒空。
 *
 * 数据来源：tools/export_exchange_calendars.py（需要 Python 与 exchange_calendars 库）
 * 设计依据：.agents/plan/market_calendar_module.md §3.4
 */
class MarketImportCalendar extends Command
{
    /** 假日表 */
    private const TABLE = 'market_calendar_holiday';

    /** 开市时段表（周内固定规则） */
    private const SESSION_TABLE = 'market_calendar_session';

    /** 本命令写入的来源标记；只有这一类行会被重跑覆盖 */
    private const SOURCE_IMPORT = 'exchange_calendars';

    /** 默认取的 JSON 路径，相对 root/ */
    private const DEFAULT_FILE = 'database/market_calendar_holiday.json';

    /**
     * 假日数据剩余覆盖不足这个天数就告警。
     *
     * 取一个季度：够运维发现、装 Python、跑导出脚本、走一次发版，不至于临到跨年才动手。
     */
    private const COVERAGE_WARN_DAYS = 90;

    /**
     * 孤儿行清理的安全阀：一本日历里「JSON 没有而库里有」的比例超过这个数就只报告不执行。
     *
     * 正常的上游修正是零星的（改几条假日、挪一个开盘时刻）。一次要作废三成以上，
     * 更可能是导出脚本出错少导了一批 —— 那种时候自动作废会把好数据一起抹掉，
     * 而时段表被抹掉的后果是整个市场判成永远休市。
     *
     * 取 0.3 而不是更小：XSGO 那次上游修正丢了 160/189 条（85%），确实会撞上这道阀 ——
     * 这是刻意的，那种量级的变更本就该由人看一眼再放行。
     */
    private const PRUNE_MAX_RATIO = 0.3;

    /**
     * 孤儿扫描一次最多取多少行。Coding_Standards §12 要求 SELECT 必带 LIMIT。
     *
     * 取值远大于合理上界：单本日历一年假日约 20-60 条，时段最多十几行，
     * 导十年也到不了 5000。所以触达上限意味着数据异常（重复导入、日期区间写错），
     * 此时**跳过清理并报错**，而不是把截断后的结果当全集 ——
     * 后者会把没扫到的孤儿静默留下，比不清理更难发现。
     */
    private const PRUNE_SCAN_LIMIT = 5000;

    protected function configure()
    {
        $this->setName('market:import_calendar')
            ->addOption('file', 'f', Option::VALUE_OPTIONAL,
                'JSON 数据文件路径，相对 root/ 或绝对路径，默认 ' . self::DEFAULT_FILE)
            ->addOption('dry-run', null, Option::VALUE_NONE, '只报告将要发生的改动，不写库')
            ->addOption('force-prune', null, Option::VALUE_NONE,
                '允许孤儿行作废比例超过 ' . (int)(self::PRUNE_MAX_RATIO * 100) . '% —— 上游大幅修正后人工确认再用')
            ->setDescription('导入节假日休市日历 (幂等，可反复执行)');
    }

    protected function execute(Input $input, Output $output)
    {
        $dryRun = (bool)$input->getOption('dry-run');
        $json = $this->loadJson((string)($input->getOption('file') ?: self::DEFAULT_FILE), $output);
        if ($json === null) return 1;
        $rows = $json['holidays'];

        $stat = ['insert' => 0, 'update' => 0, 'same' => 0, 'manual' => 0, 'bad' => 0];

        // 记下本次 JSON 覆盖到的键，导完之后据此找出「库里有、JSON 里没有」的孤儿行
        $seenHoliday = [];
        $seenSession = [];

        foreach ($rows as $i => $raw) {
            $row = $this->normalizeRow(is_array($raw) ? $raw : [], $i, $output);
            if ($row === null) {
                $stat['bad']++;
                continue;
            }
            $seenHoliday[$row['calendar_code']][$row['holiday_date']] = true;
            $this->upsert($row, $dryRun, $stat);
        }

        // 开市时段：老版本导出的 JSON 没有这一段，缺了就跳过而不是报错 ——
        // 假日能单独导入，两者没有先后依赖
        $sessionStat = ['insert' => 0, 'update' => 0, 'same' => 0, 'manual' => 0, 'bad' => 0];
        $sessionRows = is_array($json['sessions'] ?? null) ? $json['sessions'] : [];
        foreach ($sessionRows as $i => $raw) {
            $row = $this->normalizeSessionRow(is_array($raw) ? $raw : [], $i, $output);
            if ($row === null) {
                $sessionStat['bad']++;
                continue;
            }
            $seenSession[$row['calendar_code']][$row['open_week_day'] . '|' . $row['open_time']] = true;
            $this->upsertSession($row, $dryRun, $sessionStat);
        }

        $changed = $stat['insert'] + $stat['update'];
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>[%s] 假日 改动 %d 行</info>  新增 %d · 更新 %d · 无变化 %d · 跳过人工行 %d · 非法行 %d',
            $dryRun ? '预演' : '完成', $changed,
            $stat['insert'], $stat['update'], $stat['same'], $stat['manual'], $stat['bad']
        ));
        if ($sessionRows === []) {
            $output->warning('  JSON 里没有 sessions 段 —— 时段表未导入，请重跑 tools/export_exchange_calendars.py');
        } else {
            $output->writeln(sprintf(
                '<info>[%s] 时段 改动 %d 行</info>  新增 %d · 更新 %d · 无变化 %d · 跳过人工行 %d · 非法行 %d',
                $dryRun ? '预演' : '完成', $sessionStat['insert'] + $sessionStat['update'],
                $sessionStat['insert'], $sessionStat['update'], $sessionStat['same'],
                $sessionStat['manual'], $sessionStat['bad']
            ));
        }
        $stat['bad'] += $sessionStat['bad'];

        // 非法行太多时不清理：那多半是 JSON 结构坏了，此时「JSON 里没有」不代表「上游删了」
        $pruneUnhealthy = false;
        if ($stat['bad'] === 0) {
            $pruneUnhealthy = $this->pruneOrphans(
                $json, $seenHoliday, $seenSession, $dryRun,
                (bool)$input->getOption('force-prune'), $output
            );
        } elseif ($seenHoliday !== []) {
            $output->warning(sprintf('  本次有 %d 个非法行，跳过孤儿行清理 —— 先修 JSON 再重跑', $stat['bad']));
        }

        // 数据已定型，清掉判定侧的日历缓存。不清的话 MarketSessionService 会继续拿旧日历判休市：
        // 它有两级缓存（进程内 3s + Redis 60s），最坏情况下新导入的假日要一分钟后才生效。
        // 这里清的是 Redis 那层；各常驻进程自己的进程内缓存最多再等 3 秒自然过期。
        if (!$dryRun) {
            MarketSessionService::clearCache();
            $output->writeln('  已清空休市判定的日历缓存 (market:session:cal:*)');
        }

        $unhealthy = $this->healthCheck($output) || $pruneUnhealthy;

        // 一行都没成功说明 JSON 结构或 MIC 全对不上，这种「看着跑完了」必须让调用方感知
        if ($stat['bad'] > 0 && $stat['insert'] + $stat['update'] + $stat['same'] === 0) {
            $output->error('没有任何一行导入成功，请检查 JSON 结构与 MIC 取值。');
            return 1;
        }

        // 体检结果也要走退出码：deploy/market_health.sh 是靠退出码把问题收进 PROBLEMS 的，
        // 只在屏幕上打一行 warning，巡检脚本看不见，等于没报
        return $unhealthy ? 1 : 0;
    }

    /**
     * 读取并解析 JSON，返回 holidays 数组；任何一步失败返回 null 并已报错。
     *
     * 版本号来自导出脚本：上游文档的全部结论建立在 exchange_calendars 4.13.2 的实测上，
     * 换版本后数据对不上要能从日志追出来，所以这里把它打出来而不是丢掉。
     *
     * @param string $file
     * @param Output $output
     * @return array|null
     */
    private function loadJson(string $file, Output $output): ?array
    {
        $path = $this->resolvePath($file);
        if (!is_file($path) || !is_readable($path)) {
            $output->error("数据文件不存在或不可读: {$file}");
            $output->writeln('  请先在装有 Python 与 exchange_calendars 的机器上执行 tools/export_exchange_calendars.py');
            return null;
        }

        $json = json_decode((string)file_get_contents($path), true);
        if (!is_array($json)) {
            $output->error("数据文件不是合法 JSON: {$file}");
            return null;
        }
        if (!isset($json['holidays']) || !is_array($json['holidays'])) {
            $output->error('JSON 缺少 holidays 数组。');
            return null;
        }

        $output->writeln(sprintf('<info>[开始] %s</info>', $file));
        $output->writeln(sprintf('  生成器 %s %s · 生成于 %s · 共 %d 行',
            (string)($json['generator'] ?? '未知'),
            (string)($json['version'] ?? '未知版本'),
            (string)($json['generated_at'] ?? '未知时间'),
            count($json['holidays'])
        ));

        $this->checkTimezones(is_array($json['calendars'] ?? null) ? $json['calendars'] : [], $output);

        return $json;
    }

    /**
     * 拿导出脚本记下的时区与 MarketCalendarEnum::timezone() 交叉核对。
     *
     * 枚举里时区写错是「一切看着正常」的典型：假日照样导进来、后台照样显示，
     * 只有到了判定时刻才把开收盘算偏几个小时，而那时已经没人会回头怀疑这个常量。
     * 导出脚本那边的时区直接来自 exchange_calendars，拿它当参照物最省事。
     *
     * @param array  $calendars JSON 头里的 {MIC: {timezone: ...}}
     * @param Output $output
     */
    private function checkTimezones(array $calendars, Output $output): void
    {
        foreach ($calendars as $mic => $meta) {
            $case = MarketCalendarEnum::tryFrom((string)$mic);
            if ($case === null) continue;    // 认不出的 MIC 由逐行校验报，不在这里重复

            $fromFile = (string)($meta['timezone'] ?? '');
            if ($fromFile !== '' && $fromFile !== $case->timezone()) {
                $output->warning(sprintf(
                    '  %s 时区不一致：MarketCalendarEnum 写的是 %s，exchange_calendars 给的是 %s',
                    $mic, $case->timezone(), $fromFile
                ));
            }
        }
    }

    /**
     * 相对路径按 root/ 解析。
     *
     * 不用 './xxx' 直接交给 file_exists —— 那样解析结果取决于 php think 的执行目录，
     * 从别的目录跑就找不到文件。
     *
     * @param string $file
     * @return string
     */
    private function resolvePath(string $file): string
    {
        if (preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $file)) return $file;
        return rtrim($this->app->getRootPath(), '\\/') . DIRECTORY_SEPARATOR . ltrim($file, '\\/');
    }

    /**
     * 校验并归一一行 JSON；非法返回 null 并已 warning。
     *
     * 校验项与后台表单（MarketCalendar::_holiday_add_form_filter）一一对应 ——
     * 两个入口对同一张表，放松任何一边都会让另一边的校验变成摆设。
     *
     * @param array  $raw
     * @param int    $idx 行号，报错时给运维定位用
     * @param Output $output
     * @return array|null
     */
    private function normalizeRow(array $raw, int $idx, Output $output): ?array
    {
        $warn = function (string $msg) use ($idx, $output): null {
            $output->warning(sprintf('  第 %d 行跳过：%s', $idx + 1, $msg));
            return null;
        };

        $code = strtoupper(trim((string)($raw['calendar_code'] ?? '')));
        if (MarketCalendarEnum::tryFrom($code) === null) {
            return $warn("MarketCalendarEnum 认不出的日历 '{$code}'，导进去也永远匹配不上任何标的");
        }

        $date = trim((string)($raw['date'] ?? $raw['holiday_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $warn("日期格式非法 '{$date}'");
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if (!checkdate($m, $d, $y)) return $warn("日期不存在 '{$date}'");

        $type = (int)($raw['close_type'] ?? MarketHolidayTypeEnum::FULL_CLOSE->value);
        if (MarketHolidayTypeEnum::tryFrom($type) === null) return $warn("休市类型非法 '{$type}'");

        $open = trim((string)($raw['open_time'] ?? ''));
        $close = trim((string)($raw['close_time'] ?? ''));

        if ($type === MarketHolidayTypeEnum::FULL_CLOSE->value) {
            // 全日休市带着时间值，后来人读不懂那两个数到底生不生效
            $open = $close = '';
        } else {
            if ($open === '' && $close === '') {
                return $warn("{$code} {$date} 标为时段调整却没给时间，这一行什么也不会改变");
            }
            if ($open !== '' && !$this->isValidTime($open)) return $warn("开盘时间格式非法 '{$open}'，须 HH:MM 两位补零");
            if ($close !== '' && !$this->isValidTime($close)) return $warn("收盘时间格式非法 '{$close}'，须 HH:MM 两位补零");
            if ($open !== '' && $close !== '' && $open >= $close) {
                return $warn("{$code} {$date} 收盘 {$close} 不晚于开盘 {$open}");
            }
            // OVERRIDE 是整段替换，两头都必须给。只给一头的话另一头没有边界可用 ——
            // 沿用时段表就退化成了 PARTIAL（还多绕一道），留空则区间根本不成立。
            // 挡在导入这一层，判定函数就不必再处理「半个覆盖」这种形状。
            if ($type === MarketHolidayTypeEnum::OVERRIDE->value && ($open === '' || $close === '')) {
                return $warn("{$code} {$date} 标为时段覆盖却只给了一头（开盘 '{$open}' 收盘 '{$close}'）—— 覆盖是整段替换，两个时间都必须给");
            }
        }

        return [
            'calendar_code' => $code,
            'holiday_date'  => $date,
            'close_type'    => $type,
            'open_time'     => $open,
            'close_time'    => $close,
            'title'         => mb_substr(trim((string)($raw['title'] ?? '')), 0, 64),
        ];
    }

    /**
     * 按 (calendar_code, holiday_date) upsert，只碰自己写的行。
     *
     * 带内容比对而不是无脑 update：重跑时「本次改动」要能反映真正变了的行，
     * 否则每次都报改了 60 行，等于这个数字没有信息量。
     *
     * @param array $row
     * @param bool  $dryRun
     * @param array $stat 按引用累加计数
     */
    private function upsert(array $row, bool $dryRun, array &$stat): void
    {
        $where = ['calendar_code' => $row['calendar_code'], 'holiday_date' => $row['holiday_date']];
        $exists = Db::name(self::TABLE)->where($where)->find();

        if ($exists === null) {
            $stat['insert']++;
            if ($dryRun) return;
            Db::name(self::TABLE)->insert($row + [
                'source'    => self::SOURCE_IMPORT,
                'status'    => CommonStatusEnum::ON->value,
                'remark'    => '',
                'create_ts' => time(),
                'update_ts' => time(),
            ]);
            return;
        }

        // 人工录入 / 后台编辑过的行不碰：临时调休往往比库版本更新
        if ((string)($exists['source'] ?? '') !== self::SOURCE_IMPORT) {
            $stat['manual']++;
            return;
        }

        $changed = false;
        foreach (['close_type', 'open_time', 'close_time', 'title'] as $field) {
            if ((string)($exists[$field] ?? '') !== (string)$row[$field]) $changed = true;
        }
        if (!$changed) {
            $stat['same']++;
            return;
        }

        $stat['update']++;
        if ($dryRun) return;
        Db::name(self::TABLE)->where($where)->update([
            'close_type' => $row['close_type'],
            'open_time'  => $row['open_time'],
            'close_time' => $row['close_time'],
            'title'      => $row['title'],
            'update_ts'  => time(),
        ]);
    }

    /**
     * 校验并归一一行时段 JSON；非法返回 null 并已 warning。
     *
     * 校验项与后台表单（MarketCalendar::_session_add_form_filter）一一对应 ——
     * 两个入口写同一张表，放松任何一边都会让另一边的校验变成摆设。
     *
     * @param array  $raw
     * @param int    $idx 行号，报错时给运维定位用
     * @param Output $output
     * @return array|null
     */
    private function normalizeSessionRow(array $raw, int $idx, Output $output): ?array
    {
        $warn = function (string $msg) use ($idx, $output): null {
            $output->warning(sprintf('  时段第 %d 行跳过：%s', $idx + 1, $msg));
            return null;
        };

        $code = strtoupper(trim((string)($raw['calendar_code'] ?? '')));
        if (MarketCalendarEnum::tryFrom($code) === null) {
            return $warn("MarketCalendarEnum 认不出的日历 '{$code}'，导进去也永远匹配不上任何标的");
        }

        $openWd = (int)($raw['open_week_day'] ?? 0);
        $closeWd = (int)($raw['close_week_day'] ?? 0);
        if (CommonWeekDayEnum::tryFrom($openWd) === null)  return $warn("开盘星期非法 '{$openWd}'，须为 1-7");
        if (CommonWeekDayEnum::tryFrom($closeWd) === null) return $warn("收盘星期非法 '{$closeWd}'，须为 1-7");

        $openTime = trim((string)($raw['open_time'] ?? ''));
        $closeTime = trim((string)($raw['close_time'] ?? ''));
        if (!$this->isValidTime($openTime))  return $warn("开盘时间格式非法 '{$openTime}'，须 HH:MM 两位补零");
        if (!$this->isValidTime($closeTime)) return $warn("收盘时间格式非法 '{$closeTime}'，须 HH:MM 两位补零");

        // 开盘与收盘同一时刻：长度既能理解成 0 也能理解成整周，两种都不是想配的。
        // 不挡的话按跨周算会得到一条「全周开盘」的段，把该市场的休市判定整个废掉
        if (CommonWeekDayEnum::minuteOfWeek($openWd, $openTime) === CommonWeekDayEnum::minuteOfWeek($closeWd, $closeTime)) {
            return $warn("{$code} 周{$openWd} {$openTime} 的开盘与收盘是同一时刻，这一段长度为零");
        }

        return [
            'calendar_code'  => $code,
            'open_week_day'  => $openWd,
            'open_time'      => $openTime,
            'close_week_day' => $closeWd,
            'close_time'     => $closeTime,
        ];
    }

    /**
     * 按 (calendar_code, open_week_day, open_time) upsert 一行时段，只碰自己写的行。
     *
     * 为什么逻辑键是这三列而不是全部五列：同一本日历、同一个开盘星期、同一个开盘时刻
     * 只该有一条规则（港股周一有 09:30 和 13:00 两段，开盘时刻不同）。把收盘也算进键，
     * 改收盘时间就会变成「新增一条」而不是「更新那一条」，旧的留在表里与新的重叠。
     *
     * 表上没有对应的唯一索引 —— 时段的唯一性约束是「不重叠」，那不是任何列组合能表达的，
     * 只能靠这里和后台表单两处校验守住。
     *
     * @param array $row
     * @param bool  $dryRun
     * @param array $stat 按引用累加计数
     */
    private function upsertSession(array $row, bool $dryRun, array &$stat): void
    {
        $where = [
            'calendar_code' => $row['calendar_code'],
            'open_week_day' => $row['open_week_day'],
            'open_time'     => $row['open_time'],
        ];
        $exists = Db::name(self::SESSION_TABLE)->where($where)->find();

        if ($exists === null) {
            $stat['insert']++;
            if ($dryRun) return;
            Db::name(self::SESSION_TABLE)->insert($row + [
                'source'    => self::SOURCE_IMPORT,
                'status'    => CommonStatusEnum::ON->value,
                'remark'    => '',
                'create_ts' => time(),
                'update_ts' => time(),
            ]);
            return;
        }

        // 人工调整过的时段不碰：交易所临时改作息往往比库版本更新
        if ((string)($exists['source'] ?? '') !== self::SOURCE_IMPORT) {
            $stat['manual']++;
            return;
        }

        $changed = false;
        foreach (['close_week_day', 'close_time'] as $field) {
            if ((string)($exists[$field] ?? '') !== (string)$row[$field]) $changed = true;
        }
        if (!$changed) {
            $stat['same']++;
            return;
        }

        $stat['update']++;
        if ($dryRun) return;
        Db::name(self::SESSION_TABLE)->where($where)->update([
            'close_week_day' => $row['close_week_day'],
            'close_time'     => $row['close_time'],
            'update_ts'      => time(),
        ]);
    }

    /**
     * 反向体检：有标的跟着走、却没有（可用）假日数据的日历。
     *
     * 漏配的后果是那本日历下的标的永远查不到假日，表现为「节假日照常开盘」—— 这是
     * 上游文档反复点名的、最难发现的那个方向，所以每次导入完都主动报一次，
     * 而不是等出事了再查。
     *
     * ── 为什么光数条数不够，还要查覆盖到哪天 ────────────────────────────────
     * 假日数据是**一年一续**的，导出脚本默认只出今明两年。数据到 2027-12-31 为止，
     * 2028-01-01 起每本日历都是整年零假日 —— 但总条数依然是 23 / 33 / 31 / 21，
     * 只数条数的体检照样报「正常」。
     *
     * 这个失效方式和「一条假日都没有」的后果完全相同（节假日照常开盘），却在数量上
     * 完全看不出来，而且它会在某个跨年的凌晨自己发生，没有任何人为动作可以关联。
     * 所以必须查到期日，不能只查有没有。
     *
     * 到期日取的是**最后一条假日的日期**，不是数据的真实覆盖终点 —— 库里没存覆盖区间，
     * 只有一行行假日。两者差几天（XNYS 2027 年最后一条假日是 12-24，因为 12-25 落在
     * 周六不入表），相对 90 天的告警阈值可以忽略。宁可早报几天也不晚报。
     *
     * @param Output $output
     * @return bool 是否发现问题；调用方据此决定退出码
     */
    /**
     * 作废「库里有、本次 JSON 里没有」的行 —— 上游改口之后，旧数据不会自己消失。
     *
     * ── 为什么必须有这一步 ──────────────────────────────────────────
     * upsert 只会新增和更新，删不掉任何东西。于是上游一旦修正，旧行就永久留在库里：
     *   · 假日表：多一条假日 = 那天被判休市。方向安全，但会污染后台列表与缺名统计
     *   · 时段表：多一段开市 = **休市期间放行下单**，最危险的方向
     * 时段表的逻辑键是 (calendar_code, open_week_day, open_time)，所以改收盘时间是 update、
     * 不留孤儿；但**改开盘时间**会新增一条而旧的还在 —— 两段重叠，取并集就是多开一截。
     * 而 upsertSession 不做重叠检测（那只在后台表单里有），不会有任何人被告知。
     * XSGO 2026-07 把连续交易从 09:30 提前到 09:05 就是这个形状。
     *
     * ── 四道安全限制，每一道都是为了不误伤 ──────────────────────────
     * 1. 只动 source='exchange_calendars' 的行 —— 运维手工加的临时调休绝不碰
     * 2. 只在**本次 JSON 出现过的 calendar_code** 内清理 —— 否则 `--mic XNYS` 只导一本，
     *    会把其余 67 本全作废
     * 3. 假日只在**本次 JSON 的 years** 内清理 —— 否则导 2026-2027 会把 2025 的历史全作废
     * 4. 作废比例超过 PRUNE_MAX_RATIO 就**只报告不执行** —— 导出脚本某次少导一批时，
     *    宁可留着脏数据让人来看，也不能自动抹掉好数据。核对过确实是上游改口，
     *    用 --force-prune 放行（只放宽这一道，前三道限制照旧生效）
     *
     * 用 status=2 而不是物理删除：表设计本就用 status 表达「交易所撤销了这条」，
     * 留痕可查，改错了也能一条 SQL 恢复。判定与体检都只认 status=1，作废即失效。
     *
     * @param array  $json         导出的 JSON，取 years 限定年份范围
     * @param array  $seenHoliday  本次导入的假日键 [calendar_code][date]
     * @param array  $seenSession  本次导入的时段键 [calendar_code]["星期|开盘时间"]
     * @param bool   $dryRun
     * @param Output $output
     * @return bool 是否发现问题（超过阈值未执行也算），调用方据此决定退出码
     */
    private function pruneOrphans(array $json, array $seenHoliday, array $seenSession, bool $dryRun, bool $force, Output $output): bool
    {
        if ($seenHoliday === [] && $seenSession === []) return false;

        $years = array_values(array_filter(array_map('intval', (array)($json['years'] ?? []))));
        $unhealthy = false;
        $lines = [];

        // ── 假日表 ──
        foreach ($seenHoliday as $code => $dates) {
            $query = Db::name(self::TABLE)
                ->where('calendar_code', $code)
                ->where('source', self::SOURCE_IMPORT)
                ->where('status', CommonStatusEnum::ON->value);
            // years 缺失时不敢限定年份，退化成「只清本日历全部年份」风险太大，直接跳过
            if ($years === []) {
                $output->warning('  JSON 头缺 years，跳过假日孤儿清理 —— 无法确定该清哪些年份');
                break;
            }
            // 用日期区间而不是 YEAR(holiday_date)：ThinkPHP 的 where 字段位不接受 SQL 函数，
            // 且函数会让 holiday_date 上的索引失效。years 可能不连续，逐年 OR 起来
            $query->where(function ($q) use ($years) {
                foreach ($years as $y) {
                    $q->whereOr('holiday_date', 'between', [$y . '-01-01', $y . '-12-31']);
                }
            });
            $live = $query->limit(self::PRUNE_SCAN_LIMIT + 1)->column('holiday_date', 'id');
            if (count($live) > self::PRUNE_SCAN_LIMIT) {
                $output->error(sprintf('  %-6s 假日在扫描区间内超过 %d 行，跳过清理 —— 请核对是否重复导入',
                    $code, self::PRUNE_SCAN_LIMIT));
                $unhealthy = true;
                continue;
            }

            $orphans = [];
            foreach ($live as $id => $date) {
                if (!isset($dates[(string)$date])) $orphans[] = $id;
            }
            if ($orphans === []) continue;

            $total = max(count($live), 1);
            $ratio = count($orphans) / $total;
            if ($ratio > self::PRUNE_MAX_RATIO && !$force) {
                $output->error(sprintf(
                    '  %-6s 假日有 %d/%d 行（%.0f%%）在本次 JSON 里不存在，超过 %.0f%% 阈值 —— 已跳过清理。'
                    . '这更像是导出漏了数据而不是上游删了，请先核对 JSON；确认无误后加 --force-prune',
                    $code, count($orphans), $total, $ratio * 100, self::PRUNE_MAX_RATIO * 100
                ));
                $unhealthy = true;
                continue;
            }
            if (!$dryRun) {
                Db::name(self::TABLE)->whereIn('id', $orphans)->update([
                    'status'    => CommonStatusEnum::OFF->value,
                    'update_ts' => time(),
                ]);
            }
            $lines[] = sprintf('    %-6s 假日作废 %d 行（上游已无此条）', $code, count($orphans));
        }

        // ── 时段表 ──
        foreach ($seenSession as $code => $keys) {
            $live = Db::name(self::SESSION_TABLE)
                ->where('calendar_code', $code)
                ->where('source', self::SOURCE_IMPORT)
                ->where('status', CommonStatusEnum::ON->value)
                ->field('id, open_week_day, open_time')
                ->limit(self::PRUNE_SCAN_LIMIT + 1)
                ->select()->toArray();
            if (count($live) > self::PRUNE_SCAN_LIMIT) {
                $output->error(sprintf('  %-6s 时段在扫描区间内超过 %d 行，跳过清理 —— 请核对是否重复导入',
                    $code, self::PRUNE_SCAN_LIMIT));
                $unhealthy = true;
                continue;
            }

            $orphans = [];
            foreach ($live as $r) {
                if (!isset($keys[$r['open_week_day'] . '|' . $r['open_time']])) $orphans[] = $r['id'];
            }
            if ($orphans === []) continue;

            $ratio = count($orphans) / max(count($live), 1);
            if ($ratio > self::PRUNE_MAX_RATIO && !$force) {
                $output->error(sprintf(
                    '  %-6s 时段有 %d/%d 行在本次 JSON 里不存在，超过阈值 —— 已跳过清理。'
                    . '时段表是开市白名单，清错了会让整个市场判成永远休市；确认无误后加 --force-prune',
                    $code, count($orphans), count($live)
                ));
                $unhealthy = true;
                continue;
            }
            if (!$dryRun) {
                Db::name(self::SESSION_TABLE)->whereIn('id', $orphans)->update([
                    'status'    => CommonStatusEnum::OFF->value,
                    'update_ts' => time(),
                ]);
            }
            // 时段孤儿比假日孤儿严重得多：它意味着上游改了开盘时刻，而旧段还在放行下单
            $lines[] = sprintf('    %-6s <comment>时段作废 %d 行 —— 上游改了开盘时刻，旧段原本会多放开一截</comment>', $code, count($orphans));
        }

        if ($lines !== []) {
            $output->writeln('');
            $output->writeln(sprintf('<info>[%s] 孤儿行清理（status 置为停用，可恢复）</info>', $dryRun ? '预演' : '完成'));
            foreach ($lines as $l) $output->writeln($l);
        }
        return $unhealthy;
    }

    private function healthCheck(Output $output): bool
    {
        $output->writeln('');
        $output->writeln('<info>[体检] 日历覆盖情况</info>');

        $unhealthy = false;
        $today = date('Y-m-d');
        $warnLine = date('Y-m-d', strtotime('+' . self::COVERAGE_WARN_DAYS . ' days'));

        // 判据是 market_data.calendar_code（源维度）：采集机的过期扫描看的就是这张表，
        // 而 coin 维度在那一层拿不到（迁移 20260918110000 / 20260918120000）。
        // 统计口径因此是「几个行情源跟它走」，不是「几个标的」
        $micsInUse = Db::name('market_data')
            ->where('calendar_code', '<>', '')
            ->group('calendar_code')
            ->limit(self::PRUNE_SCAN_LIMIT)
            ->column('COUNT(*) AS n', 'calendar_code');

        foreach ($micsInUse as $mic => $coins) {
            $mic = (string)$mic;
            $case = MarketCalendarEnum::tryFrom($mic);
            if ($case === null) {
                $output->warning(sprintf('  %-6s market_data 有 %d 个行情源跟它走，但 MarketCalendarEnum 里没有这个日历', $mic, $coins));
                $unhealthy = true;
                continue;
            }

            if (!$case->hasHolidays()) {
                $output->writeln(sprintf('    %-6s %2d 个源 · 无假日（按设计节假日照常开市）', $mic, $coins));
                $unhealthy = $this->checkSegments($mic, $coins, $output) || $unhealthy;
                continue;
            }

            $query = Db::name(self::TABLE)
                ->where('calendar_code', $mic)
                ->where('status', CommonStatusEnum::ON->value);
            $days = $query->count();
            if ($days === 0) {
                $output->warning(sprintf('  %-6s %d 个行情源跟它走，但一条假日都没有 —— 节假日会照常开盘', $mic, $coins));
                $unhealthy = true;
                continue;
            }

            // 两个坑写在一起：
            //   1. max() 会消费掉上面的 where，所以重新起一个查询而不是复用 $query；
            //   2. max() 第二个参数 $force 默认 true，会把结果强转数值 ——
            //      '2027-12-31' 会变成 2027，剩余天数据此算出来是负的。必须显式传 false。
            $until = (string)Db::name(self::TABLE)
                ->where('calendar_code', $mic)
                ->where('status', CommonStatusEnum::ON->value)
                ->max('holiday_date', false);
            $left = (int)floor((strtotime($until) - strtotime($today)) / 86400);

            if ($until < $today) {
                $output->error(sprintf(
                    '  %-6s 假日数据最后一条停在 %s（%d 个行情源跟它走）—— 此后节假日一律照常开盘，请跑导出脚本续数据',
                    $mic, $until, $coins
                ));
                $unhealthy = true;
            } elseif ($until < $warnLine) {
                $output->warning(sprintf(
                    '  %-6s 假日数据最后一条是 %s，只剩 %d 天 —— 该跑 tools/export_exchange_calendars.py 续下一年了',
                    $mic, $until, $left
                ));
                $unhealthy = true;
            } else {
                $output->writeln(sprintf('    %-6s %2d 个源 · %d 条假日 · 最后一条 %s（剩 %d 天）',
                    $mic, $coins, $days, $until, $left));
            }

            $unhealthy = $this->checkSegments($mic, $coins, $output) || $unhealthy;
        }

        // 枚举里有、却没有任何行情源跟着走的日历：不是错误，但值得说一声。
        //
        // 一个源都没绑时逐行列会打出全部 68 本 —— 实测 76 行输出里有 68 行是它，把孤儿清理
        // 结果和到期告警全淹了，而这条命令挂在 deploy/update.sh 上、输出正是给人读的。
        // 所以这种情况只打一行摘要。
        if ($micsInUse === []) {
            // 没有任何源绑定日历是当前的正常状态（tradfi 标的还没接行情源），
            // 但仍要说一声 —— 否则「日历数据导了一堆却没人用」这件事没有任何提示
            $output->writeln(sprintf(
                '    暂无任何行情源绑定日历（%d 本日历有数据待用）—— 接入 tradfi 标的时在 '
                . 'database/market_data.sql 给对应源填上 calendar_code',
                count(MarketCalendarEnum::cases())
            ));
        } else {
            foreach (MarketCalendarEnum::cases() as $case) {
                if (!isset($micsInUse[$case->value])) {
                    $output->writeln(sprintf('    %-6s 暂无行情源跟它走（%s）', $case->value, $case->timezone()));
                }
            }
        }

        return $unhealthy;
    }

    /**
     * 体检：这本日历有没有开市时段，没有则返回 true（不健康）。
     *
     * 时段表比假日表更致命：时段缺了是这本日历永远判不出开市（判定第一步就是「落在某段内吗」）。
     * 它不像假日有到期这回事，所以只查有没有。
     */
    private function checkSegments(string $mic, int $coins, Output $output): bool
    {
        $segments = Db::name(self::SESSION_TABLE)
            ->where('calendar_code', $mic)
            ->where('status', CommonStatusEnum::ON->value)
            ->count();
        if ($segments === 0) {
            $output->warning(sprintf(
                '  %-6s %d 个行情源跟它走，但一条开市时段都没有 —— 判定第一步就过不去，该市场会被判成永远休市',
                $mic, $coins
            ));
            return true;
        }
        $output->writeln(sprintf('           └ 开市时段 %d 段', $segments));
        return false;
    }

    /**
     * 校验 HH:MM（24 小时制，两位补零）。
     *
     * 补零是硬要求：这两个值要和时段表的 HH:MM 做字符串比较，'9:30' 与 '09:30'
     * 比大小的结果是反的（'9' > '0'），会静默把时间判反。
     *
     * @param string $time
     * @return bool
     */
    private function isValidTime(string $time): bool
    {
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }
}
