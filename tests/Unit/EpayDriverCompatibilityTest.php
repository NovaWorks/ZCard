<?php

namespace Tests\Unit;

use App\Payment\Contracts\Payable;
use App\Payment\Drivers\EpayDriver;
use Illuminate\Http\Request;
use Tests\TestCase;

class EpayDriverCompatibilityTest extends TestCase
{
    public function test_md5_callback_accepts_trade_finished_and_validates_pid(): void
    {
        $driver = new EpayDriver;
        $config = ['pid' => '1001', 'key' => 'secret-001', 'sign_type' => 'MD5'];
        $params = [
            'pid' => '1001',
            'out_trade_no' => 'ORD1001',
            'trade_no' => 'EPAY2001',
            'money' => '21.00',
            'trade_status' => 'TRADE_FINISHED',
        ];
        $params['sign_type'] = 'MD5';
        $params['sign'] = md5($this->signContent($params).$config['key']);

        $result = $driver->verifyCallback(
            Request::create('/api/payments/notify/epay', 'GET', $params),
            $config,
        );

        $this->assertNotNull($result);
        $this->assertSame('ORD1001', $result['out_trade_no']);
        $this->assertSame(2100, $result['amount']);

        $params['pid'] = '1002';
        $params['sign'] = md5($this->signContent($params).$config['key']);
        $this->assertNull($driver->verifyCallback(
            Request::create('/api/payments/notify/epay', 'POST', $params),
            $config,
        ));
    }

    public function test_rsa2_callback_accepts_escaped_and_bare_x509_public_key(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        $params = [
            'pid' => '2001',
            'out_trade_no' => 'ORD2001',
            'trade_no' => 'EPAY3001',
            'money' => '146.66',
            'trade_status' => 'TRADE_SUCCESS',
            'timestamp' => '1786449600',
            'sign_type' => 'RSA2',
        ];
        openssl_sign($this->signContent($params), $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $params['sign'] = base64_encode($signature);

        foreach ([str_replace("\n", '\\n', $publicKey), $this->keyBody($publicKey)] as $configuredPublicKey) {
            $result = (new EpayDriver)->verifyCallback(
                Request::create('/api/payments/notify/epay', 'POST', $params),
                [
                    'pid' => '2001',
                    'sign_type' => 'RSA2',
                    'platform_public_key' => $configuredPublicKey,
                ],
            );

            $this->assertNotNull($result);
            $this->assertSame(14666, $result['amount']);
        }
    }

    public function test_rsa2_payment_accepts_bare_pkcs8_private_key(): void
    {
        [$privateKey, $publicKey] = $this->keyPair();
        app()->instance('request', Request::create('https://kmigo.com/checkout', 'GET'));

        foreach ([$this->keyBody($privateKey), str_replace("\n", '\\n', $privateKey)] as $configuredPrivateKey) {
            $result = (new EpayDriver)->pay($this->payable(), [
                'pid' => '2001',
                'key' => $configuredPrivateKey,
                'platform_public_key' => $publicKey,
                'url' => 'https://pay.example',
                'sign_type' => 'RSA2',
                'type' => ['alipay'],
            ]);

            parse_str((string) parse_url($result->redirectUrl, PHP_URL_QUERY), $query);
            $this->assertSame('RSA2', $query['sign_type']);
            $this->assertSame('https://kmigo.com/api/payments/callback/epay', $query['notify_url']);
            $this->assertNotEmpty($query['sign']);
        }
    }

    public function test_standard_payment_matches_acg_faka_md5_contract_and_normalizes_gateway(): void
    {
        app()->instance('request', Request::create('https://shop.example/checkout', 'GET'));

        $result = (new EpayDriver)->pay($this->payable(), [
            'url' => 'https://pay.example/submit.php',
            'pid' => '1001',
            'key' => 'merchant-secret',
            'type' => ['alipay', 'wxpay'],
            '_selected_pay_type' => 'wxpay',
        ]);

        $this->assertStringStartsWith('https://pay.example/submit.php?', (string) $result->redirectUrl);
        parse_str((string) parse_url($result->redirectUrl, PHP_URL_QUERY), $query);
        $this->assertSame('wxpay', $query['type']);
        $this->assertSame('MD5', $query['sign_type']);
        $this->assertSame((string) config('app.name'), $query['sitename']);
        $this->assertSame(
            md5($this->signContent($query).'merchant-secret'),
            $query['sign'],
        );
    }

    public function test_md5_callback_uses_saved_algorithm_and_accepts_json_without_pid(): void
    {
        $params = [
            'out_trade_no' => 'ORDJSON1001',
            'trade_no' => 'EPAYJSON1001',
            'money' => '21.00',
            'trade_status' => 'TRADE_SUCCESS',
            // 部分易支付程序的标识与实际 MD5 签名不一致,不得用它覆盖服务端配置。
            'sign_type' => 'RSA2',
        ];
        $params['sign'] = md5($this->signContent($params).'secret-001');
        $request = Request::create(
            '/api/payments/notify/epay',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($params, JSON_THROW_ON_ERROR),
        );

        $result = (new EpayDriver)->verifyCallback($request, [
            'pid' => '1001',
            'key' => 'secret-001',
            'sign_type' => 'MD5',
        ]);

        $this->assertNotNull($result);
        $this->assertSame(2100, $result['amount']);
    }

    public function test_standard_config_only_exposes_acg_faka_credentials_and_payment_types(): void
    {
        $fields = (new EpayDriver)->getConfigFields();

        $this->assertSame(['url', 'pid', 'key', 'type'], array_keys(array_filter(
            $fields,
            fn (array $field) => empty($field['legacy']),
        )));
        $this->assertArrayNotHasKey('notify_domain', $fields);
        $this->assertArrayNotHasKey('target_currency', $fields);
        $this->assertArrayNotHasKey('exchange_rate', $fields);
    }

    /** @return array{0: string, 1: string} */
    private function keyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($resource);
        $this->assertTrue(openssl_pkey_export($resource, $privateKey));
        $details = openssl_pkey_get_details($resource);
        $this->assertIsArray($details);

        return [$privateKey, $details['key']];
    }

    private function keyBody(string $pem): string
    {
        return preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem) ?? '';
    }

    private function signContent(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, fn ($value) => $value !== '' && $value !== null);
        ksort($params);

        return implode('&', array_map(
            fn ($key, $value) => $key.'='.$value,
            array_keys($params),
            $params,
        ));
    }

    private function payable(): Payable
    {
        return new class implements Payable
        {
            public function getPayableKey(): string
            {
                return 'ORDRSA2001';
            }

            public function getPayableAmount(): int
            {
                return 2100;
            }

            public function getPayableType(): string
            {
                return 'order';
            }
        };
    }
}
