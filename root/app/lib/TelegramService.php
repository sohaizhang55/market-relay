<?php

declare(strict_types=1);

namespace app\lib;

use think\admin\extend\HttpExtend;

/**
 * Telegram 通知服务
 * Class TelegramService
 * @package app\lib
 */
class TelegramService
{
    /**
     * @var string 记录最后使用的 Chat ID
     */
    public static $usedChatId = '';

    /**
     * 发送 Telegram 消息
     * @param string $text 消息内容
     * @param string|null $chatId 接收者 ID (不传则使用默认配置)
     * @return bool
     */
    public static function sendMessage(string $text, ?string $chatId = null): bool
    {
        $botToken = sysconf('telegram.telegram_bot_token');
        $chatId = $chatId ?: sysconf('telegram.telegram_chat_id');

        if (empty($botToken) || empty($chatId)) {
            business_log('alert_channel', 'Telegram 未配置 bot_token / chat_id —— 所有告警都发不出去', 'error');
            return false;
        }

        self::$usedChatId = $chatId;
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        // 先按 Markdown 发。失败且原因是实体解析时，**降级成纯文本重发一次**。
        //
        // ⚠️ 为什么需要这个降级：告警正文里全是进程名、source_key、文件路径，
        // 而它们含大量下划线（market_relay_MarketDbFlusher、BINANCE_btcusdt_1）。
        // Telegram 旧版 Markdown 把 `_` 当斜体定界符，个数为奇数时整条消息
        // 直接被拒（ok:false, "can't parse entities"）——
        // 也就是说**告警能不能送达，取决于正文里下划线的奇偶**。
        // 内容比格式重要：宁可丢掉加粗，也不能丢掉这条告警。
        $res = self::post($url, $chatId, $text, 'Markdown');
        if (!($res['ok'] ?? false) && self::isEntityError($res)) {
            business_log('alert_channel', sprintf(
                'Telegram 拒绝了 Markdown 实体（%s），已降级为纯文本重发 —— '
                . '告警正文含下划线/星号时会这样，内容不受影响',
                (string)($res['description'] ?? '')
            ), 'warning');
            $res = self::post($url, $chatId, $text, null);
        }

        $ok = (bool)($res['ok'] ?? false);

        if (!$ok) {
            // ⚠️ 这一条必须是 error 且走 business_log。
            //
            // 原来这里只把响应写进框架日志的 info 级，返回值又被所有调用方丢弃 ——
            // 于是「告警发不出去」本身**没有任何告警**：Telegram 可以连着一周全失败，
            // 而系统看起来一切正常。监控系统自己是最不该有盲区的地方。
            business_log('alert_channel', sprintf(
                'Telegram 发送失败：%s —— 告警送不达，请检查 bot_token / chat_id / 出网',
                (string)($res['description'] ?? 'no response')
            ), 'error');
        }
        return $ok;
    }

    /** @return array 解析后的响应；解析不出来时返回空数组（等价于失败） */
    private static function post(string $url, string $chatId, string $text, ?string $parseMode): array
    {
        $data = ['chat_id' => $chatId, 'text' => $text];
        if ($parseMode !== null) $data['parse_mode'] = $parseMode;

        $ret = HttpExtend::post($url, $data, self::getHttpOptions());
        $res = json_decode((string)$ret, true);
        return is_array($res) ? $res : [];
    }

    /** Telegram 因为 Markdown/HTML 实体不合法而拒绝，而不是网络或鉴权问题 */
    private static function isEntityError(array $res): bool
    {
        $d = strtolower((string)($res['description'] ?? ''));
        return $d !== '' && (str_contains($d, 'parse entities') || str_contains($d, 'entity'));
    }

    /**
     * 获取 HTTP 请求配置 (SSL 验证控制)
     * @return array
     */
    private static function getHttpOptions(): array
    {
        // 1=开启验证, 2=关闭验证 (默认不验证，以防旧环境报错)
        $verify = intval(sysconf('telegram.telegram_ssl_verify') ?: 2);
        return [
            CURLOPT_SSL_VERIFYPEER => $verify === 1,
            CURLOPT_SSL_VERIFYHOST => $verify === 1 ? 2 : 0,
        ];
    }
}
