<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * 校验**活库**的行情表结构是否符合链路依赖的不变量。
 *
 * 为什么需要它：迁移跑没跑过、跑成什么样，这些都只在活库里，
 * `grep` 与 `migrate:status` 都回答不了 ——
 *   · migrate:status 说的是"这条迁移的记录在不在"，不是"库真的长这样"
 *   · db_structure.sql 是快照，与真实库可能已经不同步
 *
 * 三条不变量都是踩过的坑：
 *   1. bbo_* 列必须**不存在**。热层还留着 bbo 字段、而库已经删列时，
 *      落库会以 Unknown column 'bbo_bid' **整批失败** —— 现网发生过约 15 分钟。
 *   2. 数值列必须是 decimal(30,10)。窄列会在大额/高精度币种上静默截断。
 *   3. 这些列必须有 COMMENT。phinx 的 changeColumn() 是整列重定义，
 *      不带 comment 就把注释抹成空串 —— widen 那次迁移抹掉了 7 个。
 *
 *   php think market:schema_check          # 人看
 *   php think market:schema_check --verify # 脚本用，只给退出码
 */
class MarketSchemaCheck extends Command
{
    /**
     * 必须是 decimal(30,10) 且带注释的数值列。
     *
     * 与 20260821120000_widen_market_data_numeric_columns 的
     * PRICE_COLUMNS + VOLUME_COLUMNS **必须一致** —— 这里多列一个就是误报。
     * （初版把 price / funding_rate 也列了进来，当场把 funding_rate 的
     * decimal(10,6) 报成违规，而那个精度是有意的：费率本来就很小。
     * 判据自身的期望值错了，与被判对象错了，是两回事。）
     */
    private const NUMERIC_COLUMNS = [
        'open', 'close', 'low', 'high', 'mark_price',   // PRICE_COLUMNS
        'amount', 'vol',                                 // VOLUME_COLUMNS
    ];

    private const WANT_PRECISION = 30;
    private const WANT_SCALE     = 10;

    protected function configure()
    {
        $this->setName('market:schema_check')
            ->addOption('verify', null, Option::VALUE_NONE, '只给退出码，不打印明细（供部署脚本调用）')
            ->setDescription('校验活库的 market_data 结构是否符合行情链路依赖的不变量');
    }

    protected function execute(Input $input, Output $output)
    {
        $quiet = (bool)$input->getOption('verify');
        $problems = [];

        $cols = $this->columns('market_data');
        if (empty($cols)) {
            $output->writeln('<error>[schema]</error> 读不到 market_data 的列定义 —— 表不存在或库凭据不对');
            return 1;
        }

        // ① bbo_* 必须已删除
        $bbo = array_values(array_filter(array_keys($cols), fn($c) => str_starts_with($c, 'bbo')));
        if ($bbo) {
            $problems[] = sprintf(
                'market_data 还有 bbo 列（%s）—— DropBbo 迁移没在这台机器跑过；'
                . '此时若热层已清过 bbo 字段则无害，反之落库会整批失败',
                implode(', ', $bbo)
            );
        }

        // ② 数值列精度
        foreach (self::NUMERIC_COLUMNS as $c) {
            if (!isset($cols[$c])) continue;                 // 该表没有这一列就不判
            $p = (int)$cols[$c]['num_precision'];
            $s = (int)$cols[$c]['num_scale'];
            if ($p !== self::WANT_PRECISION || $s !== self::WANT_SCALE) {
                $problems[] = sprintf(
                    'market_data.%s 是 decimal(%d,%d)，期望 decimal(%d,%d) —— widen 迁移没跑或被改回去了',
                    $c, $p, $s, self::WANT_PRECISION, self::WANT_SCALE
                );
            }
        }

        // ③ 列注释
        $noComment = [];
        foreach (self::NUMERIC_COLUMNS as $c) {
            if (!isset($cols[$c])) continue;
            if (trim((string)$cols[$c]['col_comment']) === '') $noComment[] = $c;
        }
        if ($noComment) {
            $problems[] = sprintf(
                'market_data 有 %d 个列的 COMMENT 是空的（%s）—— changeColumn() 是整列重定义，'
                . '不带 comment 就把注释抹掉了。修复：按 root/database/db_structure.sql 里 market_data 的列定义 ALTER TABLE ... MODIFY 补回 COMMENT',
                count($noComment), implode(', ', $noComment)
            );
        }

        if (!$quiet) {
            $output->writeln(sprintf('<info>[schema]</info> market_data 共 %d 列，检查 %d 个数值列',
                count($cols), count(array_intersect(self::NUMERIC_COLUMNS, array_keys($cols)))));
            foreach (self::NUMERIC_COLUMNS as $c) {
                if (!isset($cols[$c])) continue;
                $output->writeln(sprintf('   %-14s decimal(%d,%d) comment=%s',
                    $c, (int)$cols[$c]['num_precision'], (int)$cols[$c]['num_scale'],
                    trim((string)$cols[$c]['col_comment']) === '' ? '<error>空</error>' : '有'));
            }
        }

        foreach ($problems as $p) {
            $output->writeln('<error>[schema][FAIL]</error> ' . $p);
        }
        if (!$problems) {
            $output->writeln('<info>[schema][OK]</info> 三条不变量全部满足（无 bbo 列 / decimal(30,10) / 注释齐全）');
        }
        return $problems ? 1 : 0;
    }

    /**
     * @return array<string,array{num_precision:?int,num_scale:?int,col_comment:string}>
     *
     * ⚠️ 必须给 information_schema 的列起别名：不加别名时驱动返回的是**大写**键
     * （COLUMN_COMMENT），按小写取会全部拿到 null —— 这一步当场踩过一次。
     */
    private function columns(string $table): array
    {
        $rows = Db::query(
            'SELECT COLUMN_NAME AS col_name, NUMERIC_PRECISION AS num_precision,'
            . ' NUMERIC_SCALE AS num_scale, COLUMN_COMMENT AS col_comment'
            . ' FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['col_name']] = [
                'num_precision' => $r['num_precision'],
                'num_scale'     => $r['num_scale'],
                'col_comment'   => (string)($r['col_comment'] ?? ''),
            ];
        }
        return $out;
    }
}
