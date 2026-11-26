<?php

declare(strict_types=1);

namespace app\command;

use think\admin\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;
use app\enum\MarketSessionStateEnum;
use app\enum\MarketTypeEnum;
use app\business\service\MarketService;
use app\service\MarketSessionService;

/**
 * 校验交易日历判出来的休市，跟行情实际有没有在更新对得上。
 *
 * 抓的是**反方向**：判休市、却还在持续收数据 —— 多半是日历配错、时区配错或假日数据过期，
 * 后果是这些 pair 随元数据下发后被订阅方当成休市。正方向（判开市却没数据）归 market:price_check_expire。
 * 两个方向合起来才算把日历盯住了。
 *
 * 判据两条同时成立才算命中：
 *   1. 最后一次行情更新距今 < market.stale_seconds —— 还在持续收，不是一笔滞后推送
 *   2. 把那次更新的**时刻**喂回判定，结果是 CLOSED —— 那一刻按我们的日历算是休市
 * 第 2 条用「更新发生的时刻」而不是「此刻」，天然躲开收盘瞬间的误报：周五收盘前最后一笔
 * 落在开市时段内，判出来是 OPEN，整个周末都不会响。
 *
 * 判定走 state() 不走 tradable*：后台总开关关掉只是「不按休市办事」，日历配没配错仍要知道。
 *
 * 命令  php think market:session_check
 * 触发  xadmin:task 队列（300s）· deploy/market_health.sh · 手工
 * 单例  无（只读，重入无副作用）
 * 依赖  表 market_data（原始行情源层，不看 exchange_pair）· 配置 market.stale_seconds
 *       Redis market_session:alerted（告警去重，TTL 1 小时）
 *
 * 用法:
 *   php think market:session_check           # 人看：打印明细，并按去重规则发告警
 *   php think market:session_check --verify  # 脚本用：只给退出码，不打印不告警
 *   php think market:session_check --force   # 清掉去重状态，强制重发一次告警
 *
 * 退出码  0 = 没有冲突；1 = 有源判休市却仍在收数据
 */
class MarketSessionCheck extends Command
{
    use \app\lib\AlertFanoutTrait;

    /** 已告警集合。配置问题不像行情中断那样要每分钟催，TTL 取 1 小时 */
    private const REDIS_KEY_ALERTED = 'market_session:alerted';
    private const ALERT_TTL = 3600;

    /** 一次最多列多少行明细，其余只报数 —— 日历配错时可能整本几十个源一起命中 */
    private const MAX_DETAIL = 20;

    /**
     * 最近一次巡检结果，供后台「休市设置」页展示。
     *
     * TTL 取 1 小时（队列 300s 一轮的 12 倍）：key 没了就说明巡检本身停了，
     * 后台据此显示「巡检未运行」—— 这比「一直显示上次的正常结果」有用得多。
     */
    private const REDIS_KEY_RESULT = 'market_session:last_check';
    private const RESULT_TTL = 3600;

    protected function configure()
    {
        $this->setName('market:session_check')
            ->addOption('verify', null, Option::VALUE_NONE, '只给退出码，不打印不告警（供部署脚本调用）')
            ->addOption('force', null, Option::VALUE_NONE, '清掉去重状态，强制重发一次告警')
            ->setDescription('校验「判休市」与「行情仍在更新」是否冲突，发现日历配错或过期');
    }

    protected function execute(Input $input, Output $output)
    {
        $quiet = (bool)$input->getOption('verify');
        // --verify 也要拿 redis：它同样是一次真实巡检，结果该反映到后台，只是不发告警
        $redis = redis_client();

        if ($input->getOption('force') && $redis) {
            $redis->del(self::REDIS_KEY_ALERTED);
            $output->writeln('[FORCE] 去重状态已清除，本轮命中的会全部重发');
        }

        $hits = $this->scan();
        $this->recordResult($hits, $redis);

        if ($hits === []) {
            if (!$quiet) $output->writeln('[OK] 没有「判休市却仍在收数据」的源');
            return 0;
        }

        if (!$quiet) {
            $output->writeln('[FAIL] ' . count($hits) . ' 个源判休市却仍在收数据，日历可能配错或已过期：');
            foreach (array_slice($hits, 0, self::MAX_DETAIL) as $line) {
                $output->writeln('  ' . $line);
            }
            if (count($hits) > self::MAX_DETAIL) {
                $output->writeln('  …… 另 ' . (count($hits) - self::MAX_DETAIL) . ' 个');
            }
            $this->notify($hits, $redis, $output);
        }

        return 1;
    }

    /**
     * 扫一遍所有配了日历的行情源，返回命中的描述行。
     *
     * @return array<int,string>
     */
    private function scan(): array
    {
        $staleMs = (int)Config::get('market.stale_seconds', 300) * 1000;
        $nowMs   = (int)(microtime(true) * 1000);

        $hits = [];
        foreach ($this->rows() as $row) {
            $sourceKey = (string)($row['source_key'] ?? '');
            if ($sourceKey === '') continue;

            $code = MarketSessionService::calendarOfSource($sourceKey);
            if ($code === '') continue;            // 24/7，没有休市这回事

            $ts = (int)($row['market_ts'] ?? 0);
            if ($ts <= 0 || ($nowMs - $ts) >= $staleMs) continue;   // 已经不更新了，归 stale 那边管

            if (MarketSessionService::state($code, intdiv($ts, 1000)) === MarketSessionStateEnum::OPEN) {
                continue;                          // 那一刻本就该开市，正常
            }

            $ageSec   = (int)(($nowMs - $ts) / 1000);
            $typeEnum = MarketTypeEnum::tryFrom((int)($row['type'] ?? 0));
            $hits[] = sprintf(
                '%s(%s) [%s] 日历 %s —— %d 秒前还在更新，但那一刻判为休市',
                strtoupper((string)($row['symbol_code'] ?? '')),
                $typeEnum ? $typeEnum->labelText() : '未知',
                $sourceKey,
                $code,
                $ageSec
            );
        }

        return $hits;
    }

    /**
     * 扫描对象恒为 market_data —— 原始行情源层，**不看 exchange_pair**。
     *
     * 与 market:price_check_expire 的口径**有意不同**：那边回答「分发出去的价格新不新」，
     * 是 pair 层的事；本命令回答「这本日历配得对不对」，而日历挂在 market_data.calendar_code 上，
     * 是**源**的属性 —— 跟这个源有没有被上架成交易对无关，日历配错要在上架**之前**就发现。
     *
     * 时间戳优先取热层：采集器先写热层，MySQL 只是 MarketDbFlusher 刷盘后的副本。
     *
     * @return array<int,array>
     */
    private function rows(): array
    {
        $out = [];
        Db::name('market_data')->field('id,source_key,symbol,type,update_ts')
            ->chunk(1000, function ($rows) use (&$out) {
                foreach ($rows as $r) {
                    $sk  = (string)$r['source_key'];
                    $hot = MarketService::getHotMarketData($sk, ['update_ts']);
                    $out[] = [
                        'source_key'  => $sk,
                        'symbol_code' => (string)$r['symbol'],
                        'type'        => (int)$r['type'],
                        'market_ts'   => (int)($hot['update_ts'] ?? $r['update_ts']),
                    ];
                }
            });
        return $out;
    }

    /** 落一份最近结果给后台读，不管有没有命中都写 —— 「上次什么时候跑的」本身就是信息 */
    private function recordResult(array $hits, $redis): void
    {
        if (!$redis) return;
        $redis->setex(self::REDIS_KEY_RESULT, self::RESULT_TTL, json_encode([
            'ts'   => time(),
            'hits' => $hits,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** 后台读最近一次巡检结果；返回 null 表示巡检没在跑（key 已过期） */
    public static function lastResult(): ?array
    {
        $redis = redis_client();
        if (!$redis) return null;
        $json = $redis->get(self::REDIS_KEY_RESULT);
        if (!is_string($json) || $json === '') return null;
        $data = json_decode($json, true);
        return is_array($data) && isset($data['ts']) ? $data : null;
    }

    /** 按 Redis 去重后发告警：同一批源 1 小时内只打扰一次 */
    private function notify(array $hits, $redis, Output $output): void
    {
        $prevJson = $redis ? $redis->get(self::REDIS_KEY_ALERTED) : null;
        $prev     = is_string($prevJson) ? (json_decode($prevJson, true) ?: []) : [];

        $fresh = array_values(array_diff($hits, $prev));
        if ($fresh !== []) {
            $shown   = array_slice($fresh, 0, self::MAX_DETAIL);
            $more    = count($fresh) - count($shown);
            $content = "判为休市但行情仍在更新（日历可能配错）：\n" . implode("\n", $shown)
                . ($more > 0 ? "\n…… 另 {$more} 个" : '');

            $this->fanout('休市判定与行情不符', $content, 'error',
                '[' . self::siteName() . "] ⚠️ *休市判定与行情不符*\n" . $content);
            business_log('market_session', "[SESSION-CONFLICT] {$content}", 'error');
            $output->writeln('[ALERT] 已发出告警（' . count($fresh) . ' 个新增）');
        } else {
            $output->writeln('[SKIP] 与上次相同，1 小时内不重复告警');
        }

        if ($redis) {
            $redis->setex(self::REDIS_KEY_ALERTED, self::ALERT_TTL, json_encode($hits, JSON_UNESCAPED_UNICODE));
        }
    }
}
