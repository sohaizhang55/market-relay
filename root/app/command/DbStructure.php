<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 数据库结构导出指令
 *
 * ── 输出必须同时能装在 MySQL 5.7 与 8.4 上 ────────────────────────────────
 * SHOW CREATE TABLE 吐的是**本机服务器版本的方言**，直接落盘会有两个后果：
 *   · 在 8.0.19+ 上生成的快照带 `int`（显示宽度已废弃），在 5.7 上生成的带 `int(11)`，
 *     于是谁重跑一次就翻一次，1000 行的方言 diff 把真正的结构变更埋掉；
 *   · 8.0 默认排序规则 utf8mb4_0900_ai_ci 在 5.7 上根本不存在，装机直接失败。
 * 所以这里统一做一次归一化，让 5.7 与 8.4 生成的快照一致、且两边都能跑。见 normalize()。
 *
 * @class DbStructure
 * @package app\command
 */
class DbStructure extends Command
{
    /**
     * 5.7 不认识的排序规则。命中就中止而不是硬改：改排序规则会改变比较与排序语义，
     * 那是要人决策的事，不能由导出脚本悄悄替换
     */
    private const MYSQL8_ONLY_COLLATIONS = ['utf8mb4_0900_', 'utf8mb4_zh_', 'utf8mb4_ja_'];

    /**
     * 指令配置
     */
    protected function configure()
    {
        $this->setName('xdata:db')
            ->setDescription('Scan database structure and update database/db_structure.sql (MySQL 5.7 / 8.4 compatible)');
    }

    /**
     * 把 SHOW CREATE TABLE 的输出归一化成 5.7 与 8.4 都能跑、且与服务器版本无关的形态。
     *
     * @param string $sql   单表的 CREATE TABLE 语句
     * @param string $table 表名，仅用于报错定位
     * @return string
     * @throws \RuntimeException 命中 5.7 装不了的构造时抛出，由调用方中止整次导出
     */
    private function normalize(string $sql, string $table): string
    {
        foreach (self::MYSQL8_ONLY_COLLATIONS as $bad) {
            if (stripos($sql, $bad) !== false) {
                throw new \RuntimeException(
                    "表 {$table} 用了 MySQL 8 专有排序规则（{$bad}*），5.7 装不上。"
                    . '请先把该列/表改成 utf8mb4_unicode_ci 或 utf8mb4_general_ci 再导出。'
                );
            }
        }

        // 整数显示宽度：8.0.19 起不再输出，5.7 会输出 int(11)。两边都接受不带宽度的写法，
        // 统一去掉，快照就与生成它的服务器版本无关了。只动整数族，decimal/varchar/char/bit 的括号是有意义的
        $sql = preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/i', '$1', $sql);

        // utf8mb3 是 8.0.29+ 对旧 utf8 的显式命名，5.7 不认；写回 utf8 两边都懂
        $sql = preg_replace('/\butf8mb3\b/i', 'utf8', $sql);

        // 列级 `CHARACTER SET utf8mb4`：8.x 会显式打出来，5.7 不打。只在与表默认字符集相同时删，
        // 删掉后列从表默认继承同一个字符集，语义不变；列自己的 COLLATE 保留，字符集也能由它反推。
        // 不这么做的话，同一个库在 5.7 与 8.4 上导出会差三百行，真正的结构变更就被埋了
        if (preg_match('/DEFAULT CHARSET=(\w+)/i', $sql, $m)) {
            $sql = preg_replace('/ CHARACTER SET ' . preg_quote($m[1], '/') . '\b/i', '', $sql);
        }

        // AUTO_INCREMENT 计数器：整个删掉而不是归零。5.7 会带上当前值、8.4 空表时干脆不输出，
        // 归零只是把"两种输出"变成"三种"，删掉才真正稳定；建表本来也不需要它
        $sql = preg_replace('/\s*AUTO_INCREMENT=\d+/i', '', $sql);

        // 表级 COLLATE 必须显式写出来，哪怕当前服务器认为它是默认值。
        //
        // utf8mb4 的默认排序规则在两个版本里**不是同一个**：5.7 是 utf8mb4_general_ci，
        // 8.x 是 utf8mb4_0900_ai_ci。5.7 导出时会因为"这就是默认值"而省略 COLLATE=，
        // 那份快照拿到 8.4 上装，表会静默变成 utf8mb4_0900_ai_ci —— 排序与比较语义全变，
        // 而且 5.7 根本不认识这个排序规则，再导回来就装不上了。补上就没有这个坑
        if (preg_match('/DEFAULT CHARSET=(\w+)/i', $sql, $m) && !preg_match('/\bCOLLATE=/i', $sql)) {
            $collation = Db::query(
                'SELECT table_collation c FROM information_schema.tables'
                . ' WHERE table_schema = DATABASE() AND table_name = ?', [$table]
            )[0]['c'] ?? '';
            if ($collation !== '') {
                $sql = preg_replace('/(DEFAULT CHARSET=' . preg_quote($m[1], '/') . ')/i',
                    '$1 COLLATE=' . $collation, $sql, 1);
            }
        }

        // ROW_FORMAT=DYNAMIC：本库有 3 个索引（system_auth_node.node / user_recharge_chain.txid /
        // user_recharge_gateway.gateway_order_id）在 utf8mb4 下超过 767 字节。5.7 只有在
        // DYNAMIC + innodb_large_prefix=ON 时才允许，虽然 5.7.9+ 默认如此，但那是**服务器配置**，
        // 装机环境不由我们控制。显式写出来，把它变成快照自带的保证
        if (stripos($sql, 'ROW_FORMAT=') === false) {
            $sql = preg_replace('/\bENGINE=InnoDB\b/i', 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC', $sql, 1);
        }

        return $sql;
    }

    /**
     * 执行指令
     * @param Input $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output)
    {
        $output->writeln('<info>Starting database structure scan...</info>');

        try {
            $tables = Db::query('SHOW TABLES');
            if (empty($tables)) {
                $output->writeln('<error>No tables found in the database.</error>');
                return 1;
            }

            $serverVersion = (string)(Db::query('SELECT VERSION() v')[0]['v'] ?? 'unknown');

            $sqlContent = "-- +----------------------------------------------------------------------\n";
            $sqlContent .= "-- | ThinkAdmin Database Structure Dump\n";
            $sqlContent .= "-- | Generated at: " . date('Y-m-d H:i:s') . " (server {$serverVersion})\n";
            $sqlContent .= "-- | 已归一化为 MySQL 5.7 / 8.4 通用方言：整数不带显示宽度、不带 AUTO_INCREMENT 计数器、\n";
            $sqlContent .= "-- | 显式 ROW_FORMAT=DYNAMIC（绕开 5.7 的 767 字节索引前缀限制）。\n";
            $sqlContent .= "-- | 重新导出一律用 php think xdata:db，别直接贴 mysqldump 的输出——那会把服务器版本方言带进来。\n";
            $sqlContent .= "-- +----------------------------------------------------------------------\n\n";
            $sqlContent .= "SET NAMES utf8mb4;\n";
            $sqlContent .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

            foreach ($tables as $tableItem) {
                $tableName = current($tableItem);

                // 排除迁移表
                if (in_array($tableName, ['migrations'])) {
                    continue;
                }

                $output->writeln("Processing table: <comment>{$tableName}</comment>");

                // 获取创建语句
                $createStatement = Db::query("SHOW CREATE TABLE `{$tableName}`");
                if (!empty($createStatement)) {
                    $sql = $this->normalize($createStatement[0]['Create Table'], $tableName);

                    $sqlContent .= "-- ----------------------------\n";
                    $sqlContent .= "-- Table structure for {$tableName}\n";
                    $sqlContent .= "-- ----------------------------\n";
                    $sqlContent .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
                    $sqlContent .= $sql . ";\n\n";
                }
            }

            $sqlContent .= "SET FOREIGN_KEY_CHECKS = 1;\n";

            // 写入文件
            $savePath = $this->app->getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'db_structure.sql';
            if (!is_dir(dirname($savePath))) {
                mkdir(dirname($savePath), 0755, true);
            }

            file_put_contents($savePath, $sqlContent);

            $output->writeln("<info>Success! Structure saved to: {$savePath}</info>");
            return 0;

        } catch (\Exception $e) {
            $output->writeln("<error>Flash dump failed: {$e->getMessage()}</error>");
            return 1;
        }
    }
}
