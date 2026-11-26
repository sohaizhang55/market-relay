<?php

namespace app\business\service;

use think\facade\Db;
use think\facade\Log;
use think\facade\Config;
use app\enum\MarketExchangeEnum;
use app\enum\MarketDataTypeEnum;
use app\enum\MarketTypeEnum;
use app\lib\MarketHelper;
use app\lib\MarketPublisher;

/**
 * 市场行情服务层 (原生 SQL 驱动)
 */
class MarketService
{
    /** @var array<string, array{pairs: array, expire: int}> 进程内一级缓存：source_key -> 关联交易对列表 */
    private static array $pairsCache = [];

    /**
     * @var array<string, array{ts: int, close: string}> 进程内缓存：pair_id:period -> 上一根K线的收盘价
     * syncPairKlines() 补档/实时场景都会按 ts 递增顺序连续调用，命中即可省掉一次
     * "查上一根close算这一根open"的 SELECT；ts 断档 (跳空/进程刚启动) 不命中时照常回落DB查询，
     * 只在DB写入成功后才更新，避免"写失败但缓存以为成功"的脏读
     */
    private static array $lastCloseCache = [];

    /** 进程内缓存有效期 (秒) */
    private const PAIRS_LOCAL_TTL = 3;

    /** Redis 缓存有效期 (秒)：兜底防止 clearPairsCache() 未被触发时的长期脏数据 */
    private const PAIRS_REDIS_TTL = 60;

    /** Redis key 前缀 */
    private const PAIRS_REDIS_PREFIX = 'market:pairs:';

    /**
     * 行情热数据 Redis key 前缀与脏集合 key
     * 高频行情写入 (updatePrice/updateMarkPrice) 不再逐 tick 同步 UPDATE MySQL，
     * 改为暂存到 Redis Hash (作为当前最新快照常驻，不设 TTL) 并 SADD 进脏集合，
     * 由独立进程 MarketDbFlusher 定时 (默认 1s) 抽干脏集合批量落库，
     * 避免高频 WS 消息把 MySQL 写压力打爆；Redis 不可用时自动降级为直接同步写 DB。
     *
     * 四个常量都是 public：本类是唯一写者，但 MarketDbFlusher（抽干脏集合）与
     * 落库链路诊断页（读热层比对四站）都要读同一批 key。它们此前各自硬编码了
     * 同样的字面量，一共 7 处 —— 改前缀时漏掉任何一处，症状都是"数据莫名其妙不落库"
     * 或"诊断页说热层是空的"，而两者都不会报错。
     */
    public const HOT_MD_PREFIX   = 'market:hot:md:';
    public const HOT_PAIR_PREFIX = 'market:hot:pair:';
    public const DIRTY_MD_KEY    = 'market:dirty:md';
    public const DIRTY_PAIR_KEY  = 'market:dirty:pair';

    /**
     * 原始层深度/成交快照 (按 source_key)，供后台「原始行情管理」的深度/成交页查看。
     *
     * 按 source_key 存交易所推过来的原样数据，与 market_data 里的 close/mark_price/funding_rate 口径一致；
     * pair 层（market:depth:pair_{id} / market:trade:pair_{id}）按 source_key 映射后再分发。
     */
    private const RAW_DEPTH_PREFIX = 'market:depth:src_';
    private const RAW_TRADE_PREFIX = 'market:trade:src_';

    /**
     * 原始层深度写入的最小间隔(秒)，按 source_key 各自计时。
     * 交易所深度约 10 帧/秒 × 51 个 source_key，全频写会凭空多出约 500 次 Redis SET/秒；
     * 而这份快照只服务于后台人工查看，1 秒的新鲜度完全够用。
     * 成交不限频：它是增量事件、量小，且限频会直接丢掉成交记录。
     */
    private const RAW_DEPTH_WRITE_INTERVAL = 1.0;

    /** source_key => 上次写原始层深度的时刻(microtime)，进程内限频状态 */
    private static array $rawDepthLastWrite = [];

    /*
     * pair 层每次更新都经 MarketPublisher 写 pair 快照，由 market:dispatch 广播给订阅方。
     * market-relay 只有一条流程（采集 → 更新 pair → 分发），没有浏览器用户，不推 WS。
     */

    /**
     * 获取指定行情源关联的交易对列表 (id, symbol_code, type)
     * 采集链路每条行情消息都会调用本方法，而 source_key -> 交易对 的映射只在后台改交易对配置时才变，
     * 缓存分两级：进程内静态数组 (命中零 IO) -> Redis (跨进程共享，后台改动后由 clearPairsCache() 主动失效) -> 数据库
     * @param string $sourceKey 行情源标识
     * @return array<int, array{id: int, symbol_code: string, type: int}>
     */
    private static function getPairsBySourceKey(string $sourceKey): array
    {
        $cached = self::$pairsCache[$sourceKey] ?? null;
        if ($cached !== null && $cached['expire'] > time()) {
            return $cached['pairs'];
        }

        $pairs = self::fetchPairsBySourceKey($sourceKey);
        self::$pairsCache[$sourceKey] = ['pairs' => $pairs, 'expire' => time() + self::PAIRS_LOCAL_TTL];

        return $pairs;
    }

    /**
     * 后台新增/编辑/启停交易对后调用，清空 source_key -> 交易对 映射缓存
     */
    public static function clearPairsCache(): void
    {
        self::$pairsCache = [];

        $redis = redis_client();
        if (!$redis) return;

        $keys = $redis->keys(self::PAIRS_REDIS_PREFIX . '*');
        if ($keys) {
            $redis->del($keys);
        }
    }

    private static function fetchPairsBySourceKey(string $sourceKey): array
    {
        $redis = redis_client();
        $key = self::PAIRS_REDIS_PREFIX . $sourceKey;

        if ($redis) {
            $cached = $redis->get($key);
            if ($cached !== false && $cached !== null) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) return $decoded;
            }
        }

        $pairs = Db::name('exchange_pair')->where('source_key', $sourceKey)->field('id, symbol_code, type')->select()->toArray();

        if ($redis) {
            $redis->set($key, json_encode($pairs), self::PAIRS_REDIS_TTL);
        }

        return $pairs;
    }

    /**
     * 暂存 market_data 字段到 Redis 热层 (Hash 常驻，不设 TTL，作为当前最新快照) 并标脏
     * Redis 不可用时降级为直接同步写 DB，保证功能不因 Redis 故障而丢数据
     */
    private static function stageMarketData(string $sourceKey, array $fields): void
    {
        $redis = redis_client();
        if (!$redis) {
            Db::name('market_data')->where('source_key', $sourceKey)->update($fields);
            return;
        }
        // 写值与标脏合成一次往返：这是采集/订阅进程的每帧热路径
        $redis->multi(\Redis::PIPELINE)
            ->hMSet(self::HOT_MD_PREFIX . $sourceKey, $fields)
            ->sAdd(self::DIRTY_MD_KEY, $sourceKey)
            ->exec();
    }

    /**
     * 暂存 exchange_pair 字段到 Redis 热层并标脏，语义同 stageMarketData()
     */
    private static function stagePairData(int $pairId, array $fields): void
    {
        $redis = redis_client();
        if (!$redis) {
            Db::name('exchange_pair')->where('id', $pairId)->update($fields);
            return;
        }
        $redis->multi(\Redis::PIPELINE)
            ->hMSet(self::HOT_PAIR_PREFIX . $pairId, $fields)
            ->sAdd(self::DIRTY_PAIR_KEY, (string)$pairId)
            ->exec();
    }

    /**
     * 从 Redis 热层读 market_data 字段，取 stageMarketData() 写进去的那份最新快照
     * 读不到返回 null，调用方回落 MySQL
     */
    public static function getHotMarketData(string $sourceKey, array $fields): ?array
    {
        return self::getHotFields(self::HOT_MD_PREFIX . $sourceKey, $fields);
    }

    /**
     * 从 Redis 热层读 exchange_pair 字段，语义同 getHotMarketData()
     */
    public static function getHotPairData(int $pairId, array $fields): ?array
    {
        return self::getHotFields(self::HOT_PAIR_PREFIX . $pairId, $fields);
    }

    /**
     * 上面两个方法的共用实现
     *
     * 要求 $fields 全部命中，缺一个就返回 null：热层是 hMSet 增量累积的，
     * 不同调用点写的字段集不同，"hash 存在"不等于"要的字段都在"。
     * 异常一律回落不上抛 —— 调用方多在撮合/强平热路径上。
     */
    private static function getHotFields(string $hashKey, array $fields): ?array
    {
        try {
            $redis = redis_client();
            if (!$redis) return null;

            $values = $redis->hMGet($hashKey, $fields);
            if (!is_array($values)) return null;

            $row = [];
            foreach ($fields as $f) {
                // phpredis 缺字段返回 false；'' 一并当缺失，免得流到 (int) 变成 0 时间戳
                $v = $values[$f] ?? false;
                if ($v === false || $v === null || $v === '') return null;
                $row[$f] = (string)$v;
            }
            return $row;
        } catch (\Throwable $e) {
            return null;   // 静默回落，热路径上记日志会把磁盘刷满
        }
    }

    /**
     * 抽干 Redis 脏集合，把行情热数据批量落库到 market_data / exchange_pair
     * 由独立进程 MarketDbFlusher 定时调用 (见 root/app/command/MarketDbFlusher.php)
     * @return array{md: int, pair: int} 本轮落库的 source_key / pair 行数
     */
    /**
     * @param int $limit 单轮最多写多少行（md 与 pair 各自计），0 = 不限。见 RedisDirtySet::swapOut
     */
    public static function flushDirty(int $limit = 0): array
    {
        $redis = redis_client();
        if (!$redis) return ['md' => 0, 'pair' => 0];

        return [
            'md'   => self::flushDirtyMarketData($redis, $limit),
            'pair' => self::flushDirtyPairData($redis, $limit),
        ];
    }

    private static function flushDirtyMarketData($redis, int $limit = 0): int
    {
        $sourceKeys = \app\lib\RedisDirtySet::swapOut($redis, self::DIRTY_MD_KEY, $limit);
        if (empty($sourceKeys)) return 0;

        $rows = [];
        foreach ($sourceKeys as $sourceKey) {
            $fields = $redis->hGetAll(self::HOT_MD_PREFIX . $sourceKey);
            if (empty($fields)) continue;
            $fields['source_key'] = $sourceKey;
            $rows[] = $fields;
        }
        if (empty($rows)) return 0;

        $res = self::batchUpdate('market_data', 'source_key', $rows);
        if ($res['failed']) {
            // 失败的不放回脏集合，记录后跳过，见 batchUpdate() 注释 ③
            self::$flushFail++;
        }
        // 如实返回**写成功**的行数，不再把整批当成功报
        return count($rows) - count($res['failed']);
    }

    /**
     * 整批失败后逐行重试（**不开事务**），把爆炸半径从「整批」缩到「那一行」。
     *
     * 整批那次已经回滚，所以这里是从零重写一遍。绝大多数情况下只有一两行是坏的，
     * 其余几十行会正常写进去 —— 相比之下原来的行为是"一行坏、全批冻住"。
     *
     * 这里是**最后一次机会**：本方法失败的行不会再进任何重试队列，
     * 只记日志然后跳过（见 batchUpdate 注释 ③），所以每个失败主键都必须在这里被记下来。
     *
     * @return array{failed:array,err:string}
     */
    private static function retryRowByRow(string $table, string $pkField, array $rows): array
    {
        $failed = [];
        $firstErr = '';
        foreach ($rows as $row) {
            $pkValue = $row[$pkField];
            unset($row[$pkField]);
            if (empty($row)) continue;
            try {
                Db::name($table)->where($pkField, $pkValue)->update($row);
            } catch (\Throwable $e) {
                $failed[] = $pkValue;
                // PDO 的异常会把整条 SQL 和绑定参数都带上，取首行并截断
                $reason = mb_substr(trim(explode("\n", $e->getMessage())[0]), 0, 180);
                if ($firstErr === '') {
                    // 带上主键点名是哪一行，光有「Out of range value for column 'vol'」
                    // 还得再猜是哪个交易对。只留首行，整条 SQL 会把事件流水冲掉
                    $firstErr = sprintf('%s[%s]: %s', $table, $pkValue, $reason);
                }
                // 每个失败主键都要在业务日志里点到名 —— $firstErr 只带得出一行
                self::logFlushFail($table, $pkValue, $reason);
                Log::error("MarketService::batchUpdate({$table}) row {$pkValue}: " . $e->getMessage());
            }
        }
        if ($failed) self::$flushError = $firstErr;
        return ['failed' => $failed, 'err' => $firstErr];
    }

    /**
     * 落库失败的行记进 business_log，按 表|主键|错误类型 节流 60 秒
     *
     * 失败多是持续性的（缺列、值越界），热门交易对每秒重新标脏就每秒失败一次，
     * 不节流会把日志刷成一整片同样的错误。节流键取错误类型而不是错误全串
     * —— MySQL 异常带着具体行的值，按全串比等于每行都是新情况。
     */
    private static function logFlushFail(string $table, $pkValue, string $err): void
    {
        $kind = preg_match('#SQLSTATE\[[0-9A-Za-z]+\]#', $err, $m) ? $m[0] : mb_substr($err, 0, 40);
        $mark = "{$table}|{$pkValue}|{$kind}";
        $now  = time();

        $seen = self::$failLogSeen[$mark] ?? ['at' => 0, 'n' => 0];
        $seen['n']++;
        if ($seen['at'] > 0 && ($now - $seen['at']) < self::FAIL_LOG_INTERVAL) {
            self::$failLogSeen[$mark] = $seen;
            return;
        }

        business_log('market_db_flush_stat', sprintf(
            '[落库失败·已跳过] %s[%s]%s：%s',
            $table, $pkValue,
            $seen['n'] > 1 ? sprintf('(近 %ds 累计 %d 次)', self::FAIL_LOG_INTERVAL, $seen['n']) : '',
            $err
        ), 'error');

        self::$failLogSeen[$mark] = ['at' => $now, 'n' => 0];
    }

    /**
     * 失败日志的节流状态：表|主键|错误类型 => ['at' => 上次落日志时刻, 'n' => 期间累计]
     * 键的基数有界（source_key 数 × 错误类型数），不会无限膨胀。
     */
    private static array $failLogSeen = [];

    /** 同一个 表+主键+错误类型 最多多少秒记一条 */
    private const FAIL_LOG_INTERVAL = 60;

    /** 落库写失败次数，由 MarketDbFlusher 定期取走并上报 */
    private static int $flushFail = 0;

    /** 最近一次落库失败的原因，跟着计数一起上报 —— 只报次数不报原因等于没报 */
    private static string $flushError = '';

    /** @return array{n:int,err:string} */
    public static function takeFlushFail(): array
    {
        $r = ['n' => self::$flushFail, 'err' => self::$flushError];
        self::$flushFail = 0;
        self::$flushError = '';
        return $r;
    }

    private static function flushDirtyPairData($redis, int $limit = 0): int
    {
        $pairIds = \app\lib\RedisDirtySet::swapOut($redis, self::DIRTY_PAIR_KEY, $limit);
        if (empty($pairIds)) return 0;

        $rows = [];
        foreach ($pairIds as $pairId) {
            $fields = $redis->hGetAll(self::HOT_PAIR_PREFIX . $pairId);
            if (empty($fields)) continue;
            $fields['id'] = (int)$pairId;
            $rows[] = $fields;
        }
        if (empty($rows)) return 0;

        // 同 flushDirtyMarketData()：失败的记录后跳过，不放回脏集合
        $res = self::batchUpdate('exchange_pair', 'id', $rows);
        if ($res['failed']) {
            self::$flushFail++;
        }
        return count($rows) - count($res['failed']);
    }

    /**
     * 按主键逐行 UPDATE，包在一个事务里减少提交开销。
     * 每行字段集合可能不同（取决于该 key 暂存过哪些字段），所以不拼多行 INSERT ON DUPLICATE KEY UPDATE。
     *
     * 三条失败处理，都是被线上问题逼出来的：
     *   ① 返回失败原因而不只是成败——最常见的失败是热层某个 field 越界或不是表的列，只报「失败了」无从排查
     *   ② 整批失败降级为逐行重试——整批一个事务时一行越界就让整轮回滚，51 个源全写不进去，表现是整张表冻住
     *   ③ 逐行再失败就只记日志、跳过，**不放回脏集合**。落库失败几乎都是持续性的（缺列、越界），
     *      重试改变不了输入，只会让坏 key 在脏集合里循环（dirty 只涨不落，2026-08-26 的 bbo_bid 事故就是这个形态）。
     *      跳过安全是因为热层存的是最新快照不是增量，下一个 tick 会重新标脏、重新落库。
     *
     * @param string $table 表名
     * @param string $pkField 主键字段名 (值取自每行同名 key，写入前会 unset)
     * @param array $rows 每行至少包含 $pkField
     * @return array{failed:array,err:string} failed=写失败的主键；err=失败原因，空串表示全部成功
     */
    private static function batchUpdate(string $table, string $pkField, array $rows): array
    {
        // startTrans() 必须在 try **里面**：MySQL 不可达时它自己就会抛，
        // 原先放在外面会让异常一路穿出 flushDirty()、穿出 MarketDbFlusher 的 Timer 回调
        // —— Workerman 里那等于**整个 worker 崩掉重启**，而这一轮已被 swapOut 取走的
        // source_key 连放回脏集合的机会都没有
        try {
            Db::startTrans();
            foreach ($rows as $row) {
                $pkValue = $row[$pkField];
                unset($row[$pkField]);
                if (empty($row)) continue;
                Db::name($table)->where($pkField, $pkValue)->update($row);
            }
            Db::commit();
            return ['failed' => [], 'err' => ''];
        } catch (\Throwable $e) {
            // 事务可能压根没开起来，回滚本身也可能抛
            try { Db::rollback(); } catch (\Throwable $ignore) {}
            Log::error("MarketService::batchUpdate({$table}) Error: " . $e->getMessage());
            return self::retryRowByRow($table, $pkField, $rows);
        }
    }

    /**
     * 更新行情价格与快照
     * @param MarketExchangeEnum $exchange 交易所枚举 (供应商)
     * @param string $symbol 外部币种代码
     * @param int $type 行情类型 (1:现货, 2:合约) @see MarketDataTypeEnum
     * @param array $tick 行情数据
     * @param int|float $ts 原始毫秒级时间戳
     * @return bool
     */
    public static function updatePrice(MarketExchangeEnum $exchange, string $symbol, int $type, array $tick, $ts = 0): bool
    {
        $normalizedSymbol = str_replace(['-', '_', '/'], '', strtolower($symbol));
        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);
        $nowStr = date('Y-m-d H:i:s');
        $nowTs = time();

        $data = [
            'update_ts'  => $ts,
            'open'       => $tick['open'] ?? 0,
            'close'      => $tick['close'] ?? 0,
            'low'        => $tick['low'] ?? 0,
            'high'       => $tick['high'] ?? 0,
            'amount'     => $tick['amount'] ?? 0,
            'vol'        => $tick['vol'] ?? 0,
            'count'      => $tick['count'] ?? 0,
            'update_at'  => $nowStr,
        ];

        try {
            // 1. 暂存原始行情池数据到 Redis 热层并标脏 (取代逐 tick 同步 UPDATE)
            self::stageMarketData($sourceKey, $data);

            // 2. 获取并同步更新平台交易对快照
            $pairs = self::getPairsBySourceKey($sourceKey);
            $resCount = 0;

            // safeNumStr 避免极低价格币种科学计数法导致 bcmath ValueError
            $close = MarketHelper::safeNumStr($tick['close'] ?? 0);
            $open  = MarketHelper::safeNumStr($tick['open'] ?? 0);

            foreach ($pairs as $pair) {
                $updatePair = [
                    'last_price' => $close,
                    'open'       => $open,
                    'high'       => MarketHelper::safeNumStr($tick['high'] ?? 0),
                    'low'        => MarketHelper::safeNumStr($tick['low'] ?? 0),
                    'close'      => $close,
                    'amount'     => $tick['amount'] ?? 0,
                    'vol'        => $tick['vol'] ?? 0,
                    // 24h 涨跌幅%，随行情同步落库，供后台按涨幅排序 (与 API 层 calc_change_rate 现算口径一致)
                    'change'     => calc_change_rate($close, $open),
                    'market_ts'  => $ts,
                    'update_at'  => $nowStr,
                    'update_ts'  => $nowTs,
                ];

                self::stagePairData((int)$pair['id'], $updatePair);

                MarketPublisher::publishTicker($pair, $updatePair);

                $resCount++;
            }

            return $resCount > 0;
        } catch (\Throwable $e) {
            Log::error("updatePrice Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新标记价格 (采集自交易所官方 markPrice，仅合约行情有该数据)
     * 同步写入 market_data (原始行情池) 与 exchange_pair (供订阅方强平判断使用)
     * @param MarketExchangeEnum $exchange 交易所
     * @param string $symbol 外部币种代码
     * @param int $type 行情类型 (1:现货, 2:合约) @see MarketDataTypeEnum
     * @param string $markPrice 标记价格
     * @param int $ts 原始毫秒级时间戳
     * @return bool
     */
    public static function updateMarkPrice(MarketExchangeEnum $exchange, string $symbol, int $type, string $markPrice, int $ts = 0): bool
    {
        // safeNumStr 避免极低价格币种科学计数法导致 bcmath ValueError
        $markPrice = MarketHelper::safeNumStr($markPrice);
        if (bccomp($markPrice, '0', 10) <= 0) return false;

        try {
            $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);

            self::stageMarketData($sourceKey, [
                'mark_price' => $markPrice,
                'mark_ts'    => $ts,
            ]);

            $pairs = self::getPairsBySourceKey($sourceKey);
            foreach ($pairs as $pair) {
                self::stagePairData((int)$pair['id'], [
                    'mark_price' => $markPrice,
                    'mark_ts'    => $ts,
                ]);

                MarketPublisher::publishMarkPrice($pair, $markPrice, (int)$ts);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("updateMarkPrice Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新资金费率 (采集自交易所，只对合约有意义)
     *
     * 结构与 updateMarkPrice() 对称（原始层 market_data → 行情层 exchange_pair，都走热层），
     * 差异：不推 WS —— 8 小时一档的慢变量，App 走 config 接口取
     *
     * @param string $rate 当前费率，可为负 (空头付多头)
     * @param string $estimated 预估下期费率
     * @param int $ts 采集毫秒时间戳
     * @param int $exchangeNextFundingTs 交易所自报的下次结算毫秒时间戳，仅记录备用，
     *                                   不参与本平台结算窗口计算 (next_funding_ts 由结算独占)
     */
    public static function updateFundingRate(MarketExchangeEnum $exchange, string $symbol, int $type, string $rate, string $estimated, int $ts, int $exchangeNextFundingTs = 0): bool
    {
        // 资金费率只对合约有意义，现货直接跳过
        if ($type !== MarketDataTypeEnum::SWAP->value) return false;

        // 费率可以是负数，不能像价格那样用 "<=0 即无效" 过滤；只要求是个合法数值
        if (!is_numeric($rate)) return false;
        $rate      = bcadd(MarketHelper::safeNumStr($rate), '0', 6);
        $estimated = is_numeric($estimated) ? bcadd(MarketHelper::safeNumStr($estimated), '0', 6) : $rate;

        try {
            $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);

            // 1. 原始行情层 (market_data)，毫秒
            self::stageMarketData($sourceKey, [
                'funding_rate'             => $rate,
                'estimated_funding_rate'   => $estimated,
                'funding_rate_ts'          => $ts,
                'exchange_next_funding_ts' => $exchangeNextFundingTs,
            ]);


            // 2. pair 行情层 (exchange_pair)，同为毫秒
            foreach (self::getPairsBySourceKey($sourceKey) as $pair) {
                self::stagePairData((int)$pair['id'], [
                    'funding_rate'           => $rate,
                    'estimated_funding_rate' => $estimated,
                    'funding_rate_ts'        => $ts,
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("updateFundingRate Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新原始K线数据 (采集自交易所)
     * @param MarketExchangeEnum $exchange 交易所
     * @param int $type 行情类型 (1:现货, 2:合约) @see MarketDataTypeEnum
     * @param string $symbol 符号
     * @param string $period 周期
     * @param array $kline K线数据
     * @param bool $isPadding 是否为断档回补的历史蜡烛，为 true 时正常落库但不推 WS
     * @return bool
     */
    public static function updateSourceKline(MarketExchangeEnum $exchange, int $type, string $symbol, string $period, array $kline, bool $isPadding = false): bool
    {
        $ts = (int)($kline['id'] ?? 0) * 1000; // 转换为毫秒存储
        if (!$ts) return false;
        $normalizedSymbol = str_replace(['-', '_', '/'], '', strtolower($symbol));
        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);

        $open  = (float)($kline['open'] ?? 0);
        $close = (float)($kline['close'] ?? 0);

        $where = [
            'source_key' => $sourceKey,
            'period'     => $period,
            'ts'         => $ts,
        ];

        $data = [
            'open'   => $open,
            'close'  => $close,
            'low'    => $kline['low'] ?? 0,
            'high'   => $kline['high'] ?? 0,
            'amount' => $kline['amount'] ?? 0,
            'vol'    => $kline['vol'] ?? 0,
        ];

        try {
            $insertData = array_merge($where, $data, [
                'exchange'  => $exchange->value,
                'symbol'    => $normalizedSymbol,
                'type'      => $type,
                'create_ts' => time(),
            ]);

            // 回补分支与实时路径的降级分支共用这条 SQL
            $sql = "INSERT INTO `market_source_kline`
                    (`source_key`, `period`, `ts`, `open`, `close`, `low`, `high`, `amount`, `vol`, `exchange`, `symbol`, `type`, `create_ts`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    `open`=VALUES(`open`), `close`=VALUES(`close`), `low`=VALUES(`low`), `high`=VALUES(`high`), `amount`=VALUES(`amount`), `vol`=VALUES(`vol`)";

            if ($isPadding) {
                // 断档回补场景：低频批量调用，没有节流必要，保持原来的立即落库行为
                Db::execute($sql, array_values($insertData));
            } else {
                // 实时单tick路径：写 Redis 缓冲，由 MarketDbFlusher 批量落库 (见 stageKline())，
                // 避免高频交易对每条tick都同步打一次MySQL导致处理跟不上、消息积压延迟。
                // merge=false 纯覆盖：交易所上报的当前 K 线快照本就是最新累计值，最新的赢，
                // 与本表落库 SQL 的 ON DUPLICATE 子句 (全列 VALUES() 覆盖) 语义一致。
                // 返回 null = Redis 不可用或脚本失败，降级同步直写，用的就是上面那条 SQL
                if (self::stageKline('src', $sourceKey . ':' . $period . ':' . $ts, $insertData, false) === null) {
                    Db::execute($sql, array_values($insertData));
                }
            }


            // 同步生成 pair 层 K 线
            $pairs = self::getPairsBySourceKey($sourceKey);
            foreach ($pairs as $pair) {
                self::syncPairKlines($pair, $period, array_merge($data, ['ts' => $ts]), $isPadding);
            }

            // 这个周期若是别的周期的汇总基础（火币 4 小时线 → 日线），把上面当前那一根也算出来
            self::deriveKlineLive($exchange, $type, $symbol, $sourceKey, (int)$period, $ts, $data, $isPadding);

            return true;
        } catch (\Throwable $e) {
            Log::error("updateSourceKline Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @var array<string, array{bucket:int, upto:int, agg:?array, at:int}> deriveKlineLive() 的缓存：
     * 「source_key|汇总周期」=> 当前这一根里**已收盘**的基础 K 线的汇总。每个 tick 只拿它和当前这根
     * 基础 K 线合并，不查库；基础周期换根时重算，另外最多 DERIVED_CLOSED_TTL 秒也重算一次 ——
     * 已收盘的基础 K 线可能被补数进程事后补进来（另一个进程，通知不到这里）
     */
    private static array $derivedClosed = [];

    /** $derivedClosed 的有效期(秒) */
    private const DERIVED_CLOSED_TTL = 60;

    /**
     * 实时汇总：基础周期的一根 K 线更新后，重算它所在的汇总周期当前那一根并写入源层（再同步到 pair 层）
     *
     * 只处理 MarketHelper::klinePlan() 里以本周期为基础的那些周期。汇总按 UTC 整除分桶，
     * 规则：开盘取第一根，收盘取最后一根，最高最低取极值，成交量与成交额求和。
     * 当前这一根还在走，所以盘中写进去的是「到此刻为止」的汇总，和交易所推的日线语义一样。
     */
    private static function deriveKlineLive(MarketExchangeEnum $exchange, int $type, string $symbol, string $sourceKey, int $base, int $baseTs, array $baseData, bool $isPadding): void
    {
        foreach (MarketHelper::klinePlan($exchange)['derived'] as $period => $from) {
            if ($from !== $base) continue;

            if ($isPadding) {
                self::rebuildDerivedKlines($exchange, $type, $symbol, $period, $baseTs, $baseTs);
                continue;
            }

            $periodMs = $period * 1000;
            $bucket   = intdiv($baseTs, $periodMs) * $periodMs;
            $current  = self::candleOf($baseData);
            $closed   = self::derivedClosedPart($sourceKey, $base, $period, $bucket, $baseTs);
            $agg      = $closed === null ? $current : self::mergeCandles($closed, $current);

            self::updateSourceKline($exchange, $type, $symbol, (string)$period, ['id' => intdiv($bucket, 1000)] + $agg);
        }
    }

    /**
     * 汇总周期当前这一根里、$baseTs 之前已收盘的基础 K 线的汇总；一根都没有（这根基础 K 线就是第一根）返回 null
     *
     * 已收盘的基础 K 线可能还在 Redis 缓冲里没落库（每秒刷一次），所以先读缓冲、缺的再查库
     */
    private static function derivedClosedPart(string $sourceKey, int $base, int $period, int $bucket, int $baseTs): ?array
    {
        $cacheKey = $sourceKey . '|' . $period;
        $hit = self::$derivedClosed[$cacheKey] ?? null;
        if ($hit !== null && $hit['bucket'] === $bucket && $hit['upto'] === $baseTs
            && time() - $hit['at'] < self::DERIVED_CLOSED_TTL) return $hit['agg'];

        $baseMs = $base * 1000;
        $tsList = $baseTs > $bucket ? range($bucket, $baseTs - $baseMs, $baseMs) : [];

        $rows  = [];
        $redis = redis_client();
        foreach ($tsList as $ts) {
            $buf = $redis ? (array)$redis->hGetAll(self::klineBufKey('src', $sourceKey . ':' . $base . ':' . $ts)) : [];
            if ($buf) $rows[$ts] = $buf;
        }
        $missing = array_values(array_diff($tsList, array_keys($rows)));
        if ($missing) {
            $db = Db::name('market_source_kline')
                ->where('source_key', $sourceKey)->where('period', (string)$base)->whereIn('ts', $missing)
                ->field('ts, open, high, low, close, vol, amount')->select()->toArray();
            foreach ($db as $r) $rows[(int)$r['ts']] = $r;
        }
        ksort($rows);

        $agg = null;
        foreach ($rows as $r) {
            $c   = self::candleOf($r);
            $agg = $agg === null ? $c : self::mergeCandles($agg, $c);
        }
        self::$derivedClosed[$cacheKey] = ['bucket' => $bucket, 'upto' => $baseTs, 'agg' => $agg, 'at' => time()];
        return $agg;
    }

    /** 取出一根 K 线的 OHLCV，统一成定点字符串（bcmath 不收 float / 科学计数法） */
    private static function candleOf(array $k): array
    {
        return [
            'open'   => MarketHelper::safeNumStr($k['open'] ?? 0),
            'high'   => MarketHelper::safeNumStr($k['high'] ?? 0),
            'low'    => MarketHelper::safeNumStr($k['low'] ?? 0),
            'close'  => MarketHelper::safeNumStr($k['close'] ?? 0),
            'vol'    => MarketHelper::safeNumStr($k['vol'] ?? 0),
            'amount' => MarketHelper::safeNumStr($k['amount'] ?? 0),
        ];
    }

    /** 两段相邻 K 线合并成一段：$earlier 在前、$later 在后 */
    private static function mergeCandles(array $earlier, array $later): array
    {
        return [
            'open'   => $earlier['open'],
            'high'   => bccomp($later['high'], $earlier['high'], 10) > 0 ? $later['high'] : $earlier['high'],
            'low'    => bccomp($later['low'], $earlier['low'], 10) < 0 ? $later['low'] : $earlier['low'],
            'close'  => $later['close'],
            'vol'    => bcadd($earlier['vol'], $later['vol'], 10),
            'amount' => bcadd($earlier['amount'], $later['amount'], 10),
        ];
    }

    /**
     * 按库里的基础 K 线重算一段时间内的汇总 K 线（只算已收盘、基础 K 线齐全的那些根），批量写入源层并同步 pair 层
     *
     * 调用方：updateSourceKlineBatch()（基础周期被补数 / 补历史写入后）、market:kline_backfill 的汇总兜底。
     * 当前还没收盘的那一根不在这里算，由 deriveKlineLive() 负责。
     * 基础 K 线不齐的那一根跳过不写：拿残缺的数据汇总出一根「看起来正常」的 K 线，比缺一根更难发现；
     * 等基础周期补齐后会再触发一次重算。
     *
     * @param int $period 要重算的汇总周期(秒)，必须在该交易所的 klinePlan()['derived'] 里
     * @return int 写入的根数
     */
    public static function rebuildDerivedKlines(MarketExchangeEnum $exchange, int $type, string $symbol, int $period, int $fromTs, int $toTs): int
    {
        $base = MarketHelper::klinePlan($exchange)['derived'][$period] ?? null;
        if ($base === null) return 0;

        $periodMs = $period * 1000;
        $first    = intdiv($fromTs, $periodMs) * $periodMs;
        $last     = intdiv($toTs, $periodMs) * $periodMs;
        // 只算已收盘的：收盘时刻还没到的那一根交给实时汇总
        $nowMs = (int)(microtime(true) * 1000);
        if ($last + $periodMs > $nowMs) $last -= $periodMs;
        if ($last < $first) return 0;

        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);
        $rows = Db::name('market_source_kline')
            ->where('source_key', $sourceKey)->where('period', (string)$base)
            ->whereBetween('ts', [$first, $last + $periodMs - 1])
            ->order('ts', 'asc')->field('ts, open, high, low, close, vol, amount')->select()->toArray();

        $need    = intdiv($period, $base);
        $buckets = [];
        foreach ($rows as $r) {
            $bucket = intdiv((int)$r['ts'], $periodMs) * $periodMs;
            $c      = self::candleOf($r);
            $buckets[$bucket]['agg'] = isset($buckets[$bucket]) ? self::mergeCandles($buckets[$bucket]['agg'], $c) : $c;
            $buckets[$bucket]['n']   = ($buckets[$bucket]['n'] ?? 0) + 1;
        }

        $candles = [];
        foreach ($buckets as $bucket => $b) {
            if ($b['n'] < $need) continue;
            $candles[] = ['id' => intdiv($bucket, 1000)] + $b['agg'];
        }
        return $candles ? self::updateSourceKlineBatch($exchange, $type, $symbol, (string)$period, $candles) : 0;
    }
    /**
     * 更新深度数据 (OrderBook)
     * @param MarketExchangeEnum $exchange 交易所
     * @param int $type 行情类型 (1:现货, 2:合约)
     * @param string $symbol 符号
     * @param array $depth 深度数据 ['bids' => [], 'asks' => []]
     * @return bool
     */
    public static function updateDepth(MarketExchangeEnum $exchange, int $type, string $symbol, array $depth): bool
    {
        $normalizedSymbol = str_replace(['-', '_', '/'], '', strtolower($symbol));
        $redis = redis_client();
        if (!$redis) return false;

        // 1. 准备深度数据 (仅保留前 20 档)
        $bids = array_slice($depth['bids'] ?? [], 0, 20);
        $asks = array_slice($depth['asks'] ?? [], 0, 20);
        $ts   = $depth['ts'] ?? (int)(microtime(true) * 1000);

        try {
            // 2. 同步分发到所有关联的平台交易对 (PairID)
            $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);

            // 2.0 原始层快照 (按 source_key)，供后台原始行情深度页查看，限频写，见 RAW_DEPTH_WRITE_INTERVAL
            self::stageRawDepth($redis, $sourceKey, $bids, $asks, $ts);

            $pairs = self::getPairsBySourceKey($sourceKey);

            $data = ['bids' => $bids, 'asks' => $asks, 'ts' => $ts];
            $json = json_encode($data);

            foreach ($pairs as $pair) {
                $pairId = (int)$pair['id'];

                // 更新 Redis 缓存
                $redis->set("market:depth:pair_{$pairId}", $json);
                // 写 pair 快照供分发
                MarketPublisher::publishDepth($pair, $data);
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("updateDepth Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 写原始层深度快照 (按 source_key)，限频。
     * 纯观测用途，失败不影响主流程，所以不抛异常、不返回结果。
     */
    private static function stageRawDepth($redis, string $sourceKey, array $bids, array $asks, int $ts): void
    {
        $now = microtime(true);
        $last = self::$rawDepthLastWrite[$sourceKey] ?? 0;
        if (($now - $last) < self::RAW_DEPTH_WRITE_INTERVAL) return;
        self::$rawDepthLastWrite[$sourceKey] = $now;

        try {
            $redis->set(self::RAW_DEPTH_PREFIX . $sourceKey, json_encode([
                'bids' => $bids,
                'asks' => $asks,
                'ts'   => $ts,
            ]));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 写原始层成交流水 (按 source_key)，保留最近 30 条，形状与 pair 侧一致。
     * 不限频：成交是增量事件，限频等于直接丢记录。
     */
    private static function stageRawTrades($redis, string $sourceKey, array $trades): void
    {
        try {
            $key = self::RAW_TRADE_PREFIX . $sourceKey;
            $existingJson = $redis->get($key);
            $existing = $existingJson ? json_decode($existingJson, true, 512, JSON_BIGINT_AS_STRING) : [];
            if (!is_array($existing)) $existing = [];

            $existingIds = array_column($existing, 'id');
            foreach ($trades as $t) {
                if (!in_array($t['id'], $existingIds)) array_unshift($existing, $t);
            }

            $redis->set($key, json_encode(array_slice($existing, 0, 30)));
        } catch (\Throwable $e) {
        }
    }

    /**
     * 读原始层深度快照，供后台页面使用
     * @return array{bids: array, asks: array, ts: int}
     */
    public static function getRawDepthBySourceKey(string $sourceKey): array
    {
        $redis = redis_client();
        if (!$redis) return ['bids' => [], 'asks' => [], 'ts' => 0];

        $json = $redis->get(self::RAW_DEPTH_PREFIX . $sourceKey);
        $data = $json ? json_decode($json, true) : null;

        return is_array($data)
            ? ['bids' => $data['bids'] ?? [], 'asks' => $data['asks'] ?? [], 'ts' => (int)($data['ts'] ?? 0)]
            : ['bids' => [], 'asks' => [], 'ts' => 0];
    }

    /**
     * 读原始层成交流水，供后台页面使用
     */
    public static function getRawTradesBySourceKey(string $sourceKey): array
    {
        $redis = redis_client();
        if (!$redis) return [];

        $json = $redis->get(self::RAW_TRADE_PREFIX . $sourceKey);
        $data = $json ? json_decode($json, true, 512, JSON_BIGINT_AS_STRING) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * 读 pair 层深度快照（就是分发出去的那份），供后台页面使用
     * @return array{bids: array, asks: array, ts: int}
     */
    public static function getPairDepth(int $pairId): array
    {
        $redis = redis_client();
        $json  = $redis ? $redis->get("market:depth:pair_{$pairId}") : null;
        $data  = $json ? json_decode($json, true) : null;

        return is_array($data)
            ? ['bids' => $data['bids'] ?? [], 'asks' => $data['asks'] ?? [], 'ts' => (int)($data['ts'] ?? 0)]
            : ['bids' => [], 'asks' => [], 'ts' => 0];
    }

    /**
     * 读 pair 层成交流水（就是分发出去的那份），供后台页面使用
     */
    public static function getPairTrades(int $pairId): array
    {
        $redis = redis_client();
        $json  = $redis ? $redis->get("market:trade:pair_{$pairId}") : null;
        $data  = $json ? json_decode($json, true, 512, JSON_BIGINT_AS_STRING) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * 根据源层 K 线生成 pair 层 K 线
     * @param array $pair 交易对信息
     * @param string $period 周期
     * @param array $sourceKline 原始 K 线数据 (已包含 ts)
     * @param bool $isPadding 是否为断档回补的历史蜡烛：仍正常落库(exchange_pair_kline)，但跳过 WS 推送，
     *                        避免一次回补几十根历史蜡烛时对已连接客户端连续推送造成图表闪烁
     */
    public static function syncPairKlines(array $pair, string $period, array $sourceKline, bool $isPadding = false): void
    {
        $pairId = (int)$pair['id'];
        $ts = $sourceKline['ts'];

        $where = [
            'pair_id'     => $pairId,
            'period'      => $period,
            'ts'          => $ts,
        ];

        // 新蜡烛的 open 优先"继承上一根的 close"：保证蜡烛首尾相接，这也是大多数图表约定俗成的做法；
        // 真实行情本来 close_N ≈ open_{N+1}，与直接取源 open 基本相等。只有该周期第一个 tick 的
        // INSERT 分支会真正用到这个值——若该 ts 的行已存在 (后续同周期 tick)，
        // MySQL 的 ON DUPLICATE KEY UPDATE 不会碰 open 列，这里算出来的候选值会被直接丢弃。
        $periodMs = (int)$period * 1000;
        $closeCacheKey = $pairId . ':' . $period;
        $cachedClose = self::$lastCloseCache[$closeCacheKey] ?? null;
        if ($cachedClose !== null && $cachedClose['ts'] === $ts - $periodMs) {
            // 命中进程内缓存 (补档/实时都是按 ts 递增顺序连续调用)，省掉一次SELECT；
            // 不命中 (跳空断档/进程刚启动/缓存被上一次写入失败清空) 照常回落DB查询，行为不变
            $prevClose = $cachedClose['close'];
        } else {
            $prevClose = Db::name('exchange_pair_kline')
                ->where('pair_id', $pairId)->where('period', $period)->where('ts', $ts - $periodMs)
                ->value('close');
            $prevClose = $prevClose !== null ? (string)$prevClose : null;
        }
        // safeNumStr 避免极低价格币种科学计数法 (如 "4.34E-6") 导致 bcmath ValueError
        $openValue = $prevClose ?? MarketHelper::safeNumStr($sourceKline['open']);

        $data = [
            'open'        => $openValue,
            'high'        => MarketHelper::safeNumStr($sourceKline['high']),
            'low'         => MarketHelper::safeNumStr($sourceKline['low']),
            'close'       => MarketHelper::safeNumStr($sourceKline['close']),
            'vol'         => $sourceKline['vol'],
            'amount'      => $sourceKline['amount'],
        ];

        // 逻辑修正：如果 open 调整后超出了 high/low 范围，同步修正它们，防止蜡烛图畸形
        if (bccomp((string)$data['open'], (string)$data['high'], 10) > 0) $data['high'] = $data['open'];
        if (bccomp((string)$data['open'], (string)$data['low'], 10) < 0) $data['low'] = $data['open'];

        try {
            $insertData = array_merge($where, $data);

            // 回补分支与实时路径的降级分支共用这条 SQL。
            // high/low 用 GREATEST/LEAST 累计极值 (交易所上报的当前K线 high/low 本就单调不减/不增)，
            // 再加上 open 被替换成上一根 close，保证 open 落在 [low, high] 内。
            // ⚠️ open 不放进 UPDATE 子句：只有该周期第一个 tick 的 INSERT 分支会设置 open，后续
            // 同周期内的 tick 只推进 close/high/low，open 保持不变，理由见上面注释。
            $sql = "INSERT INTO `exchange_pair_kline`
                    (`pair_id`, `period`, `ts`, `open`, `high`, `low`, `close`, `vol`, `amount`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    `high`=GREATEST(`high`,VALUES(`high`)), `low`=LEAST(`low`,VALUES(`low`)), `close`=VALUES(`close`), `vol`=VALUES(`vol`), `amount`=VALUES(`amount`)";

            if ($isPadding) {
                // 断档回补场景：低频批量调用，没有节流必要，保持原来的立即落库行为
                Db::execute($sql, array_values($insertData));
                self::$lastCloseCache[$closeCacheKey] = ['ts' => $ts, 'close' => (string)$data['close']];
                return; // 断档回补不推送 WS，历史蜡烛推给正在看盘的用户只会造成图表突然插入闪烁
            }

            // 实时单tick路径：写 Redis 缓冲 + 由 MarketDbFlusher 批量落库 (见 stageKline())，
            // 避免每条tick都同步打一次MySQL。缓冲维护的就是"跟下面这条 ON DUPLICATE KEY UPDATE 完全对齐"
            // 的合并结果 (open保留首次/high取max/low取min/其余覆盖为最新)，WS 推送直接用这份合并后的
            // 最新状态——不用再回读DB，既省了一次SELECT，推送时效性也不受落库节流影响。
            $merged = self::stageKline('pair', $pairId . ':' . $period . ':' . $ts, $insertData, true);

            if ($merged === null) {
                // 降级：Redis 不可用 (或脚本执行失败)，回到搬迁前的同步直写，行为与 isPadding 分支一致。
                // 与 stageMarketData()/stagePairData() 的兜底同策略——宁可让采集进程被 MySQL 拖慢，
                // 也不丢 K 线。$insertData 就是本次现算值，直接拿来推 WS
                Db::execute($sql, array_values($insertData));
                $merged = $insertData;
            }

            self::$lastCloseCache[$closeCacheKey] = ['ts' => $ts, 'close' => (string)$merged['close']];

            MarketPublisher::publishKline($pair, (string)$period, $merged);

        } catch (\Throwable $e) {
            Log::error("syncPairKlines Error: " . $e->getMessage());
        }
    }

    /**
     * 刷盘：把三条实时单tick路径写进 Redis 缓冲的行换出、批量落库，替代原来"来一条tick就同步
     * INSERT一次"。**由 MarketDbFlusher 独占调用** —— 缓冲搬到 Redis 之后跨进程可见，
     * 采集/订阅/补数三个进程各自的 kline_flush Timer 已随之删除，MySQL 写入统一收口到 flusher。
     * WS 推送不受这个节流影响：写入侧拿 stageKline() 返回的合并结果直接推送，
     * 跟落库时机解耦，落库延迟不会传导成推送延迟。
     *
     * @param int $limit 单轮每个 tag 最多写多少行，0 = 不限。见 RedisDirtySet::swapOut()
     */
    public static function flushKlineBuffers(int $limit = 0): void
    {
        self::flushKlineBuffer(
            'src',
            'market_source_kline',
            ['source_key','period','ts','open','close','low','high','amount','vol','exchange','symbol','type','create_ts'],
            '`open`=VALUES(`open`), `close`=VALUES(`close`), `low`=VALUES(`low`), `high`=VALUES(`high`), `amount`=VALUES(`amount`), `vol`=VALUES(`vol`)',
            fn($r) => [(int)$r['ts'], (int)$r['period'], $r['source_key']],
            fn($r) => $r['source_key'] . ':' . $r['period'] . ':' . $r['ts'],
            $limit
        );

        self::flushKlineBuffer(
            'pair',
            'exchange_pair_kline',
            ['pair_id','period','ts','open','high','low','close','vol','amount'],
            '`high`=GREATEST(`high`,VALUES(`high`)), `low`=LEAST(`low`,VALUES(`low`)), `close`=VALUES(`close`), `vol`=VALUES(`vol`), `amount`=VALUES(`amount`)',
            fn($r) => [(int)$r['ts'], (int)$r['period'], (int)$r['pair_id']],
            fn($r) => $r['pair_id'] . ':' . $r['period'] . ':' . $r['ts'],
            $limit
        );
    }

    /** 两个 K 线缓冲的积压行数，供监控页/stat 定时器用 */
    public static function klinePending(): array
    {
        $redis = redis_client();
        return [
            'src'  => self::klinePendingOf($redis, 'src'),
            'pair' => self::klinePendingOf($redis, 'pair'),
        ];
    }

    /** 刷盘时因死锁被退回缓冲、等下一轮重试的行数，随日志输出。稳态应接近 0 */
    private static int $klineRequeued = 0;

    /**
     * 单个 tag 的缓冲上界（脏集合成员数）。退回重试不能无上界——MySQL 长时间抖动时
     * 缓冲会一直涨，与订阅方 pending 同构。超上界就整块丢弃不再退回：那几根下游看到的是
     * **缺行**，缺行检测与 K 线断档回补都能发现；而无限增长会把 Redis 撑爆，
     * 那是全站共享的实例，代价远大于丢几根蜡烛。
     *
     * 搬到 Redis 之前这是进程内数组长度、撑爆的是采集进程自己的内存；现在爆的是 Redis，
     * 所以这个上界比过去更要紧，不能因为「Redis 内存大」就调高。
     */
    private const KLINE_BUFFER_MAX = 20000;

    /**
     * 取走 K 线落库统计并清零。由 MarketDbFlusher 的 stat 定时器调用
     * （2026-08-28 前是采集/订阅进程各自取，缓冲收口后跟着搬过去）。
     *
     * @return array{rounds:int,rows:int,chunks:int,ms:float,ms_max:float}
     */
    public static function takeKlineFlushStat(): array
    {
        $s = self::$klineFlushStat;
        self::$klineFlushStat = ['rounds' => 0, 'rows' => 0, 'chunks' => 0, 'ms' => 0.0, 'ms_max' => 0.0];
        return $s;
    }

    /**
     * 把一个 K 线缓冲 (Redis) 换出并批量写库，死锁时退回缓冲、下一轮再试
     *
     * ⚠️ 排序键必须 **ts 打头**。`INSERT ... ON DUPLICATE KEY UPDATE` 按行序逐个取索引锁，
     * 顺序不一致就是 1213 Deadlock；而争用全在 KEY(ts) 上——ts 是所有 source_key 共享的对齐值
     * （同一分钟的 60s 蜡烛，51 个源同一个 ts）。
     * 缓冲收口到 MarketDbFlusher 之后，本表的并发写者从 5 个（4 采集器 + 回补进程）降到 2 个
     * （flusher + backfill 的回补直写），排序仍然要留着：backfill 那侧是绕过缓冲直接写的。
     *
     * 死锁要**退回缓冲**而不是只记日志：一次死锁 = 那批 K 线直接丢，只能等回补事后补，
     * 而补数本身又是新的并发写、正好再制造死锁。按 chunk 各自 try，免得第一块失败后面几块一起丢。
     * 退回走 requeueKline()（合并语义写回 Redis），此刻缓冲里可能已经有更新的 tick，
     * 合并回去不会把新值顶掉。
     *
     * @param string   $tag      缓冲标识 src / pair
     * @param callable $sortKey  取排序元组，决定取锁顺序
     * @param callable $keyOf    从行算出缓冲行标识，退回时用
     * @param int      $limit    本轮最多取多少行，0 = 不限
     */
    private static function flushKlineBuffer(
        string $tag, string $table, array $cols, string $onDup,
        callable $sortKey, callable $keyOf, int $limit = 0
    ): void {
        $redis = redis_client();
        if (!$redis) return; // 没有 Redis 就没有缓冲可刷（写入侧此时走的是同步直写降级）

        $t0 = microtime(true);
        $rows = self::drainKlineBuffer($redis, $tag, $limit);
        if (empty($rows)) return;

        $rowCount = count($rows);
        $chunkCount = 0;

        // 排序：所有写者用同一个取锁顺序。backfill 那侧本来就是单个 (source_key, period)
        // 按 ts 递增写，与这里的元组序天然一致
        usort($rows, fn($a, $b) => $sortKey($a) <=> $sortKey($b));

        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $requeued = 0;
        $pending  = self::klinePendingOf($redis, $tag);

        foreach (array_chunk($rows, self::BATCH_CHUNK_SIZE) as $chunk) {
            $chunkCount++;
            $placeholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $placeholders[] = $ph;
                // 缺列按 null 绑定而不是让 PHP 抛 undefined key：Redis 里的 hash 理论上总是整行写入的，
                // 但热层曾出过残留/缺字段的事故（2026-08-26 bbo_bid），这里宁可让那一行被 MySQL 拒掉
                // 并走 batchUpdate 那套「记日志 + 跳过」，也不要整个刷盘轮次崩在一个数组下标上
                foreach ($cols as $c) $params[] = $row[$c] ?? null;
            }
            $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $cols) . "`)
                    VALUES " . implode(',', $placeholders) . "
                    ON DUPLICATE KEY UPDATE " . $onDup;
            try {
                Db::execute($sql, $params);
            } catch (\Throwable $e) {
                if (self::isDeadlock($e)) {
                    // 死锁是瞬时的：退回缓冲，下一轮自然重试。
                    // 不在这里 sleep 重试 —— 本方法跑在 Workerman 定时器里，睡就是停整个事件循环。
                    // 上界不能没有：MySQL 长时间抖动时退回会一直涨到 Redis 被撑爆，超了就丢这一块，
                    // 那几根下游看到的是**缺行**（缺行检测能发现），比把 Redis 写满强
                    if ($pending < self::KLINE_BUFFER_MAX) {
                        $n = self::requeueKline($tag, $chunk, $keyOf);
                        $requeued += $n;
                        $pending  += $n;
                    }
                    continue;
                }
                Log::error("flushKlineBuffers({$table}) Error: " . $e->getMessage());
            }
        }

        $ms = (microtime(true) - $t0) * 1000;
        self::$klineFlushStat['rounds']++;
        self::$klineFlushStat['rows']   += $rowCount;
        self::$klineFlushStat['chunks'] += $chunkCount;
        self::$klineFlushStat['ms']     += $ms;
        self::$klineFlushStat['ms_max']  = max(self::$klineFlushStat['ms_max'], $ms);

        if ($requeued > 0) {
            self::$klineRequeued += $requeued;
            business_log('market_kline_flush', sprintf(
                '[%s/%s] 死锁退回 %d 行等下一轮重试（累计 %d，缓冲 %d/%d）',
                $table, $tag, $requeued, self::$klineRequeued, $pending, self::KLINE_BUFFER_MAX
            ), 'warning');
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    // K 线落库缓冲（Redis）。2026-08-28 从三个进程各自的 static 数组搬过来：
    // 缓冲原先活在写它的那个 PHP 进程内存里、别的进程看不见，于是落库只能由写入进程自己的 Timer 做
    // —— 采集/订阅/补数三个进程各带一份刷盘定时器，MySQL 写入分散在三处，与「行情进程只碰内存和
    // Redis、落库统一收口到 MarketDbFlusher」的分工冲突，也让 exchange_pair_kline 有 5 个并发写者。
    // 搬到 Redis 后缓冲跨进程可见，三个 Timer 一并删除，落库由 MarketDbFlusher 独占。
    //
    // 结构与上面热层那套 (stageMarketData()/flushDirty()) 同构，脏集合共用 RedisDirtySet：
    //   行数据   market:kline:buf:{tag}:{key}  -> hash，每列一个 field
    //   脏集合   market:kline:dirty:{tag}      -> set，成员就是上面的 {key}
    // 三个 tag 的落库语义见 flushKlineBuffers()。
    //
    // ⚠️ 缓冲只负责「一轮刷盘之内」的合并，跨轮合并靠落库 SQL 的 ON DUPLICATE KEY UPDATE ——
    // drainKlineBuffer() 会把行取走并删除，下一个 tick 重新建 hash，那时 open 已经不在缓冲里了，
    // 靠 SQL 子句不含 open 保证它不被改写。这与搬迁前「刷盘时整体清空」的行为一致。
    // ───────────────────────────────────────────────────────────────────────

    /** 行数据 key 前缀，完整形态 market:kline:buf:{tag}:{key} */
    private const KLINE_BUF_PREFIX = 'market:kline:buf:';

    /** 脏集合 key 前缀，完整形态 market:kline:dirty:{tag} */
    private const KLINE_DIRTY_PREFIX = 'market:kline:dirty:';

    /**
     * 单次换出最多带多少个 key 进一个 MULTI。一个事务里的命令原子执行，
     * 太多会长时间占住单线程 Redis；取值与 BATCH_CHUNK_SIZE 同量级。
     */
    private const KLINE_DRAIN_CHUNK = 300;

    /** 脏集合 key。public：落库链路诊断页 (MarketPipeline) 要按同一命名规则读积压 */
    public static function klineDirtyKey(string $tag): string
    {
        return self::KLINE_DIRTY_PREFIX . $tag;
    }

    /** 单行 hash key */
    private static function klineBufKey(string $tag, string $key): string
    {
        return self::KLINE_BUF_PREFIX . $tag . ':' . $key;
    }

    /**
     * 把一行写进 K 线缓冲并标脏。
     *
     * @param string $tag   src / pair
     * @param string $key   行标识，pair 是 "{pair_id}:{period}:{ts}"，src 是 "{source_key}:{period}:{ts}"
     * @param array  $row   列 => 值
     * @param bool   $merge true 走 open/high/low 合并语义；false 纯覆盖 (src 用，交易所上报的
     *                      当前 K 线快照本就是最新累计值，最新的赢)
     * @return array|null   合并后的完整行 (供调用方直接推 WS，不必回读 DB)；失败返回 null，
     *                      调用方据此降级为同步直写 MySQL
     */
    private static function stageKline(string $tag, string $key, array $row, bool $merge): ?array
    {
        $redis = redis_client();
        if (!$redis || empty($row)) return null;

        try {
            $bufKey = self::klineBufKey($tag, $key);

            if ($merge) {
                // 合并语义与落库 SQL 的 ON DUPLICATE 子句对齐，也与搬迁前的 bufferPairKline() 逐字一致：
                // open 只认第一次进缓冲的值 (对应「只有 INSERT 分支会设置 open」)；high/low 取累计极值
                // (GREATEST/LEAST)；其余字段覆盖为最新值。
                //
                // ⚠️ 读-改-写不是原子的，理论上存在跨进程竞态：updatePrice() 在采集进程与补数进程
                // (MarketPriceFallback 的价格兜底) 都会调，两者同时写同一个 (pair,period,ts) 时后写的赢、
                // 丢一个极值。**刻意不为它上 Lua/WATCH**：
                //   1. 两者天然错开 —— 价格兜底只在 WS 静默时触发，那时采集进程恰恰没在推 tick；
                //   2. 后果有限 —— 丢的极值只影响图表上一根针的长度，下个 tick 就会重新推进；
                //   3. 有最终防线 —— 落库 SQL 的 GREATEST/LEAST 仍是精确 decimal 比较，
                //      已经进过库的极值不会被这里的竞态抹掉。
                // 拿一套 Lua 脚本 + sha 缓存 + NOSCRIPT 回落去换这点收益，维护成本不成比例。
                $old = $redis->hMGet($bufKey, ['open', 'high', 'low']);
                if (is_array($old) && isset($old['open']) && $old['open'] !== false) {
                    $row['open'] = $old['open'];
                    if (isset($old['high']) && $old['high'] !== false
                        && bccomp((string)$old['high'], (string)$row['high'], 10) > 0) {
                        $row['high'] = $old['high'];
                    }
                    if (isset($old['low']) && $old['low'] !== false
                        && bccomp((string)$old['low'], (string)$row['low'], 10) < 0) {
                        $row['low'] = $old['low'];
                    }
                }
            }

            // 转字符串再写：float 直接进 Redis 会被 PHP 按 precision 截断 —— 价格是 decimal，丢精度就是丢钱
            $store = [];
            foreach ($row as $f => $v) {
                $store[(string)$f] = is_float($v) ? rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') : (string)$v;
            }

            // 写行与标脏放进一个事务：只写了行没标脏 = 这一根永远不会被换出，静默丢失
            $redis->multi();
            $redis->hMSet($bufKey, $store);
            $redis->sAdd(self::klineDirtyKey($tag), $key);
            $redis->exec();

            // 返回 $row 而不是回读 Redis：合并结果已经在 $row 里，而且这份要直接拿去推 WS ——
            // 回读的话 pair_id / ts / period 会变成字符串 "12" / "1756…"，前端字段类型跟搬迁前不一样
            // (ts 尤其要紧，图表时间轴在用)
            return $row;
        } catch (\Throwable $e) {
            Log::error('K线缓冲写入失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 换出一批待落库的 K 线行。脏集合走 RedisDirtySet::swapOut() 的 SPOP 原子弹出 (同热层)，行数据再按 key 批量取走。
     *
     * @param int $limit 本轮最多取多少行，0 = 不限。语义同 RedisDirtySet::swapOut()
     * @return array<int,array> 每项是一行 (列 => 值)；顺序不保证，调用方需自行排序取锁
     */
    private static function drainKlineBuffer($redis, string $tag, int $limit = 0): array
    {
        if (!$redis) return [];

        $members = \app\lib\RedisDirtySet::swapOut($redis, self::klineDirtyKey($tag), $limit);
        if (empty($members)) return [];

        $rows = [];
        foreach (array_chunk($members, self::KLINE_DRAIN_CHUNK) as $chunk) {
            try {
                // ⚠️ 取和删必须在同一个事务里：分两批发的话，中间到达的新 tick 会被 DEL 抹掉 ——
                // 那一根蜡烛这一轮既没进库 (取的时候还没写) 也不在缓冲里了，静默丢失
                $redis->multi();
                foreach ($chunk as $m) $redis->hGetAll(self::klineBufKey($tag, $m));
                foreach ($chunk as $m) $redis->del(self::klineBufKey($tag, $m));
                $res = $redis->exec();
            } catch (\Throwable $e) {
                Log::error('K线缓冲换出失败: ' . $e->getMessage());
                continue;
            }
            if (!is_array($res)) continue;

            // exec() 按命令顺序返回结果，前 count($chunk) 条是 hGetAll 的
            for ($i = 0, $n = count($chunk); $i < $n; $i++) {
                if (!empty($res[$i]) && is_array($res[$i])) $rows[] = $res[$i];
            }
        }

        return $rows;
    }

    /**
     * 落库失败 (死锁) 的行写回缓冲，等下一轮重试。
     *
     * ⚠️ 统一走**合并**语义写回，即使是纯覆盖的 src：退回的是旧数据，此刻缓冲里可能已经有
     * 新 tick 写入的更新值，覆盖回去就是把新数据顶掉。合并语义下 high/low 取极值、open 保留已有，
     * 对 src 也没有副作用 —— 交易所上报的当前 K 线 high/low 本就单调，合并结果等同于取最新那份。
     *
     * @param callable $keyOf 从行算出行标识的闭包 (各表主键构成不同，由调用方给)
     * @return int 实际写回的行数
     */
    private static function requeueKline(string $tag, array $rows, callable $keyOf): int
    {
        if (empty($rows)) return 0;

        $n = 0;
        foreach ($rows as $row) {
            if (self::stageKline($tag, (string)$keyOf($row), $row, true) !== null) $n++;
        }
        return $n;
    }

    /** 某个 tag 的脏集合当前积压行数，供观测用；取不到返回 0 */
    private static function klinePendingOf($redis, string $tag): int
    {
        if (!$redis) return 0;
        try {
            return (int)$redis->sCard(self::klineDirtyKey($tag));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** 是不是 InnoDB 死锁 / 锁等待超时 —— 这两种都该重试，其它异常不该 */
    private static function isDeadlock(\Throwable $e): bool
    {
        $m = $e->getMessage();
        return str_contains($m, '40001')            // SQLSTATE 序列化失败
            || str_contains($m, '1213')             // Deadlock found
            || str_contains($m, '1205')             // Lock wait timeout
            || stripos($m, 'deadlock') !== false;
    }

    /** 单条多行INSERT最多携带的行数，避免SQL文本/参数数组无限增长 (实际补档批次通常远小于这个数) */
    private const BATCH_CHUNK_SIZE = 300;

    /**
     * K 线落库的每轮开销。窗口内累加，由调用方 takeKlineFlushStat() 取走并清零。
     *
     * 为什么必须有：MarketDbFlusher 的 busy 只有一个 flush 桶，market_data 与 K 线混在一起，
     * 既分不出 K 线占了多少，也分不出是**行多**还是**每行慢** —— 前者调 --interval / --batch 就能摊薄，
     * 后者是锁竞争或索引问题，调周期只会让每轮更重。
     * 没有这个数，优化就只能靠猜。
     */
    private static array $klineFlushStat = ['rounds' => 0, 'rows' => 0, 'chunks' => 0, 'ms' => 0.0, 'ms_max' => 0.0];

    /**
     * syncPairKlines() 的批量版：只供 REST 补档/断档重放场景调用 (isPadding 固定为 true，不做WS推送)，
     * 实时WS单tick路径继续走 syncPairKlines()，不受影响。把同一个pair这一批 sourceKline
     * (已按ts asc排序) 合并成一条 (或按 BATCH_CHUNK_SIZE 分块的几条) 多行INSERT写入exchange_pair_kline，
     * 取代原来"每根candle都单独INSERT一次"的循环，逐行的 prevClose 计算逻辑跟单条版完全一致，
     * 只是把SQL执行这一步从逐行推迟到整批算完后再一次性做。
     * @param array $pair 交易对信息
     * @param string $period 周期
     * @param array $sourceKlineRows 该pair这一批原始K线数据 (每项已含 ts)，需按 ts 递增排序
     */
    public static function batchSyncPairKlines(array $pair, string $period, array $sourceKlineRows): void
    {
        if (empty($sourceKlineRows)) return;

        $pairId = (int)$pair['id'];
        $periodMs = (int)$period * 1000;
        $closeCacheKey = $pairId . ':' . $period;

        // 批内滚动收盘价：种子取自进程共享缓存 (跟单条版取值逻辑一致)，随着批内逐行推进更新，
        // 只有整批落库成功后才回写共享缓存，避免"缓存以为成功、DB其实没写"的脏读
        $rollingClose = self::$lastCloseCache[$closeCacheKey] ?? null;
        $lastRowTs = null;
        $lastRowClose = null;

        $insertRows = [];
        foreach ($sourceKlineRows as $sourceKline) {
            $ts = $sourceKline['ts'];

            if ($rollingClose !== null && $rollingClose['ts'] === $ts - $periodMs) {
                $prevClose = $rollingClose['close'];
            } else {
                $prevClose = Db::name('exchange_pair_kline')
                    ->where('pair_id', $pairId)->where('period', $period)->where('ts', $ts - $periodMs)
                    ->value('close');
                $prevClose = $prevClose !== null ? (string)$prevClose : null;
            }
            $data = [
                'pair_id' => $pairId, 'period' => $period, 'ts' => $ts,
                'open'    => $prevClose ?? MarketHelper::safeNumStr($sourceKline['open']),
                'high'    => MarketHelper::safeNumStr($sourceKline['high']),
                'low'     => MarketHelper::safeNumStr($sourceKline['low']),
                'close'   => MarketHelper::safeNumStr($sourceKline['close']),
                'vol'     => $sourceKline['vol'],
                'amount'  => $sourceKline['amount'],
            ];
            if (bccomp((string)$data['open'], (string)$data['high'], 10) > 0) $data['high'] = $data['open'];
            if (bccomp((string)$data['open'], (string)$data['low'], 10) < 0) $data['low'] = $data['open'];

            $insertRows[] = $data;
            $rollingClose = ['ts' => $ts, 'close' => (string)$data['close']];
            $lastRowTs = $ts;
            $lastRowClose = $data['close'];
        }

        if (empty($insertRows)) return;

        try {
            Db::transaction(function () use ($insertRows) {
                foreach (array_chunk($insertRows, self::BATCH_CHUNK_SIZE) as $chunk) {
                    $placeholders = [];
                    $params = [];
                    foreach ($chunk as $row) {
                        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?)';
                        array_push(
                            $params,
                            $row['pair_id'], $row['period'], $row['ts'], $row['open'], $row['high'],
                            $row['low'], $row['close'], $row['vol'], $row['amount']
                        );
                    }
                    // 语义跟单条版完全一致：open 不放进 UPDATE 子句 (只有INSERT分支会设置)，
                    // high/low 用 GREATEST/LEAST 累计极值，见 syncPairKlines() 内的详细注释
                    $sql = "INSERT INTO `exchange_pair_kline`
                            (`pair_id`, `period`, `ts`, `open`, `high`, `low`, `close`, `vol`, `amount`)
                            VALUES " . implode(',', $placeholders) . "
                            ON DUPLICATE KEY UPDATE
                            `high`=GREATEST(`high`,VALUES(`high`)), `low`=LEAST(`low`,VALUES(`low`)), `close`=VALUES(`close`), `vol`=VALUES(`vol`), `amount`=VALUES(`amount`)";
                    Db::execute($sql, $params);
                }
            });

            // 没有WS推送：这个批量方法只服务补档/断档重放场景，等价于单条版syncPairKlines()
            // 里isPadding=true的分支——补的是历史K线，推给正在看盘的用户只会造成图表突然插入
            // 几十根"过去"K线的闪烁，不是漏写。

            if ($lastRowTs !== null) {
                self::$lastCloseCache[$closeCacheKey] = ['ts' => $lastRowTs, 'close' => (string)$lastRowClose];
            }
        } catch (\Throwable $e) {
            Log::error("batchSyncPairKlines Error: " . $e->getMessage());
        }
    }

    /**
     * updateSourceKline() 的批量版：只供 klineBackfillFix() 补档场景调用 (isPadding固定为true)，
     * 实时WS单tick路径继续走 updateSourceKline()，不受影响。把整批candle的 market_source_kline
     * 写入合并成一条(或分块的几条)多行INSERT，随后对该source_key下每个挂载的pair各调用一次
     * batchSyncPairKlines() 合并写 exchange_pair_kline，取代原来"每根candle都单独写两张表"
     * 的循环，逻辑(价格跳空对齐)跟单条版一致，只是SQL执行推迟到批量算完后一次性做。
     * @param array $candles REST拉回来的原始K线数组，需按ts递增排序 (调用方已保证)
     * @return int 实际写入的candle数量
     */
    public static function updateSourceKlineBatch(MarketExchangeEnum $exchange, int $type, string $symbol, string $period, array $candles): int
    {
        if (empty($candles)) return 0;

        $normalizedSymbol = str_replace(['-', '_', '/'], '', strtolower($symbol));
        $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);
        $now = time();

        $insertRows = [];
        $normalizedRows = []; // 喂给 batchSyncPairKlines() 的形状，跟单条路径的 $data+ts 一致
        foreach ($candles as $kline) {
            $ts = (int)($kline['id'] ?? 0) * 1000;
            if (!$ts) continue;

            $open  = (float)($kline['open'] ?? 0);
            $close = (float)($kline['close'] ?? 0);

            $rowData = [
                'open'   => $open,
                'close'  => $close,
                'low'    => $kline['low'] ?? 0,
                'high'   => $kline['high'] ?? 0,
                'amount' => $kline['amount'] ?? 0,
                'vol'    => $kline['vol'] ?? 0,
            ];

            $insertRows[] = array_merge($rowData, [
                'source_key' => $sourceKey, 'period' => $period, 'ts' => $ts,
                'exchange' => $exchange->value, 'symbol' => $normalizedSymbol, 'type' => $type, 'create_ts' => $now,
            ]);
            $normalizedRows[] = array_merge($rowData, ['ts' => $ts]);
        }

        if (empty($insertRows)) return 0;

        try {
            Db::transaction(function () use ($insertRows) {
                foreach (array_chunk($insertRows, self::BATCH_CHUNK_SIZE) as $chunk) {
                    $placeholders = [];
                    $params = [];
                    foreach ($chunk as $row) {
                        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                        array_push(
                            $params,
                            $row['source_key'], $row['period'], $row['ts'], $row['open'], $row['close'],
                            $row['low'], $row['high'], $row['amount'], $row['vol'],
                            $row['exchange'], $row['symbol'], $row['type'], $row['create_ts']
                        );
                    }
                    $sql = "INSERT INTO `market_source_kline`
                            (`source_key`, `period`, `ts`, `open`, `close`, `low`, `high`, `amount`, `vol`, `exchange`, `symbol`, `type`, `create_ts`)
                            VALUES " . implode(',', $placeholders) . "
                            ON DUPLICATE KEY UPDATE
                            `open`=VALUES(`open`), `close`=VALUES(`close`), `low`=VALUES(`low`), `high`=VALUES(`high`), `amount`=VALUES(`amount`), `vol`=VALUES(`vol`)";
                    Db::execute($sql, $params);
                }
            });
        } catch (\Throwable $e) {
            Log::error("updateSourceKlineBatch Error: " . $e->getMessage());
            return 0;
        }


        // 同步生成 pair 层 K 线，同一个source_key下每个pair各批量写一次
        $pairs = self::getPairsBySourceKey($sourceKey);
        foreach ($pairs as $pair) {
            self::batchSyncPairKlines($pair, $period, $normalizedRows);
        }

        // 补进来的是某个汇总周期的基础 K 线时，覆盖到的那几根汇总 K 线要重算（基础补齐了，汇总才算得出来）
        foreach (MarketHelper::klinePlan($exchange)['derived'] as $derived => $base) {
            if ($base !== (int)$period) continue;
            $tsList = array_column($normalizedRows, 'ts');
            self::rebuildDerivedKlines($exchange, $type, $symbol, $derived, min($tsList), max($tsList));
        }

        return count($insertRows);
    }

    /**
     * 更新成交历史数据 (Trades)
     * @param MarketExchangeEnum $exchange 交易所
     * @param int $type 行情类型 (1:现货, 2:合约)
     * @param string $symbol 符号
     * @param array $data 原始成交数据。两种形状都接受：交易所原始载荷（Binance 单笔对象 / Huobi 成交数组），
     *                    以及 snapshot 传输下 MarketPublisher::mergeTradeWindow() 产出的**滚动窗口列表**。
     * @return bool
     */
    public static function updateTrade(MarketExchangeEnum $exchange, int $type, string $symbol, array $data): bool
    {
        $normalizedSymbol = str_replace(['-', '_', '/'], '', strtolower($symbol));
        $redis = redis_client();
        if (!$redis) return false;

        $fmt = function($val) {
            if (strpos((string)$val, 'E') !== false || strpos((string)$val, 'e') !== false) {
                return rtrim(rtrim(sprintf('%.10f', (float)$val), '0'), '.');
            }
            return (string)$val;
        };

        $fmtId = function($val) {
            if (strpos((string)$val, 'E') !== false || strpos((string)$val, 'e') !== false) {
                return sprintf('%.0f', (float)$val);
            }
            return (string)$val;
        };

        $trades = [];
        if ($exchange === MarketExchangeEnum::HUOBI) {
            foreach ($data as $item) {
                if (!is_array($item)) continue;
                $trades[] = [
                    'id'        => $fmtId($item['tradeId'] ?? (int)(microtime(true) * 1000)),
                    'price'     => $fmt($item['price'] ?? 0),
                    'quantity'  => $fmt($item['amount'] ?? 0),
                    'direction' => (string)($item['direction'] ?? 'buy'),
                    'ts'        => (int)($item['ts'] ?? (int)(microtime(true) * 1000)),
                ];
            }
        } elseif ($exchange === MarketExchangeEnum::BINANCE) {
            // 载荷有两种形状，必须都认：
            //  - queue 传输：交易所原始的**单笔对象** {t,p,q,T,m}
            //  - snapshot 传输：MarketPublisher::mergeTradeWindow() 把 data 改写成的**滚动窗口列表** [{...},{...}]
            // 只按单笔对象读顶层 t/p/q 的话，列表上这些键都不存在，每帧会落一条
            // price=0/quantity=0、id 为 microtime 的幽灵成交，而窗口里的真实成交全被丢掉。
            // Huobi 分支本来就在遍历列表，所以只有这里需要归一。
            foreach (array_is_list($data) ? $data : [$data] as $item) {
                if (!is_array($item)) continue;
                $trades[] = [
                    'id'        => $fmtId($item['t'] ?? (int)(microtime(true) * 1000)),
                    'price'     => $fmt($item['p'] ?? 0),
                    'quantity'  => $fmt($item['q'] ?? 0),
                    'direction' => isset($item['m']) && $item['m'] ? 'sell' : 'buy',
                    'ts'        => (int)($item['T'] ?? (int)(microtime(true) * 1000)),
                ];
            }
        } elseif ($exchange === MarketExchangeEnum::BINGX) {
            // 采集器已归一成 {id,price,quantity,direction,ts}（BingX 在采集端做）；列表 / 单笔两种形状同 Binance
            foreach (array_is_list($data) ? $data : [$data] as $item) {
                if (!is_array($item) || !isset($item['price'])) continue;
                $trades[] = [
                    'id'        => $fmtId($item['id'] ?? (int)(microtime(true) * 1000)),
                    'price'     => $fmt($item['price']),
                    'quantity'  => $fmt($item['quantity'] ?? 0),
                    'direction' => (string)($item['direction'] ?? 'buy'),
                    'ts'        => (int)($item['ts'] ?? (int)(microtime(true) * 1000)),
                ];
            }
        }

        if (empty($trades)) return false;

        try {
            $sourceKey = MarketHelper::makeSourceKey($exchange, $symbol, $type);

            // 原始层成交快照 (按 source_key)，供后台原始行情成交页查看
            self::stageRawTrades($redis, $sourceKey, $trades);

            $pairRows = self::getPairsBySourceKey($sourceKey);

            foreach ($pairRows as $pair) {
                $pairId = (int)$pair['id'];
                $pairTrades = $trades;

                $key = "market:trade:pair_{$pairId}";
                $existingJson = $redis->get($key);
                $existing = $existingJson ? json_decode($existingJson, true, 512, JSON_BIGINT_AS_STRING) : [];
                if (!is_array($existing)) $existing = [];

                // 窗口传输下每帧都带全窗（默认 30~100 笔）且与上一帧大面积重叠，去重从"偶尔命中"
                // 变成"绝大多数命中"：用 id 做哈希键而不是 in_array 线性扫。
                // 同时把本轮新插入的 id 也记进去 —— 否则同一帧内的重复 id 会被插两次。
                $existingIds = array_flip(array_map('strval', array_column($existing, 'id')));
                $newTrades = [];
                foreach ($pairTrades as $t) {
                    $tid = (string)$t['id'];
                    if (isset($existingIds[$tid])) continue;
                    array_unshift($existing, $t);
                    $existingIds[$tid] = true;
                    $newTrades[] = $t;
                }

                // 保留笔数与分发侧的窗口容量共用同一个配置项。
                // 这里原来硬编码 30，而分发侧是可配的 market.dispatch.trade_window ——
                // 两个必须一致的数字只有一个能改，于是"调大 trade_window"会**静默不生效**：
                // 多发过来的成交在这一行被切掉，看起来像没生效，实际是被下游丢了。
                $keep = max(1, (int)Config::get('market.dispatch.trade_window', 30));
                $existing = array_slice($existing, 0, $keep);
                $redis->set($key, json_encode($existing));

                if (!empty($newTrades)) {
                    // 发整窗（订阅方按 id 去重，覆盖语义下重复投递幂等）
                    MarketPublisher::publishTrade($pair, $existing, count($newTrades));
                }
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("updateTrade Error: " . $e->getMessage());
            return false;
        }
    }

    /** 单批最多删多少行，避免一次DELETE锁太久 */
    private const KLINE_CLEAN_BATCH_SIZE = 5000;

    /**
     * K线定期清理：market_source_kline，按period算好时间线一刀切，删掉更早的
     * @return int 实际删除的总行数
     */
    public static function cleanSourceKline(): int
    {
        return self::cleanKlineByCutoff('market_source_kline', 'source');
    }

    /**
     * K线定期清理：exchange_pair_kline，按period算好时间线一刀切，删掉更早的
     * (App端图表产品数据，2026-08-14 确认改为清理，早年历史不再保留)
     * @return int 实际删除的总行数
     */
    public static function cleanPairKline(): int
    {
        return self::cleanKlineByCutoff('exchange_pair_kline', 'pair');
    }

    /**
     * 通用实现：按 period 分组(只有个位数~十几种周期，不是 symbol×period 那种几百组的量级)，
     * 每种 period 各取自己的保留量算出时间线 = now - period × keep，直接删掉 ts 早于这条线的
     * 所有行——不需要逐组去查"第 N 新是哪一条"，纯算术，也就不会再踩 Db::value() 强制 LIMIT 1 的坑。
     *
     * 语义上是"保留最近 keep 个周期的时间窗口"而不是"精确保留 keep 行"：中间有断档的 symbol
     * 不会因此多留一段更早的历史，更符合清理的初衷。
     *
     * @param string $layer 传给 MarketHelper::klineKeep()，决定读哪一层的保留量配置
     */
    private static function cleanKlineByCutoff(string $table, string $layer): int
    {
        $periods = Db::name($table)->field('period')->distinct(true)->column('period');
        $nowMs = time() * 1000;
        $total = 0;

        foreach ($periods as $period) {
            $periodMs = (int)$period * 1000;
            if ($periodMs <= 0) continue;
            // 保留量按周期取：同样根数在 1min 和 1day 上覆盖的时长差几百倍，
            // 一个统一数要么让分钟线只剩一天多，要么让日线存到十年前
            $cutoffTs = $nowMs - $periodMs * MarketHelper::klineKeep($layer, $period);

            do {
                $ids = Db::name($table)->where('period', $period)
                    ->where('ts', '<', $cutoffTs)
                    ->limit(self::KLINE_CLEAN_BATCH_SIZE)
                    ->column('id');
                if (empty($ids)) break;
                $total += Db::name($table)->where('id', 'in', $ids)->delete();
            } while (count($ids) >= self::KLINE_CLEAN_BATCH_SIZE);
        }

        return $total;
    }
}
