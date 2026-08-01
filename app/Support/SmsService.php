<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 短信服务(#3):从 StorefrontConfig 读取阿里云 SMS 配置,通过 REST API 发送。
 * 零 SDK 依赖(直接调用阿里云 Dysmsapi HTTP 接口 + HMAC-SHA1 签名)。
 *
 * 配置(StorefrontConfig):
 * - sms_enabled: 开关
 * - sms_platform: 平台(当前仅 aliyun)
 * - sms_access_key / sms_access_secret: 阿里云 AccessKey
 * - sms_sign_name: 短信签名
 * - sms_template_code: 模板 CODE
 */
class SmsService
{
    private const ENDPOINT = 'https://dysmsapi.aliyuncs.com';

    /**
     * 发送短信验证码(找回密码/注册等)。
     * @param string $phone 手机号
     * @param string $code 验证码
     */
    public static function sendCaptchaSms(string $phone, string $code): void
    {
        self::send($phone, ['code' => $code], StorefrontConfig::get('sms_template_code'));
    }

    /**
     * 发送发货通知短信。
     * @param string $phone 手机号
     * @param array $params 模板变量(如 ['order_no' => 'ORD123'])
     */
    public static function sendDeliverySms(string $phone, array $params = []): void
    {
        self::send($phone, $params, StorefrontConfig::get('sms_delivery_template_code'));
    }

    /**
     * 通用发送(阿里云 Dysmsapi SendSms)。
     * @param string $phone 手机号
     * @param array $templateParam 模板变量 JSON
     * @param string|null $templateCode 模板 CODE(不传则用 sms_template_code)
     * @param array $templateParam 模板变量 JSON
     */
    public static function send(string $phone, array $templateParam = [], ?string $templateCode = null): void
    {
        if (! StorefrontConfig::get('sms_enabled')) {
            return;
        }

        $accessKey = StorefrontConfig::get('sms_access_key');
        $accessSecret = StorefrontConfig::get('sms_access_secret');
        $signName = StorefrontConfig::get('sms_sign_name');
        $templateCode = $templateCode ?: StorefrontConfig::get('sms_template_code');

        if (! $accessKey || ! $accessSecret || ! $signName || ! $templateCode) {
            Log::warning('短信发送跳过:阿里云 SMS 配置不完整');
            return;
        }

        try {
            $params = [
                'PhoneNumbers' => $phone,
                'SignName' => $signName,
                'TemplateCode' => $templateCode,
                'TemplateParam' => json_encode($templateParam ?: new \stdClass()),
                'AccessKeyId' => $accessKey,
                'SignatureMethod' => 'HMAC-SHA1',
                'SignatureVersion' => '1.0',
                'SignatureNonce' => uniqid(),
                'Timestamp' => now()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'),
                'Format' => 'JSON',
                'Action' => 'SendSms',
                'Version' => '2017-05-25',
                'RegionId' => 'cn-hangzhou',
            ];

            // 计算签名(阿里云 RPC 风格)
            $params['Signature'] = self::sign($params, $accessSecret);

            $response = Http::timeout(10)
                ->asForm()
                ->post(self::ENDPOINT . '/', $params);

            $body = $response->json();

            if (($body['Code'] ?? '') !== 'OK') {
                Log::warning('短信发送失败: ' . ($body['Message'] ?? '未知错误') . ' (Code: ' . ($body['Code'] ?? '?') . ')');
            }
        } catch (\Throwable $e) {
            Log::warning('短信发送异常: ' . $e->getMessage());
        }
    }

    /**
     * 阿里云 RPC 签名计算(HMAC-SHA1 + Base64)。
     */
    private static function sign(array $params, string $accessSecret): string
    {
        ksort($params);

        $canonicalized = '';
        foreach ($params as $key => $value) {
            $canonicalized .= '&' . self::encode($key) . '=' . self::encode($value);
        }
        $stringToSign = 'POST&' . self::encode('/') . '&' . self::encode(ltrim($canonicalized, '&'));

        return base64_encode(hash_hmac('sha1', $stringToSign, $accessSecret . '&', true));
    }

    /**
     * 阿里云 URL 编码(RFC 3986 变体)。
     */
    private static function encode(string $str): string
    {
        $encoded = urlencode($str);
        return str_replace(['+', '*'], ['%20', '%2A'], $encoded);
    }
}
