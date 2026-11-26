<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;

/**
 * 比对本机实际生效的 `config/market.php` 与仓库里对应的 sample。
 *
 * 为什么需要它：`config/market.php` 是 **gitignore 的**，每台机器一份、
 * 由人从 sample 拷贝一次之后就再也不会自动跟进。于是一旦仓库里改了某个默认值，
 * 已经在跑的机器**永远停在老默认上**，而且：
 *   · `git pull` 没有任何提示（那个文件不在版本控制里）
 *   · 代码里的 `Config::get('x', 默认)` 第三参数也帮不上忙 ——
 *     本机文件里那个键**存在**，只是值是旧的，走不到代码默认
 *   · 日志、指标、监控页统统看不出来："配置就是这么配的"
 *
 * 现实的例子：`stale_seconds` 的默认值从 30 改成 300，sample 改了，
 * 而各机器的 `config/market.php` 仍是 30 —— 过期判定按旧值走，
 * 日志、指标、监控页统统显示"配置就是这么配的"，没有任何告警。
 * （历史上同样的事发生在 `publish_kline` 上：2026-08-23 默认值从 false 翻成 true，
 *  各机器的文件仍是 false，整条收盘 K 线分发链继续不跑。那个键已随
 *  「K 线无条件分发」删除，教训留在这里。）
 *
 * 判据只看**文本**，不看运行时值：`env('NAME', 默认)` 的那个默认字面量。
 * 比运行时值可靠 —— 运行时值受 .env 影响，而 .env 本来就该按机器不同。
 * 这里要回答的是"这台机器的 config 文件跟上仓库了吗"，不是"值对不对"。
 *
 *   php think market:config_check
 *   php think market:config_check --verify   # 只给退出码
 */
class MarketConfigCheck extends Command
{
    protected function configure()
    {
        $this->setName('market:config_check')
            ->addOption('verify', null, Option::VALUE_NONE, '只给退出码，供部署脚本调用')
            ->addOption('env', null, Option::VALUE_NONE, '输出需要写进 .env 的片段')
            ->addOption('drift', null, Option::VALUE_NONE, '只做与仓库模板的默认值漂移比对')
            ->setDescription('比对本机 config/market.php 与对应 sample 的默认值漂移');
    }

    protected function execute(Input $input, Output $output)
    {
        $quiet = (bool)$input->getOption('verify');
        $dir   = $this->app->getConfigPath();

        if ($input->getOption('env')) {
            $this->printEnvTemplate($output);
            return 0;
        }

        // ① 生效配置 + 逐项判定。放在漂移比对之前 ——
        //    "我这台机器现在到底生效了什么、配没配对"才是配置时真正要问的，
        //    而漂移比对回答的是另一个问题（"我的文件跟上仓库了吗"）
        $bad = 0;
        if (!$input->getOption('drift')) {
            $bad = $this->reportEffective($output, $quiet);
        }
        // bad 不在这里提前退出：退出码由末尾那条 return 统一给（bad 或 drift 都非零），
        // 提前 return 会让一条 bad（token 空、主机名解析失败）吞掉下面整段漂移比对 ——
        // 而升级期间最需要看的正是 [DRIFT]

        $live = $dir . 'market.php';
        if (!is_file($live)) {
            $output->writeln('<error>[config][FAIL]</error> config/market.php 不存在 —— 从对应 sample 拷一份');
            return 1;
        }

        $sample = $this->pickSample($dir);
        if ($sample === null) {
            $output->writeln('<error>[config][FAIL]</error> 找不到可比对的 sample 文件');
            return 1;
        }

        $liveSrc        = (string)file_get_contents($live);
        $acked          = $this->ackedKnobs($liveSrc);
        $liveDefaults   = $this->envDefaults($liveSrc);
        $sampleDefaults = $this->envDefaults((string)file_get_contents($sample));

        $drift   = [];
        $missing = [];
        foreach ($sampleDefaults as $env => $want) {
            if (isset($acked[$env])) continue;              // 本机有意改的，已写明理由
            if (!array_key_exists($env, $liveDefaults)) { $missing[$env] = $want; continue; }
            if ($liveDefaults[$env] !== $want) $drift[$env] = [$liveDefaults[$env], $want];
        }

        if (!$quiet) {
            $output->writeln(sprintf('<info>[config]</info> 比对 %s', basename($sample)));
            $output->writeln(sprintf('   本机 %d 个 env 旋钮，sample %d 个，已确认豁免 %d 个',
                count($liveDefaults), count($sampleDefaults), count($acked)));
        }

        foreach ($drift as $env => [$got, $want]) {
            $output->writeln(sprintf(
                '<error>[config][DRIFT]</error> %s 本机默认 %s，仓库已改为 %s'
                . ' —— config/market.php 是 gitignore 的，git pull 不会更新它',
                $env, $got, $want
            ));
        }
        foreach ($missing as $env => $want) {
            $output->writeln(sprintf(
                '<comment>[config][NEW]</comment> %s 是仓库新增的旋钮（默认 %s），本机 config 里没有'
                . ' —— 会走代码里的默认值，通常无害，但值得知道',
                $env, $want
            ));
        }
        if (!$drift && !$missing) {
            $output->writeln('<info>[config][OK]</info> 本机 config/market.php 的默认值与 sample 一致'
                . ($acked ? sprintf('（%d 个旋钮已写明本机有意不同）', count($acked)) : ''));
        }
        if ($drift) {
            $output->writeln('');
            $output->writeln('   本机有意改的，在 config/market.php 里那一行上方写一行说明即可豁免：');
            $output->writeln('     // @config-drift-ok: MARKET_XXX —— 理由');
        }

        // NEW 只提示不失败：走代码默认通常是对的，而 DRIFT 是"停在老默认上"，必须修
        return ($drift || $bad > 0) ? 1 : 0;
    }

    /**
     * 本机有意与 sample 不同的旋钮：在 config/market.php 里写
     *     // @config-drift-ok: MARKET_XXX —— 理由
     *
     * 为什么要有这个口子：端口、对端地址、密钥这类**本来就该按机器不同**的值，
     * 若每次都报一遍，这条检查很快就没人看了 —— 而它真正要挡的是
     * 「仓库改了策略默认值，而这台机器停在老默认上」那种。
     * 一个吵的检查等于没有检查；豁免要留痕，理由要写在旁边。
     *
     * @return array<string,true>
     */
    private function ackedKnobs(string $src): array
    {
        $out = [];
        if (preg_match_all('#@config-drift-ok:\s*([A-Z][A-Z0-9_]*)#', $src, $ms)) {
            foreach ($ms[1] as $k) $out[$k] = true;
        }
        return $out;
    }

    /** 比对用的模板：config/market.sample.php */
    private function pickSample(string $dir): ?string
    {
        return is_file($dir . 'market.sample.php') ? $dir . 'market.sample.php' : null;
    }

    /**
     * 抽出 `env('NAME', 默认)` 里的默认字面量，键为环境变量名。
     *
     * 只认字面量默认（true/false/数字/字符串）—— 表达式默认没法做文本比对，
     * 硬比会把"写法不同但值相同"报成漂移，而一个吵的检查等于没有检查。
     *
     * @return array<string,string>
     */
    private function envDefaults(string $src): array
    {
        $out = [];
        $re = "#env\(\s*'([A-Z][A-Z0-9_]*)'\s*,\s*(true|false|null|-?\d+(?:\.\d+)?|'[^']*')\s*\)#i";
        if (preg_match_all($re, $src, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) {
                $out[$m[1]] = strtolower($m[2]) === 'true' ? 'true'
                    : (strtolower($m[2]) === 'false' ? 'false' : $m[2]);
            }
        }
        return $out;
    }

    /**
     * 列出**实际生效**的配置并逐项判定。
     *
     * 为什么要有这个：sample 的注释是第一次读的时候看的，而配置/排查时要问的是
     * 「我这台机器现在到底生效了什么、配没配对」—— 那在 300 行注释里翻是找不到的。
     * 一行一个，带判定与一句话原因。
     *
     * @return int 有问题的项数
     */
    private function reportEffective(Output $output, bool $quiet): int
    {
        $rows = [];   // [级别, 键, 值, 说明]  级别: ok|warn|bad
        $add = function (string $lv, string $k, $v, string $note = '') use (&$rows) {
            $rows[] = [$lv, $k, is_bool($v) ? ($v ? 'true' : 'false') : (string)$v, $note];
        };
        $cfg = fn(string $k, $d = null) => Config::get('market.' . $k, $d);

        // ── inner_api 鉴权 ──────────────────────────────────────────────────
        $token = (string)$cfg('inner_api.token', '');
        if ($token === '') {
            $add('bad', 'inner_api.token', '(空)', '必填。与订阅方填同一个，空 = /inner_api/* 一律 403');
        } elseif (in_array($token, self::COMPROMISED_TOKENS, true)) {
            $add('bad', 'inner_api.token', '(仓库公开的历史默认值)', '必须改掉，等同未配置');
        } else {
            $add('ok', 'inner_api.token', '已配置(' . strlen($token) . ' 字符)', '与订阅方必须一致');
        }
        $ips = (string)$cfg('inner_api.allow_ips', '');
        $add($ips === '' ? 'warn' : 'ok', 'inner_api.allow_ips',
            $ips === '' ? '(空)' : $ips, $ips === '' ? '不限制来源 IP' : '');

        // ── 分发 ────────────────────────────────────────────────────────────
        $host = (string)$cfg('dispatch.host', '0.0.0.0');
        $hostOk = in_array($host, ['0.0.0.0', '127.0.0.1', 'localhost'], true);
        $add($hostOk ? 'ok' : 'bad', 'dispatch.host', $host,
            $hostOk ? '' : '⚠️ 云主机的公网 IP 不在本机网卡上，bind 会失败；对外监听填 0.0.0.0');
        // 现货 / 合约各一个分发进程、各一个端口；两个端口相同时后起的那个会 bind 失败。
        // 端口必填、没有默认值：没配为 0，market:dispatch 会拒绝启动
        $spotPort = (int)$cfg('dispatch.ports.' . \app\enum\MarketDataTypeEnum::SPOT->value, 0);
        $swapPort = (int)$cfg('dispatch.ports.' . \app\enum\MarketDataTypeEnum::SWAP->value, 0);
        $unset = '未配置：.env 缺 %s（值见 deploy/ports.env），market:dispatch 会拒绝启动';
        $add($spotPort > 0 ? 'ok' : 'bad', 'dispatch.ports 现货', $spotPort,
            $spotPort > 0 ? '' : sprintf($unset, 'MARKET_DISPATCH_SPOT_PORT'));
        if ($swapPort <= 0) {
            $add('bad', 'dispatch.ports 合约', $swapPort, sprintf($unset, 'MARKET_DISPATCH_CONTRACT_PORT'));
        } else {
            $add($spotPort !== $swapPort ? 'ok' : 'bad', 'dispatch.ports 合约', $swapPort,
                $spotPort !== $swapPort ? '' : '⚠️ 与现货端口相同，两个分发进程只能起一个');
        }

        $add('ok', 'dispatch.tick_ms', (int)$cfg('dispatch.tick_ms', 100));

        // ── 其它 ────────────────────────────────────────────────────────────

        // 残留旧配置段：分发机启动时会被拒，这里提前说清楚
        $legacy = $cfg('dispatcher');
        if (is_array($legacy) && $legacy !== []) {
            $add('bad', 'dispatcher(旧段)', '仍存在',
                '已并入 dispatch：把 host / port / max_send_buffer 挪进 dispatch 段并删掉本段，env 名不变');
        }

        // 残留的订阅方配置段：已没有订阅方角色，留着只会让人以为还有这条路
        $sub = $cfg('subscriber');
        if (is_array($sub) && $sub !== []) {
            $add('warn', 'subscriber(旧段)', '仍存在', '已没有订阅方角色，删掉本段（连同 .env 的 MARKET_DISPATCH_SERVER / MARKET_API_URL）');
        }
        if ($cfg('mode') !== null) {
            $add('warn', 'mode(旧键)', (string)$cfg('mode'), '已没有 mode，删掉这一行（连同 .env 的 MARKET_MODE）');
        }

        // 8a：能同时砍掉采集 / 回补 / 心跳判活三处的开关，最该出现在这一屏上
        $dsk = array_filter((array)$cfg('debug_source_keys', []));
        $add($dsk === [] ? 'ok' : 'warn', 'debug_source_keys',
            $dsk === [] ? '(空 -> 全部源)' : implode(',', $dsk),
            $dsk === [] ? '' : '⚠️ 其余源全部不采集 / 不回补 / 不判活 —— 心跳告警也被同一份配置关掉了');

        $kb = (bool)$cfg('kline_backfill.enable', true);
        $add($kb ? 'ok' : 'warn', 'kline_backfill.enable', $kb,
            $kb ? '' : '⚠️ 关着则缺口无人补，收盘蜡烛的 REST 复核也一并停');
        $rf = (bool)$cfg('rest_fallback.enable', true);
        $add($rf ? 'ok' : 'warn', 'rest_fallback.enable', $rf,
            $rf ? '' : '⚠️ 关着则 WS 断流期间价格完全停更');
        $stale = (int)$cfg('stale_seconds', 300);
        $add('ok', 'stale_seconds', $stale,
            '共用：监控页 / 价格过期告警 / REST 兜底阈值');

        // 显示**结果**而不是显示输入：lead_seconds 是提前量不是绝对阈值，
        // 光看两个配置值推不出真实行为，而这正是那句错注释坑过一次的地方
        $lead   = (int)$cfg('rest_fallback.lead_seconds', 0);
        $maxAge = max(1, $stale - $lead);
        $add($maxAge <= 5 ? 'bad' : 'ok', 'rest_fallback 触发阈值', $maxAge . 's',
            $maxAge <= 5
                ? "⚠️ = stale_seconds({$stale}) - lead_seconds({$lead})，已塌到 {$maxAge} 秒："
                  . '兜底每轮都会认为所有源都过期、持续打交易所 REST。调 stale_seconds 时必须同步调 lead_seconds'
                : "= stale_seconds({$stale}) - lead_seconds({$lead})；WS 静默这么久就开始 REST 兜底");

        $bad = 0;
        foreach ($rows as [$lv, , , ]) if ($lv === 'bad') $bad++;

        if ($quiet) return $bad;

        $output->writeln('<info>[生效配置]</info>');
        foreach ($rows as [$lv, $k, $v, $note]) {
            $mark = ['ok' => ' ', 'warn' => '<comment>!</comment>', 'bad' => '<error>x</error>'][$lv];
            $output->writeln(sprintf('  %s %-30s %-26s %s', $mark, $k, $v, $note));
        }
        $output->writeln($bad > 0
            ? sprintf('<error>[配置][FAIL]</error> %d 项必须修正（上面标 x 的）', $bad)
            : '<info>[配置][OK]</info> 生效配置没有问题');
        $output->writeln('');
        return $bad;
    }

    /** 仓库里公开过的 token，等同未配置 */
    private const COMPROMISED_TOKENS = ['d9cc1aebefd19991b9722445d7b7067b9'];

    /**
     * 输出需要写进 .env 的片段。
     *
     * 配置一台新机器时，真正要做的只有几行 —— 而那几行埋在 300 行模板里。
     * 这里直接把它们连同当前值一起打出来，能贴就贴。
     */
    private function printEnvTemplate(Output $output): void
    {
        $cfg  = fn(string $k, $d = null) => Config::get('market.' . $k, $d);
        $out  = [];

        $out[] = '# ── 行情链路 ──';
        $tok = (string)$cfg('inner_api.token', '');
        $out[] = 'MARKET_INNER_API_TOKEN=' . ($tok === '' || in_array($tok, self::COMPROMISED_TOKENS, true)
            ? '<必填：与订阅方填同一个随机串>' : $tok);
        $out[] = '';
        $out[] = '# 分发端口';
        // 必填项：已配就打当前值，没配打占位，提示去 deploy/ports.env 取值
        $portLine = function (string $env, int $type) use ($cfg): string {
            $p = (int)$cfg('dispatch.ports.' . $type, 0);
            return $env . '=' . ($p > 0 ? $p : '<必填：值见 deploy/ports.env>');
        };
        $out[] = $portLine('MARKET_DISPATCH_SPOT_PORT', \app\enum\MarketDataTypeEnum::SPOT->value);
        $out[] = $portLine('MARKET_DISPATCH_CONTRACT_PORT', \app\enum\MarketDataTypeEnum::SWAP->value);
        $out[] = '';
        $out[] = '# 其余旋钮保持默认即可；要看全部生效值：php think market:config_check';

        foreach ($out as $l) $output->writeln($l);
    }
}
