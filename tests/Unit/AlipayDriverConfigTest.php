<?php

namespace Tests\Unit;

use App\Payment\Drivers\AlipayDriver;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * 支付宝原生公钥模式(RSA2):签名-验签闭环、回调解析、支付方式映射。
 * 回归:加签方式用「密钥」(应用私钥+支付宝公钥),无需证书。
 */
class AlipayDriverConfigTest extends TestCase
{
    private function keyPair(): array
    {
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privateKey);
        $pub = openssl_pkey_get_details($res);

        return [$privateKey, $pub['key']];
    }

    public function test_verify_callback_validates_rsa2_signature(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $driver = new AlipayDriver;

        $params = [
            'app_id' => '2021000000000000',
            'trade_no' => '2026081022000000000000000000',
            'out_trade_no' => 'ORDTEST001',
            'total_amount' => '10.00',
            'trade_status' => 'TRADE_SUCCESS',
            'notify_time' => '2026-08-10 12:00:00',
        ];
        $params['sign_type'] = 'RSA2';
        // 官方签名规则:sign 与 sign_type 均不参与签名内容
        $signParams = $params;
        unset($signParams['sign_type']);
        ksort($signParams);
        $content = '';
        foreach ($signParams as $k => $v) {
            if ($v === '') {
                continue;
            }
            $content .= $k.'='.$v.'&';
        }
        $content = rtrim($content, '&');
        openssl_sign($content, $sign, $privateKey, OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($sign);

        $result = $driver->verifyCallback(Request::create('/api/payments/callback/alipay', 'POST', $params), [
            'alipay_public_key' => $publicKey,
        ]);

        $this->assertNotNull($result);
        $this->assertSame('ORDTEST001', $result['out_trade_no']);
        $this->assertSame(1000, $result['amount']);
    }

    public function test_verify_callback_rejects_bad_signature(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $driver = new AlipayDriver;

        $params = [
            'app_id' => 'x',
            'out_trade_no' => 'ORDX',
            'total_amount' => '1.00',
            'trade_status' => 'TRADE_SUCCESS',
        ];
        openssl_sign('tampered-content', $sign, $privateKey, OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($sign);

        $result = $driver->verifyCallback(Request::create('/api/payments/callback/alipay', 'POST', $params), [
            'alipay_public_key' => $publicKey,
        ]);

        $this->assertNull($result);
    }

    public function test_verify_callback_rejects_non_success_status(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $driver = new AlipayDriver;

        $params = [
            'app_id' => 'x',
            'out_trade_no' => 'ORDX',
            'total_amount' => '1.00',
            'trade_status' => 'WAIT_BUYER_PAY',
        ];
        ksort($params);
        $content = '';
        foreach ($params as $k => $v) {
            $content .= $k.'='.$v.'&';
        }
        openssl_sign(rtrim($content, '&'), $sign, $privateKey, OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($sign);

        $result = $driver->verifyCallback(Request::create('/api/payments/callback/alipay', 'POST', $params), [
            'alipay_public_key' => $publicKey,
        ]);

        $this->assertNull($result);
    }

    public function test_pay_types_returns_configured_type(): void
    {
        $driver = new AlipayDriver;
        $this->assertSame(['scan'], $driver->getPayTypes([]));
        $this->assertSame(['web'], $driver->getPayTypes(['pay_type' => 'web']));
        $this->assertSame(['scan'], $driver->getPayTypes(['pay_type' => 'scan']));
    }
}
