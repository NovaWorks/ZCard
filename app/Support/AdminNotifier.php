<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * 管理员告警通知(issue #6)。
 * 渠道:邮件(SMTP,复用 MailService)/ Telegram Bot / 企业微信群机器人 Webhook。
 * 各渠道独立开关:配置非空即启用;总开关 admin_alert_enabled。
 * 失败静默,不抛出(告警不应影响主流程)。
 */
class AdminNotifier
{
    public static function send(array $channels, string $subject, string $content): void
    {
        if (! StorefrontConfig::get('admin_alert_enabled', false)) {
            return;
        }

        foreach ($channels as $channel) {
            try {
                match ($channel) {
                    'email' => self::sendEmail($subject, $content),
                    'telegram' => self::sendTelegram($content),
                    'wecom' => self::sendWecom($content),
                    default => null,
                };
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** 当前启用的渠道列表(后台配置非空即启用) */
    public static function activeChannels(): array
    {
        $channels = [];
        $email = (string) StorefrontConfig::get('admin_alert_email', '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $channels[] = 'email';
        }
        if ((string) StorefrontConfig::get('admin_alert_tg_token', '') !== ''
            && (string) StorefrontConfig::get('admin_alert_tg_chat_id', '') !== '') {
            $channels[] = 'telegram';
        }
        if ((string) StorefrontConfig::get('admin_alert_wecom_webhook', '') !== '') {
            $channels[] = 'wecom';
        }

        return $channels;
    }

    private static function sendEmail(string $subject, string $content): void
    {
        $to = (string) StorefrontConfig::get('admin_alert_email', '');
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        MailService::send($to, $subject, $content);
    }

    private static function sendTelegram(string $content): void
    {
        $token = (string) StorefrontConfig::get('admin_alert_tg_token', '');
        $chatId = (string) StorefrontConfig::get('admin_alert_tg_chat_id', '');
        if ($token === '' || $chatId === '') {
            return;
        }

        $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $content,
            'parse_mode' => 'HTML',
        ]);
        if ($response->failed()) {
            report(new \RuntimeException('Telegram 告警发送失败: '.$response->body()));
        }
    }

    private static function sendWecom(string $content): void
    {
        $webhook = (string) StorefrontConfig::get('admin_alert_wecom_webhook', '');
        if ($webhook === '') {
            return;
        }

        $response = Http::timeout(10)->post($webhook, [
            'msgtype' => 'text',
            'text' => ['content' => $content],
        ]);
        if ($response->failed()) {
            report(new \RuntimeException('企业微信告警发送失败: '.$response->body()));
        }
    }
}
