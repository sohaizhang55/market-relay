<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use app\lib\MarketHelper;

/**
 * /inner_api/* 连通性与鉴权测试工具 —— 用本机的 token 去打 --url 指定的站点，验的是订阅方会走的那条路。
 *
 * ── 为什么需要一条专门的命令 ────────────────────────────────────────────
 * InnerAuth 有三条拒绝路径，**全都返回 403**：IP 不在白名单、本机 token 没配、请求 token 不匹配。
 * 于是一条裸 curl 得到的 403 什么也证明不了 —— 「配好了但没带头」和「压根没配」长得一模一样，
 * 只能去翻日志。这条命令把几种情况直接说清楚，并且**不打印 token 本身**（只打印指纹）。
 *
 * ── 为什么 --url 必填 ───────────────────────────────────────────────────
 * 站点端口在 deploy/ports.env 与 nginx 配置里，不在 PHP 配置里；命令里再写一个默认地址，
 * 就是第三份端口，改端口时最容易漏掉，而且漏掉后打的是错的端口、报的是「请求失败」，看不出是地址不对。
 * 要打哪里由调用方显式给出。
 *
 * 命令  php think market:inner_check
 * 触发  手工
 * 单例  无
 * 依赖  配置 market.inner_api.token · market.inner_api.allow_ips
 *
 * 用法:
 *   php think market:inner_check --url=http://127.0.0.1:32343   # 打本机站点（端口取 deploy/ports.env 的 SITE_PORT_3）
 *   php think market:inner_check --url=http://10.0.0.5:32343    # 从别的机器验 A 机
 *   php think market:inner_check --local                        # 只看本机配置，不发请求，不需要 --url
 *
 * 退出码  0 = 全部正常；1 = 有问题或参数错误（原因见输出）
 */
class MarketInnerApiCheck extends Command
{
    /** 与 InnerAuth 共用同一份「已泄露」名单，避免两处各记一份 */
    private const COMPROMISED = ['d9cc1aebefd19991b9722445d7b7067b9'];

    /** 费率超过这么久没更新算陈旧(秒)。market:funding 每 30 秒转 4 个合约，65 个合约一圈约 8 分钟 */
    private const FUNDING_STALE_SEC = 600;

    protected function configure()
    {
        $this->setName('market:inner_check')
            ->addOption('url', 'u', Option::VALUE_REQUIRED, 'inner_api 所在站点的 HTTP 根地址，必填（--local 时不需要），例如 http://127.0.0.1:32343')
            ->addOption('local', null, Option::VALUE_NONE, '只检查本机配置，不发请求')
            ->setDescription('Check /inner_api reachability and shared-token configuration');
    }

    protected function execute(Input $input, Output $output)
    {
        // 必填校验放在最前：框架的 VALUE_REQUIRED 只保证「给了 --url 就得带值」，不保证一定给了
        $local  = (bool)$input->getOption('local');
        $apiUrl = trim((string)$input->getOption('url'));
        if (!$local && !preg_match('#^https?://[^/\s]+#i', $apiUrl)) {
            $output->writeln($apiUrl === ''
                ? '<error>缺少 --url：要打哪个站点必须显式给出，命令里不带默认地址</error>'
                : "<error>--url 格式不对：{$apiUrl}（要以 http:// 或 https:// 开头）</error>");
            $output->writeln('用法:');
            $output->writeln('  php think market:inner_check --url=http://127.0.0.1:32343   # 端口取 deploy/ports.env 的 SITE_PORT_3');
            $output->writeln('  php think market:inner_check --local                        # 只看本机配置，不发请求');
            return 1;
        }

        $ok = true;

        // ── 本机配置 ──────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('<comment>本机配置</comment>');

        $token = trim((string)Config::get('market.inner_api.token', ''));
        if ($token === '') {
            $this->line($output, 'inner_api.token', '未配置 —— 请在 root/.env 设置 MARKET_INNER_API_TOKEN', false);
            $ok = false;
        } elseif (in_array($token, self::COMPROMISED, true)) {
            $this->line($output, 'inner_api.token', '仍是仓库公开的历史默认值，等同未配置', false);
            $ok = false;
        } else {
            // 只打指纹不打值：自检结果经常被贴进工单和聊天记录
            $this->line($output, 'inner_api.token', sprintf(
                '已配置（长度 %d，指纹 %s）', strlen($token), substr(hash('sha256', $token), 0, 12)
            ), true);
        }

        $allowIps = trim((string)Config::get('market.inner_api.allow_ips', ''));
        $this->line($output, 'inner_api.allow_ips', $allowIps !== '' ? $allowIps : '(空 = 不限制来源 IP)', true);

        if ($local) return $ok ? 0 : 1;

        // ── 实际请求 ──────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('<comment>请求 ' . $apiUrl . '</comment>');

        // 分发协议 v3：订阅方只认 pair，验收打的是 pair 接口
        $url = rtrim($apiUrl, '/') . '/inner_api/pair/fundingRates';

        // 先不带头打一次：预期被拒，证明鉴权确实生效
        $bare = MarketHelper::httpGetJson($url, 5);
        if (is_array($bare) && ($bare['code'] ?? 0) === 1) {
            $this->line($output, '不带 token', '居然通过了 —— 鉴权没生效，检查 inner_api 路由是否挂了 InnerAuth', false);
            $ok = false;
        } else {
            $this->line($output, '不带 token', '被拒绝（正常，这说明鉴权确实生效了）', true);
        }

        // 再带头打一次：这一发才是真正的验收
        $resp = MarketHelper::httpGetJson($url, 5, ["X-Inner-Token: {$token}"]);
        if ($resp === null) {
            $this->line($output, '带 token', '请求失败/超时 —— 检查站点是否在跑、端口与地址是否正确', false);
            return 1;
        }
        if (($resp['code'] ?? 0) !== 1) {
            $this->line($output, '带 token', sprintf(
                '仍被拒绝（msg=%s）—— token 不一致，或请求来源 IP 不在 allow_ips 内',
                (string)($resp['msg'] ?? '?')
            ), false);
            return 1;
        }

        $rows = (array)($resp['data'] ?? []);
        $this->line($output, '带 token', sprintf('通过，返回 %d 条费率', count($rows)), true);

        // ── 费率数据的新鲜度 ──────────────────────────────────────────────
        // 通道通不代表数据新：market:funding 断了，接口照样 200，只是费率越来越旧
        $output->writeln('');
        $output->writeln('<comment>费率新鲜度</comment>');

        if (empty($rows)) {
            $this->line($output, '费率', '返回 0 条 —— market:funding 没在跑，或它没采到', false);
            return 1;
        }

        $now  = (int)(microtime(true) * 1000);
        $ages = [];
        foreach ($rows as $r) {
            // 接口返回的字段名是 funding_rate_ts（见 inner_api Pair::fundingRates），不是 ts
            $ts = (int)($r['funding_rate_ts'] ?? 0);
            if ($ts > 0) $ages[(string)($r['pair'] ?? '?')] = (int)(($now - $ts) / 1000);
        }
        if (empty($ages)) {
            $this->line($output, '费率', '返回的行里没有 funding_rate_ts，无法判定', false);
            return 1;
        }
        arsort($ages);
        $worstKey = array_key_first($ages);
        $worst    = $ages[$worstKey];
        $fresh    = $worst <= self::FUNDING_STALE_SEC;
        $this->line($output, '费率', sprintf(
            '最旧 %s（%s）%s', $this->human($worst), $worstKey,
            $fresh ? '' : ' —— 超过 ' . self::FUNDING_STALE_SEC . 's，查 market:funding 是否在跑、交易所接口是否连续失败'
        ), $fresh);
        if (!$fresh) $ok = false;

        $output->writeln('');
        $output->writeln($ok ? '<info>inner_api 与费率数据均正常。</info>'
                             : '<error>有问题 —— 见上面标 ✗ 的那一行</error>');
        return $ok ? 0 : 1;
    }

    /** 秒 -> 人类可读，避免 "1234s" 这种要心算的输出 */
    private function human(int $sec): string
    {
        if ($sec < 60)   return $sec . 's';
        if ($sec < 3600) return sprintf('%dm%02ds', intdiv($sec, 60), $sec % 60);
        return sprintf('%dh%02dm', intdiv($sec, 3600), intdiv($sec % 3600, 60));
    }

    private function line(Output $output, string $key, string $val, bool $ok): void
    {
        $mark = $ok ? '<info>✓</info>' : '<error>✗</error>';
        $output->writeln(sprintf('  %s %-22s %s', $mark, $key, $val));
    }
}
