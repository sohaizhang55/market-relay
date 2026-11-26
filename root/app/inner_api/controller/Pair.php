<?php

declare(strict_types=1);

namespace app\inner_api\controller;

use think\facade\Db;
use think\admin\Controller;
use app\enum\MarketDataTypeEnum;
use app\lib\MarketHelper;
use app\lib\MarketPublisher;

/**
 * pair 内部接口（分发协议 v3），供订阅方补数与对时使用（鉴权见 app\inner_api\middleware\InnerAuth）
 *
 * 与 WS 分发同一个口径：
 *   · 一律按 pair（{symbol_code}_{type}，见 MarketPublisher::pairKey()）定位与返回，
 *     不暴露 source_key / 交易所 —— 订阅方不关心数据来自哪家交易所
 *   · 读的都是本机的 **pair 层**（exchange_pair / exchange_pair_kline / market:depth:pair_{id}），
 *     与 WS 帧同源。两条通道必须读同一层，否则订阅方收到的数据口径不一致
 *   · 不返回 pair_id：它是本机 exchange_pair 的自增主键，跨机会静默错配
 */
class Pair extends Controller
{
    /**
     * pair 元数据全集，订阅方据此核对本机 exchange_pair、判断开休市
     * GET /inner_api/pair/pairs
     *
     * calendar_code 取自该 pair 当前数据源的 market_data.calendar_code：
     * 订阅方不再持有 market_data，开休市判断只能靠这一列（空串 = 24/7）。
     * 包含 status=禁用 的 pair：要不要用由订阅方自己决定，A 只如实报告。
     */
    public function pairs()
    {
        $rows = Db::name('exchange_pair')->alias('p')
            ->leftJoin('market_data m', 'm.source_key = p.source_key')
            ->field('p.symbol, p.symbol_code, p.type, p.base_coin, p.quote_coin, p.precision_price, p.precision_amount, p.status, p.sort, m.calendar_code')
            ->order('p.type asc, p.sort desc, p.id asc')
            ->select()
            ->toArray();

        $data = array_map(fn($r) => [
            'pair'             => MarketPublisher::pairKey((string)$r['symbol_code'], (int)$r['type']),
            'symbol'           => (string)$r['symbol'],
            'symbol_code'      => (string)$r['symbol_code'],
            'type'             => (int)$r['type'],
            'base_coin'        => (string)$r['base_coin'],
            'quote_coin'       => (string)$r['quote_coin'],
            'precision_price'  => (int)$r['precision_price'],
            'precision_amount' => (int)$r['precision_amount'],
            'status'           => (int)$r['status'],
            'sort'             => (int)$r['sort'],
            // 没有数据源（自发币）或源没配日历时都是 ''，与 market_data 里「空串 = 24/7」同义
            'calendar_code'    => (string)($r['calendar_code'] ?? ''),
        ], $rows);

        return json(['code' => 1, 'msg' => 'success', 'data' => $data]);
    }

    /**
     * 全部 pair 的当前价格与标记价格，供订阅方在 WS 静默时自救
     * GET /inner_api/pair/prices
     *
     * ⚠️ 读的是 exchange_pair，即**刷盘之后**的副本，落后一个 db_flush 周期（默认 1 秒）。
     * market_ts / mark_ts 原样返回、不替换成当前时刻：订阅方要拿它跟本机比新旧决定写不写，
     * 换成 now 会让「A 其实也断了」伪装成一份新鲜数据。
     */
    public function prices()
    {
        // market_ts > 0 才返回：从未更新过的 pair 没有参考价值，返回反而可能把订阅方的值覆盖成 0
        $rows = Db::name('exchange_pair')
            ->where('market_ts', '>', 0)
            ->field('symbol_code, type, last_price, open, high, low, close, amount, vol, `change`, market_ts, mark_price, mark_ts')
            ->select()
            ->toArray();

        foreach ($rows as &$r) {
            $r = ['pair' => MarketPublisher::pairKey((string)$r['symbol_code'], (int)$r['type'])] + $r;
        }
        unset($r);

        return json(['code' => 1, 'msg' => 'success', 'data' => $rows]);
    }

    /**
     * 全部合约 pair 的当前资金费率，是订阅方费率的唯一供数通道（费率不走 WS）
     * GET /inner_api/pair/fundingRates
     *
     * exchange_next_funding_ts 取自数据源的 market_data（交易所自报的下次结算时刻，仅供参考）。
     */
    public function fundingRates()
    {
        // funding_rate_ts > 0 才返回，理由同 prices()
        $rows = Db::name('exchange_pair')->alias('p')
            ->leftJoin('market_data m', 'm.source_key = p.source_key')
            ->where('p.type', MarketDataTypeEnum::SWAP->value)
            ->where('p.funding_rate_ts', '>', 0)
            ->field('p.symbol_code, p.type, p.funding_rate, p.estimated_funding_rate, p.funding_rate_ts, m.exchange_next_funding_ts')
            ->select()
            ->toArray();

        foreach ($rows as &$r) {
            $r['exchange_next_funding_ts'] = (int)($r['exchange_next_funding_ts'] ?? 0);
            $r = ['pair' => MarketPublisher::pairKey((string)$r['symbol_code'], (int)$r['type'])] + $r;
        }
        unset($r);

        return json(['code' => 1, 'msg' => 'success', 'data' => $rows]);
    }

    /**
     * 单个 pair 的当前盘口，供订阅方在 WS 静默时自救
     * GET /inner_api/pair/depth?pair=btcusdt_2
     *
     * 一次一个 pair：深度载荷比价格大一个量级，调用方按「本机哪几路过期了」按需要。
     */
    public function depth()
    {
        $pair = $this->resolvePair((string)$this->request->get('pair', ''));
        if ($pair === null) {
            return json(['code' => 0, 'msg' => 'unknown pair']);
        }

        $json = redis_client()?->get("market:depth:pair_{$pair['id']}");
        $snap = $json ? json_decode((string)$json, true) : null;

        // A 自己就没有这一路的深度快照时回 code=0 而不是回一份空盘口：
        // 调用方拿空数组覆盖本机深度，做市商会把「深度缺失」当成「深度过期」撤单，等于帮倒忙
        if (!is_array($snap) || (empty($snap['bids']) && empty($snap['asks']))) {
            return json(['code' => 0, 'msg' => 'no depth snapshot on server A']);
        }

        return json(['code' => 1, 'msg' => 'success', 'data' => [
            'bids' => $snap['bids'] ?? [],
            'asks' => $snap['asks'] ?? [],
            'ts'   => (int)($snap['ts'] ?? 0),
        ]]);
    }

    /**
     * 单个 pair 的 K 线历史，供订阅方断档回补与新币补历史
     * GET /inner_api/pair/klines?pair=btcusdt_2&period=60&since_ts=1792...&to_ts=0&limit=500
     *   since_ts 开区间下界、to_ts 闭区间上界（0 = 不限），单位都是毫秒；
     *   返回升序，每行 {ts(毫秒,K线起始时刻), open, high, low, close, vol, amount}，与 WS kline 帧同形
     *
     * 读 exchange_pair_kline。更早的历史由 market:kline_backfill 从源层回放补齐（replayPairKlineGap），
     * 新上架的 pair 刚开始时这里的历史可能比源层短。
     */
    public function klines()
    {
        $pair    = $this->resolvePair((string)$this->request->get('pair', ''));
        $period  = (string)$this->request->get('period', '');
        $sinceTs = (int)$this->request->get('since_ts', 0);
        $toTs    = (int)$this->request->get('to_ts', 0);
        // 上限跟该周期的保留量走：补历史时一次就能把序列拉满。读的是本地表，放大 LIMIT 只是多扫几行索引
        $maxLimit = max(500, MarketHelper::klineKeep('pair', $period));
        $limit    = min($maxLimit, max(1, (int)$this->request->get('limit', 500)));

        if ($pair === null || $period === '') {
            return json(['code' => 0, 'msg' => 'pair/period required']);
        }

        $rows = Db::name('exchange_pair_kline')
            ->where('pair_id', $pair['id'])
            ->where('period', $period)
            ->where('ts', '>', $sinceTs)
            ->when($toTs > 0, fn($q) => $q->where('ts', '<=', $toTs))
            ->order('ts asc')
            ->limit($limit)
            ->field('ts, open, high, low, close, vol, amount')
            ->select()
            ->toArray();

        foreach ($rows as &$r) {
            $r['ts'] = (int)$r['ts'];
        }
        unset($r);

        return json(['code' => 1, 'msg' => 'success', 'data' => $rows]);
    }

    /**
     * 把 pair（{symbol_code}_{type}）解析成本机 exchange_pair 行。拆不开或本机没有返回 null。
     * 从最后一个下划线切：type 恒为纯数字
     * @return array{id:int, symbol_code:string, type:int}|null
     */
    private function resolvePair(string $pair): ?array
    {
        $pos = strrpos($pair, '_');
        if ($pos === false || $pos === 0) return null;
        $symbol = substr($pair, 0, $pos);
        $type   = substr($pair, $pos + 1);
        if (!ctype_digit($type)) return null;

        $row = Db::name('exchange_pair')
            ->where('symbol_code', strtolower($symbol))
            ->where('type', (int)$type)
            ->field('id, symbol_code, type')
            ->find();

        return $row ? ['id' => (int)$row['id'], 'symbol_code' => (string)$row['symbol_code'], 'type' => (int)$row['type']] : null;
    }
}
