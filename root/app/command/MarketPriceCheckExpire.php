<?php

declare(strict_types=1);

namespace app\command;

use think\admin\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\business\service\AdminNoticeService;
use app\lib\TelegramService;
use app\service\MarketSessionService;
use app\enum\MarketTypeEnum;

/**
 * 行情价格过期健康检查
 * 扫描全部交易对的 market_ts（成交价），以及全部永续合约的 mark_ts（标记价格），过期时通知管理员；恢复后发送恢复通知。
 * 两者分开判断：成交价仍在更新而官方 markPrice 采集通道断开时，last_price 不会过期但 mark_price 会过期，
 * 而下游的合约风控依赖的是 mark_price，因此必须独立监控，不能只看成交价。
 * 通过 xadmin:task 每 60 秒调度一次，对引擎零侵入。
 *
 * Redis 双 Key 设计（成交价、标记价格分别各一组）：
 *   - REDIS_KEY_ALERTED*  TTL=60s：已发送告警的 ID 集合，过期后下一轮强制重推（防漏报）
 *   - REDIS_KEY_STALE*    无 TTL ：当前已知过期 ID 集合，用于检测恢复（不能随告警 key 一起过期）
 */
class MarketPriceCheckExpire extends Command
{
    use \app\lib\AlertFanoutTrait;

    private const REDIS_KEY_ALERTED = 'price_health:alerted_pids'; // TTL=60s，成交价
    private const REDIS_KEY_STALE   = 'price_health:stale_pids';   // 无过期，成交价
    private const REDIS_KEY_ALERTED_MARK = 'price_health:alerted_mark_pids'; // TTL=60s，标记价格
    private const REDIS_KEY_STALE_MARK   = 'price_health:stale_mark_pids';   // 无过期，标记价格
    private const ALERT_TTL = 60;

    protected function configure()
    {
        $this->setName('market:price_check_expire')
            ->addOption('force', null, \think\console\input\Option::VALUE_NONE, 'Delete Redis state keys before running to force re-notify all stale pairs')
            ->setDescription('Scan all trading pairs for stale prices (last_price & mark_price) and notify admins');
    }

    protected function execute(Input $input, Output $output)
    {
        $siteName = self::siteName();
        $redis = redis_client();
        $nowMs = (int)(microtime(true) * 1000);

        // --force：清除所有差量状态，强制重推
        if ($input->getOption('force') && $redis) {
            $redis->del(self::REDIS_KEY_ALERTED);
            $redis->del(self::REDIS_KEY_STALE);
            $redis->del(self::REDIS_KEY_ALERTED_MARK);
            $redis->del(self::REDIS_KEY_STALE_MARK);
            $output->writeln("[FORCE] Redis state cleared, will re-notify all stale pairs.");
        }

        // 扫 pair 层：分发出去的是它，订阅方看到的新不新鲜也是它。阈值 stale_seconds
        $pairs   = $this->pairRows();
        $staleMs = (int)\think\facade\Config::get('market.stale_seconds', 300) * 1000;
        $priceStale = fn(array $p) => ($nowMs - (int)$p['market_ts']) > $staleMs;
        $markStale  = fn(array $p) => ($nowMs - (int)$p['mark_ts']) > $staleMs;
        // 休市中的源整行滤掉：价格不更新是**计划内**的，报 stale 等于把休市当故障 ——
        // 这正是休市模块要解决的第一个问题（market_calendar_overview.md §4.12）。
        //
        // 走 tradable*（看后台总开关）而不是 isOpen*：开关关掉要精确回到「没有休市功能」的状态，
        // 包括告警。理由见 MarketSessionService 里那段入口说明。
        //
        // 在这里滤掉而不是只跳过告警：留在 $pairs 里它只是从 stalePairs 消失，而 diffAndNotify
        // 是按「上轮 stale 集合 - 本轮 stale 集合」算恢复的，于是休市第一轮就会误发一条「已恢复」。
        // 整行滤掉后 $pairMap 里也没有它，恢复分支的 isset($pairMap[$rid]) 会自然挡住这条误报；
        // 开市后它重新进入扫描，真坏了照常告警（ALERT_TTL 只有 60s，不会漏推）。
        $scanned = count($pairs);
        $pairs   = array_values(array_filter(
            $pairs,
            fn(array $p) => MarketSessionService::tradableBySourceKey((string)($p['source_key'] ?? ''))
        ));
        $inSession = $scanned - count($pairs);
        if ($inSession > 0) {
            $output->writeln("[SESSION] {$inSession} 个源处于休市，已排除在过期扫描之外");
        }

        $pairMap = array_column($pairs, null, 'id');

        // 1. 成交价格 (last_price) 过期扫描 —— 全部交易对
        $stalePairs = [];
        foreach ($pairs as $pair) {
            if ($priceStale($pair)) {
                $stalePairs[$pair['id']] = $this->buildStaleInfo($pair, $nowMs, (int)$pair['market_ts']);
            }
        }

        // 2. 标记价格 (mark_price) 过期扫描 —— 仅永续合约有该数据，现货不采集
        $staleMarkPairs = [];
        foreach ($pairs as $pair) {
            if ((int)$pair['type'] !== MarketTypeEnum::CONTRACT->value) continue;
            if ($markStale($pair)) {
                $staleMarkPairs[$pair['id']] = $this->buildStaleInfo($pair, $nowMs, (int)$pair['mark_ts']);
            }
        }

        $changed1 = $this->diffAndNotify(
            $output, $redis, $siteName, $pairMap,
            $stalePairs, self::REDIS_KEY_ALERTED, self::REDIS_KEY_STALE,
            '成交价', '价格停更', '价格已恢复'
        );

        $changed2 = $this->diffAndNotify(
            $output, $redis, $siteName, $pairMap,
            $staleMarkPairs, self::REDIS_KEY_ALERTED_MARK, self::REDIS_KEY_STALE_MARK,
            '标记价格', '标记价格停更', '标记价格已恢复'
        );

        if (!$changed1 && !$changed2) {
            $staleCount = count($stalePairs) + count($staleMarkPairs);
            $suffix     = $staleCount > 0 ? "（{$staleCount} 项持续过期，60s 后将重推告警）" : '';
            $output->writeln("[OK] 全部价格正常{$suffix}");
        }
    }

    /**
     * 扫描对象：exchange_pair 每行一个交易对，source_key 是当前在用的源。
     * 时间戳优先取热层：MarketService 写的是热层，MySQL 只是刷盘后的副本
     */
    private function pairRows(): array
    {
        $rows = Db::name('exchange_pair')->field('id, symbol_code, type, market_ts, mark_ts, source_key')->select()->toArray();
        foreach ($rows as &$r) {
            // 分开读：getHotPairData 要求字段全部命中，现货热层里没有 mark_ts
            $hot     = \app\business\service\MarketService::getHotPairData((int)$r['id'], ['market_ts']);
            $hotMark = (int)$r['type'] === MarketTypeEnum::CONTRACT->value
                ? \app\business\service\MarketService::getHotPairData((int)$r['id'], ['mark_ts']) : null;
            $r['market_ts'] = (int)($hot['market_ts'] ?? $r['market_ts']);
            $r['mark_ts']   = (int)($hotMark['mark_ts'] ?? $r['mark_ts']);
        }
        unset($r);
        return $rows;
    }

    /**
     * @param array $pair 交易对行 (id, symbol_code, type, source_key ...)
     * @param int $nowMs 当前毫秒时间戳
     * @param int $ts 用于计算过期时长的原始时间戳字段 (market_ts 或 mark_ts)
     */
    private function buildStaleInfo(array $pair, int $nowMs, int $ts): array
    {
        return [
            // source_key 已经拼进 label 里了，不再单独带一份出去
            'label'     => $this->pairLabel($pair),
            'expired_s' => (int)(($nowMs - $ts) / 1000),
        ];
    }

    /**
     * 通知里指认一个交易对的统一写法：`BTCUSDT(永续合约) [HUOBI_btcusdt_2]`。
     *
     * 三段缺一不可 —— symbol 说是哪个币，type 说是现货还是合约（同一个 symbol 两边都有），
     * source_key 说是**哪个交易所的哪一路**。少了最后一段，同一交易对配了多个数据源时
     * 就分不清报的是哪一条链路。
     */
    private function pairLabel(array $pair): string
    {
        $typeEnum = MarketTypeEnum::tryFrom((int)$pair['type']);
        $typeStr  = $typeEnum ? $typeEnum->labelText() : '未知';
        $label    = strtoupper((string)$pair['symbol_code']) . "({$typeStr})";
        $sk       = (string)($pair['source_key'] ?? '');
        return $sk === '' ? $label : $label . " [{$sk}]";
    }

    /**
     * 对比本轮/上轮过期集合，发送告警与恢复通知，并更新 Redis 状态
     * @return bool 本轮是否发出了告警或恢复通知
     */
    private function diffAndNotify(
        Output $output, $redis, string $siteName, array $pairMap,
        array $staleMap, string $alertedKey, string $staleKey,
        string $priceLabel, string $alertTitle, string $recoverTitle
    ): bool {
        $prevAlertedIds = $this->redisGetIds($redis, $alertedKey); // TTL key，可能已过期
        $prevStaleIds   = $this->redisGetIds($redis, $staleKey);   // 持久 key

        $currentStaleIds = array_keys($staleMap);

        // 需要告警：当前过期 且 不在本轮已告警集合内（alerted key 过期后 prevAlertedIds 为空，全部重推）
        $newStaleIds  = array_values(array_diff($currentStaleIds, $prevAlertedIds));
        // 已恢复：上轮记录为过期，本轮正常
        $recoveredIds = array_values(array_diff($prevStaleIds, $currentStaleIds));

        $notified = false;

        if (!empty($newStaleIds)) {
            $lines = array_map(function ($id) use ($staleMap) {
                $info = $staleMap[$id];
                return $info['label'] . ' ' . $this->formatDuration($info['expired_s']);
            }, $newStaleIds);

            $content = "{$priceLabel}未更新：\n" . implode("\n", $lines);
            $this->fanout($alertTitle, $content, 'error', "[{$siteName}] ⚠️ *{$alertTitle}*\n{$content}");
            business_log('price_health', "[STALE][{$priceLabel}] {$content}", 'warning');
            $output->writeln("[ALERT] {$content}");
            $notified = true;
        }

        if (!empty($recoveredIds)) {
            $lines = [];
            foreach ($recoveredIds as $rid) {
                if (!isset($pairMap[$rid])) continue;
                // 恢复通知原来只写 symbol+type，**没带 source_key** —— 于是同一个交易对
                // 在两个交易所都有行情时，收到「已恢复」也不知道恢复的是哪一路。
                // 告警那一支一直是带的，两边口径统一到 buildStaleInfo()
                $lines[] = $this->pairLabel($pairMap[$rid]);
            }
            if (!empty($lines)) {
                $content = "{$priceLabel}已恢复：\n" . implode("\n", $lines);
                $this->fanout($recoverTitle, $content, 'info', "[{$siteName}] ✅ *{$recoverTitle}*\n{$content}");
                business_log('price_health', "[RECOVERED][{$priceLabel}] {$content}", 'info');
                $output->writeln("[RECOVER] {$content}");
                $notified = true;
            }
        }

        if ($redis) {
            // alerted key：TTL=60s，过期后下一轮视所有过期 pair 为"新增"，强制重推
            $redis->setex($alertedKey, self::ALERT_TTL, json_encode($currentStaleIds));
            // stale key：无 TTL，仅用于恢复检测
            $redis->set($staleKey, json_encode($currentStaleIds));
        }

        return $notified;
    }

    private function redisGetIds($redis, string $key): array
    {
        if (!$redis) return [];
        $json    = $redis->get($key);
        $decoded = $json ? json_decode($json, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) return "{$seconds}秒";
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($m < 60) return $s > 0 ? "{$m}分{$s}秒" : "{$m}分";
        $h = intdiv($m, 60);
        $m = $m % 60;
        return $m > 0 ? "{$h}小时{$m}分" : "{$h}小时";
    }

}
