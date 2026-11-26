<?php

use think\migration\Migrator;

/**
 * 资金费率采集迁入行情模块
 *
 * 费率是**行情数据**，与 last_price / mark_price 同级，因此走和它们完全相同的两段式落点：
 *   market_data (原始行情层，按 source_key)  ->  exchange_pair (pair 行情层，按 pair_id)
 *
 * 原先费率存在 exchange_contract 上是错位的：那张表装的是合约玩法配置 (费率档位、杠杆、
 * 最小下单量) 与结算状态，不是行情。挪到 exchange_pair 之后，MarketService::updateFundingRate()
 * 得以复用 stagePairData() 热层，与 updateMarkPrice() 结构完全对称。
 *
 * 时间戳单位统一为**毫秒**，对齐两张表各自的既有兄弟列：
 *   - market_data   的 update_ts / mark_ts / bbo_ts 全是毫秒 bigint
 *   - exchange_pair 的 market_ts / mark_ts        全是毫秒 bigint
 * 因此全链路无需任何单位换算 (exchange_contract.funding_rate_ts 那个秒级列随本迁移一并删除)。
 *
 * exchange_contract.next_funding_ts **保留不动**：那是结算窗口调度状态，不是行情，
 * 仍由 ContractFunding 独占推进；现货 pair 上也没有它的意义。
 *
 * 存量费率不做搬运，新列留空等下一轮采集更新即可——结算侧的新鲜度校验会让空窗期安全跳过。
 */
class AddFundingRateToMarketData extends Migrator
{
    public function up()
    {
        // ── 1. 原始行情层 ──────────────────────────────────────────────
        $marketData = $this->table('market_data');
        $marketData->addColumn('funding_rate', 'decimal', [
            'precision' => 10,
            'scale'     => 6,
            'null'      => false,
            'default'   => 0,
            'after'     => 'mark_ts',
            'comment'   => '当前资金费率 (交易所原始值)',
        ]);
        $marketData->addColumn('estimated_funding_rate', 'decimal', [
            'precision' => 10,
            'scale'     => 6,
            'null'      => false,
            'default'   => 0,
            'after'     => 'funding_rate',
            'comment'   => '预估下期资金费率',
        ]);
        $marketData->addColumn('funding_rate_ts', 'biginteger', [
            'null'      => false,
            'default'   => 0,
            'after'     => 'estimated_funding_rate',
            'comment'   => '资金费率采集毫秒级时间戳，用于新鲜度校验与 REST 兜底触发判断',
        ]);
        $marketData->addColumn('exchange_next_funding_ts', 'biginteger', [
            'null'      => false,
            'default'   => 0,
            'after'     => 'funding_rate_ts',
            'comment'   => '交易所自报的下次结算毫秒时间戳，仅记录备用，不参与本平台结算窗口计算',
        ]);
        $marketData->save();

        // ── 2. pair 行情层 ────────────────────────────────────────────
        // 紧跟 mark_price/mark_ts 之后，三者同属"只对合约有意义、现货 pair 上恒为 0"的行情列
        $pair = $this->table('exchange_pair');
        $pair->addColumn('funding_rate', 'decimal', [
            'precision' => 10,
            'scale'     => 6,
            'null'      => false,
            'default'   => 0,
            'after'     => 'mark_ts',
            'comment'   => '当前资金费率 (行情模块同步写入)',
        ]);
        $pair->addColumn('estimated_funding_rate', 'decimal', [
            'precision' => 10,
            'scale'     => 6,
            'null'      => false,
            'default'   => 0,
            'after'     => 'funding_rate',
            'comment'   => '预估下期资金费率',
        ]);
        $pair->addColumn('funding_rate_ts', 'biginteger', [
            'null'      => false,
            'default'   => 0,
            'after'     => 'estimated_funding_rate',
            'comment'   => '资金费率毫秒级时间戳，结算前据此判断费率是否已过期 (单位对齐 mark_ts)',
        ]);
        $pair->save();

        // ── 3. 从 exchange_contract 摘除费率列 ──────────────────────────
        // next_funding_ts 不动：结算窗口状态，非行情
        //
        // 不搬运 exchange_contract 上的存量费率：新列留空即可，等采集器/分发的下一轮更新自然写入。
        // 这段空窗是安全的——结算侧会校验 funding_rate_ts 新鲜度，ts=0 时直接跳过该期且不推进
        // next_funding_ts，不会拿 0 费率错误收费，窗口留到费率到位后的下一轮重试。
        // market-relay：分发机的库没有业务表，表不存在就跳过
        if (!$this->hasTable('exchange_contract')) return;
        $contract = $this->table('exchange_contract');
        $contract->removeColumn('funding_rate');
        $contract->removeColumn('estimated_funding_rate');
        $contract->removeColumn('funding_rate_ts');
        $contract->save();
    }

    public function down()
    {
        // 还原 exchange_contract 的三列 (含原来的非零默认值)
        // market-relay：分发机的库没有业务表，表不存在就跳过
        if ($this->hasTable('exchange_contract')) {
            $contract = $this->table('exchange_contract');
            $contract->addColumn('funding_rate', 'decimal', [
                'precision' => 10,
                'scale'     => 6,
                'null'      => false,
                'default'   => 0.000100,
                'comment'   => '当前资金费率',
            ]);
            $contract->addColumn('estimated_funding_rate', 'decimal', [
                'precision' => 10,
                'scale'     => 6,
                'null'      => false,
                'default'   => 0.000100,
                'comment'   => '预估下期资金费率',
            ]);
            $contract->addColumn('funding_rate_ts', 'integer', [
                'null'      => false,
                'default'   => 0,
                'comment'   => '资金费率同步时间戳(秒)',
            ]);
            $contract->save();
        }

        // 同样不搬运数据回去，理由同 up()：回滚后由 FundingRateSyncer/采集侧下一轮更新写入即可
        $pair = $this->table('exchange_pair');
        $pair->removeColumn('funding_rate');
        $pair->removeColumn('estimated_funding_rate');
        $pair->removeColumn('funding_rate_ts');
        $pair->save();

        $marketData = $this->table('market_data');
        $marketData->removeColumn('funding_rate');
        $marketData->removeColumn('estimated_funding_rate');
        $marketData->removeColumn('funding_rate_ts');
        $marketData->removeColumn('exchange_next_funding_ts');
        $marketData->save();
    }
}
