<?php

declare(strict_types=1);

namespace app\inner_api\middleware;

use app\lib\DispatchAuth;
use Closure;
use think\facade\Config;
use think\Request;
use think\Response;

/**
 * 内部接口鉴权：/inner_api/* 仅供订阅方服务器调用，禁止公网直接访问
 *
 * 校验顺序：IP 白名单（如配置）→ 订阅方 token → 本机 .env 的共享 token
 *
 * 订阅方 token 在后台「订阅方管理」发放，命中即放行并记下该订阅方最后来源 IP（DispatchAuth::markSeen）。
 * .env 的 MARKET_INNER_API_TOKEN 不对应任何订阅方，留给本机巡检（market:inner_check）与还没换 token 的老订阅方；
 * 订阅方都换成自己的 token 之后，改掉它就能收回旧的共享 token。
 *
 * ── token 为什么必须 fail-closed ──────────────────────────────────────────
 * 原实现是「token 非空才校验」，而三份 config sample 又都给了一个**写死在仓库里**的
 * 默认值。两件事叠起来的结果是：任何读过本仓库的人都知道该带什么 X-Inner-Token，
 * 而运维完全没有感知 —— 接口看起来"有鉴权"，实际等于没有。
 *
 * 所以这里改成两条硬规则：
 *   1. token 没配（空）→ 拒绝。不配置不等于不需要，只等于还没配。
 *   2. token 等于仓库里那个历史默认值 → 按没配处理并拒绝。
 *      存量机器的 config/market.php 是 gitignore 的、不会随代码更新，把 sample 的默认值
 *      改成空串救不了它们；只有在这里认出这个值，升级后才会立刻暴露"你还在用公开密钥"。
 *      把已泄露的值写在这里不是新增泄露 —— 它本来就在仓库历史里。
 */
class InnerAuth
{
    /**
     * 曾经作为 config sample 默认值提交进仓库的 token。
     * 视同"未配置"，任何机器上出现它都拒绝并告警。
     */
    private const COMPROMISED_TOKENS = [
        'd9cc1aebefd19991b9722445d7b7067b9',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        $allowIps = array_filter(array_map('trim', explode(',', (string)Config::get('market.inner_api.allow_ips', ''))));
        if (!empty($allowIps) && !in_array($ip, $allowIps, true)) {
            business_log('inner_api', "rejected: ip {$ip} not in allow_ips, path=" . $request->pathinfo(), 'warning');
            return json(['code' => 0, 'msg' => 'forbidden'], 403);
        }

        $sent = (string)$request->header('x-inner-token', '');

        $sub = DispatchAuth::identify($sent);
        if ($sub !== null) {
            DispatchAuth::markSeen($sub['id'], DispatchAuth::SEEN_API, $ip);
            return $next($request);
        }

        $token = trim((string)Config::get('market.inner_api.token', ''));

        if ($token === '' || in_array($token, self::COMPROMISED_TOKENS, true)) {
            // 日志级别是 error 而不是 warning：这是配置事故，不是访问异常。
            // 每一次被拒都写一条 —— 内部接口的正常调用频率是分钟级，不存在刷盘风险，
            // 而"回补一直失败但没人知道为什么"比多几条日志糟得多。
            business_log('inner_api', sprintf(
                'rejected: 请求 token 不属于任何启用的订阅方，而 MARKET_INNER_API_TOKEN %s，只有订阅方 token 能用（ip=%s path=%s）。'
                . '订阅方 token 在后台「订阅方管理」发放；本机巡检要用的话在 root/.env 设置 MARKET_INNER_API_TOKEN（可用 openssl rand -hex 16 生成）。',
                $token === '' ? '未配置' : '仍是仓库公开的默认值',
                $ip, $request->pathinfo()
            ), 'error');
            return json(['code' => 0, 'msg' => 'forbidden'], 403);
        }

        if (!hash_equals($token, $sent)) {
            // 区分「压根没带头」和「带了但不对」：这两种的排查方向完全相反 ——
            // 前者九成是调用方没配 / 手工 curl 忘了带头（本机配置是好的），
            // 后者才是 A/B 两侧 token 不一致。日志不分开的话，
            // 「我明明配了怎么还是 403」只能靠猜。
            business_log('inner_api', sprintf(
                'rejected: ip %s %s, path=%s',
                $ip,
                $sent === '' ? '请求未携带 X-Inner-Token 头（本机 token 已正确配置）' : 'X-Inner-Token 既不属于任何启用的订阅方，也与本机 .env 不一致',
                $request->pathinfo()
            ), 'warning');
            return json(['code' => 0, 'msg' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
