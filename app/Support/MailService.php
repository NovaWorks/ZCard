<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 邮件服务:动态配置 SMTP + 发送通知邮件。
 * 从 StorefrontConfig 读取 SMTP 配置,运行时覆盖 Laravel mail 配置。
 */
class MailService
{
    /**
     * 运行时配置 SMTP(从店铺设置读取)。
     */
    public static function configure(): void
    {
        if (! StorefrontConfig::get('mail_enabled')) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => StorefrontConfig::get('mail_host'),
                'port' => (int) StorefrontConfig::get('mail_port'),
                'encryption' => StorefrontConfig::get('mail_encryption') ?: 'ssl',
                'username' => StorefrontConfig::get('mail_username'),
                'password' => StorefrontConfig::get('mail_password'),
                'timeout' => 15,
            ],
            'mail.from.address' => StorefrontConfig::get('mail_from_address') ?: StorefrontConfig::get('mail_username'),
            'mail.from.name' => StorefrontConfig::get('mail_from_name') ?: 'ZCard',
        ]);
    }

    /**
     * 发送发卡通知邮件。
     */
    public static function sendDeliveryNotification(string $toEmail, array $data): void
    {
        if (! StorefrontConfig::get('mail_enabled')) {
            return;
        }

        self::configure();

        try {
            Mail::raw(
                self::buildDeliveryBody($data),
                function ($message) use ($toEmail, $data) {
                    $message->to($toEmail)
                        ->subject('【'.(StorefrontConfig::get('site_name') ?: 'ZCard').'】订单 '.($data['order_no'] ?? '').' 发货通知');
                }
            );
        } catch (\Throwable $e) {
            Log::warning('邮件发送失败: '.$e->getMessage());
        }
    }

    /**
     * 发送验证码邮件(找回密码)。
     */
    public static function sendCaptchaEmail(string $toEmail, string $code): void
    {
        if (! StorefrontConfig::get('mail_enabled')) {
            throw new \RuntimeException(__('messages.mail.disabled'));
        }

        self::configure();

        Mail::raw(
            "您正在进行身份验证,验证码为:{$code}\n\n验证码有效期为 5 分钟,请尽快使用。\n如非本人操作,请忽略此邮件。",
            function ($message) use ($toEmail) {
                $message->to($toEmail)
                    ->subject('【'.(StorefrontConfig::get('site_name') ?: 'ZCard').'】邮箱验证码');
            }
        );
    }

    /**
     * 构建发卡通知邮件正文。
     */
    private static function buildDeliveryBody(array $data): string
    {
        $siteName = StorefrontConfig::get('site_name') ?: 'ZCard';
        $body = "亲爱的客户,您的订单已完成发货!\n\n";
        $body .= "订单号:{$data['order_no']}\n";
        $body .= "商品:{$data['product_name']}\n";
        $body .= "数量:{$data['quantity']}\n\n";
        $body .= "===== 发货内容 =====\n";
        foreach (($data['cards'] ?? []) as $i => $card) {
            $body .= ($i + 1).". {$card}\n";
        }
        $body .= "===================\n\n";
        $instructions = trim((string) ($data['instructions'] ?? ''));
        if ($instructions !== '') {
            $instructions = trim(html_entity_decode(strip_tags($instructions), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($instructions !== '') {
                $body .= "===== 使用说明 =====\n{$instructions}\n===================\n\n";
            }
        }
        $body .= "感谢您的惠顾! {$siteName}";

        return $body;
    }
}
