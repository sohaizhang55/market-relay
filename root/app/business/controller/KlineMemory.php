<?php

declare(strict_types=1);

namespace app\business\controller;

use app\lib\KlineFetchMemory;
use app\lib\MarketHelper;
use think\admin\Controller;
use think\admin\service\AdminService;

/**
 * K 线补数记录
 *
 * market:kline_backfill 向交易所要过、交易所确实没给的段（KlineFetchMemory，Redis，7 天过期），
 * 记下后不再去问。记错了图表会一直缺一块，所以要能看、能删。
 *
 * 删除一条 = 让补数进程重新确认一次。market:kline_backfill 进程内缓存 5 分钟重读一次，所以最多 5 分钟生效。
 * @class KlineMemory
 * @package app\business\controller
 */
class KlineMemory extends Controller
{
    /**
     * K 线补数记录
     * @auth true
     * @menu true
     */
    public function index()
    {
        if ($this->isLayTableRequest()) {
            $rows = $this->filterRows(KlineFetchMemory::gapList());
            usort($rows, fn($a, $b) => $b['marked_ts'] <=> $a['marked_ts']);

            $limit = max(1, (int)$this->request->get('limit', 20));
            $page  = max(1, (int)$this->request->get('page', 1));
            $data  = array_slice($rows, ($page - 1) * $limit, $limit);
            $this->mapRows($data);
            return json(['code' => 0, 'msg' => '', 'count' => count($rows), 'data' => $data]);
        }

        $this->title   = 'K 线补数记录';
        $this->periods = self::periodLabels();
        return $this->fetch();
    }

    /**
     * 删除一条记录，让补数进程重新确认
     * @auth true
     */
    public function remove()
    {
        $sourceKey = trim((string)$this->request->post('source_key'));
        $period    = trim((string)$this->request->post('period'));
        $startTs   = (int)$this->request->post('start_ts');
        if ($sourceKey === '' || $period === '' || $startTs <= 0) $this->error('参数错误！');

        if (!KlineFetchMemory::forgetGap($sourceKey, $period, $startTs)) $this->error('记录不存在或已过期！');

        business_log('market_kline_backfill', sprintf('[%s][%s] 后台删除「交易所确认没有」记录，起点 %s（%s）',
            $sourceKey, $period, date('Y-m-d H:i', intdiv($startTs, 1000)), AdminService::getUserName()), 'warning');
        $this->success('已删除，market:kline_backfill 最多 5 分钟内会重新去问交易所！');
    }

    /** 按搜索条件过滤：内部编码模糊匹配（不分大小写）、周期精确匹配 */
    private function filterRows(array $rows): array
    {
        $sourceKey = strtolower(trim((string)$this->request->get('source_key', '')));
        $period    = trim((string)$this->request->get('period', ''));
        return array_values(array_filter($rows, fn($r) =>
            ($sourceKey === '' || str_contains(strtolower($r['source_key']), $sourceKey))
            && ($period === '' || $r['period'] === $period)));
    }

    /** 列表映射：时间、周期文字、根数 */
    private function mapRows(array &$data): void
    {
        $periods = self::periodLabels();
        foreach ($data as &$vo) {
            $vo['period_text'] = $periods[$vo['period']] ?? $vo['period'] . 's';
            $vo['start_text']  = date('Y-m-d H:i', intdiv($vo['start_ts'], 1000));
            $vo['end_text']    = date('Y-m-d H:i', intdiv($vo['end_ts'], 1000));
            $vo['candles']     = intdiv($vo['end_ts'] - $vo['start_ts'], max(1, (int)$vo['period']) * 1000) + 1;
            $vo['marked_text'] = date('Y-m-d H:i:s', $vo['marked_ts']);
            $vo['expire_text'] = date('Y-m-d H:i:s', $vo['marked_ts'] + KlineFetchMemory::TTL);
        }
        unset($vo);
    }

    /** @return array<string,string> 周期(秒) => 文字 */
    private static function periodLabels(): array
    {
        $labels = [60 => '1分钟', 300 => '5分钟', 900 => '15分钟', 3600 => '1小时', 14400 => '4小时', 86400 => '1天'];
        $out = [];
        foreach (MarketHelper::KLINE_PERIODS as $p) $out[(string)$p] = $labels[$p] ?? $p . 's';
        return $out;
    }

    /** 是否 layTable 组件发起的数据请求（写法同 Market::isLayTableRequest()） */
    private function isLayTableRequest(): bool
    {
        $output = strtolower($this->request->method() . '.' . $this->request->request('output', 'default'));
        return $output === 'get.layui.table';
    }
}
