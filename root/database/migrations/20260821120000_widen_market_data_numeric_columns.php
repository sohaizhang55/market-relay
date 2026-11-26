<?php

use think\migration\Migrator;

/**
 * 放宽 market_data 的数值列宽，与 exchange_pair 对齐。
 *
 * ── 为什么要改 ────────────────────────────────────────────────────────────
 * 线上落库每轮都失败：
 *     SQLSTATE[22003]: Out of range value for column 'vol' at row 1
 *
 * market_data 的数值列一直是 decimal(20,8) —— 整数位只有 12 位，上限约 1e12。
 * 而 Binance 的 @ticker 里 `v` 是**基础币成交量**，SHIB / PEPE 这类低价币的
 * 24 小时成交量轻松上万亿，直接越界。火币那边 `amount` 同理。
 *
 * exchange_pair 的同名列早就是 decimal(30,10)（上限约 1e20），**两层列宽从一开始
 * 就不一致**：pair 层装得下、原始层装不下。所以这不是"哪个币特别大"的偶发问题，
 * 而是原始层的定义本来就窄了一档，只是 vol 数字最大、第一个撞上而已。
 * 本迁移把原始层统一对齐到 pair 层。
 *
 * ── 为什么这个 bug 的影响面远超"一个币" ──────────────────────────────────
 * MarketService::batchUpdate() 把整批行包在**一个事务**里。只要有一个 source_key
 * 的一个字段越界，整轮就回滚 —— **那一轮全部 51 个 source_key 都写不进去**。
 * 于是表现是整张 market_data 冻住、越拖越远，而不是"某个币的数据不对"。
 * （批量降级为逐行重试的改动见 MarketService::batchUpdate()，与本迁移配套。）
 *
 * 精度从 8 位小数提到 10 位是顺带的：对齐 pair 层即可，不影响既有值。
 * 表只有一个 source_key 一行（现网约 51 行），ALTER 瞬间完成。
 */
class WidenMarketDataNumericColumns extends Migrator
{
    /** 与 exchange_pair 同名列一致的目标精度 */
    private const PRECISION = 30;
    private const SCALE     = 10;

    /** 价格类：本身撑不爆 12 位，但没有理由比 pair 层窄 */
    // bbo_bid / bbo_ask 已由 20260820120000_drop_bbo_fields_from_market_data 删除，不再列入。
    // 本迁移的 hasColumn() 守卫本来也会跳过它们，列着只是死条目
    private const PRICE_COLUMNS = ['open', 'close', 'low', 'high', 'mark_price'];

    /** 成交量类：真正会越界的是这两个 */
    private const VOLUME_COLUMNS = ['amount', 'vol'];

    /**
     * 取该列现有的 COMMENT。
     *
     * ⚠️ 这一步不能省：phinx 的 changeColumn() 是**整列重定义**，选项里不带 comment
     * 就等于把它改成空串。第一版就是这么写的，结果 open/close/low/high/amount/vol/mark_price
     * 七个列的注释在迁移后全没了 —— 本地重建库跑完一比对才发现。
     * 列注释是 db_structure.sql 里唯一的字段级文档，掉了不报错、也没人会立刻察觉。
     *
     * 不写死一份注释表而是回读：这样无论生产上现在挂的是哪一版注释，迁移都只改精度、不动文档。
     */
    private function columnComment(string $table, string $column): string
    {
        // ⚠️ 必须显式起别名。information_schema 的列名在结果集里是**大写**的
        // (MySQL 8 实测键名为 COLUMN_COMMENT)，按 $row['column_comment'] 取会拿到 null，
        // 于是"保留注释"变成"把注释设成空串"——正是本方法要修的那个 bug 的翻版。
        $row = $this->fetchRow(sprintf(
            "SELECT column_comment AS col_comment FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name = '%s'",
            $table, $column
        ));
        return (string)($row['col_comment'] ?? '');
    }

    /** 只改精度，保留 comment */
    private function widen(string $col, int $precision, int $scale): void
    {
        $table = $this->table('market_data');
        if (!$table->hasColumn($col)) return;
        $table->changeColumn($col, 'decimal', [
            'precision' => $precision,
            'scale'     => $scale,
            'null'      => false,
            'default'   => 0,
            'comment'   => $this->columnComment('market_data', $col),
        ])->save();
    }

    public function up()
    {
        foreach (array_merge(self::PRICE_COLUMNS, self::VOLUME_COLUMNS) as $col) {
            $this->widen($col, self::PRECISION, self::SCALE);
        }
    }

    public function down()
    {
        // 回滚到 decimal(20,8)。⚠️ 若期间已经写入过超过 1e12 的成交量，
        // 回滚会再次触发 Out of range —— 这正是当初的故障，所以回滚前先确认：
        //   SELECT source_key, vol, amount FROM market_data
        //   WHERE vol >= 1000000000000 OR amount >= 1000000000000;
        foreach (array_merge(self::PRICE_COLUMNS, self::VOLUME_COLUMNS) as $col) {
            $this->widen($col, 20, 8);
        }
    }
}
