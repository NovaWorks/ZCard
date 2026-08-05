<?php

namespace Tests\Unit;

use App\Payment\Contracts\Payable;
use App\Payment\Drivers\OkPayDriver;
use App\Payment\Drivers\TokenPayDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OKPay / TokenPay 虚拟货币支付驱动测试。
 * 签名算法对照 dujiao-next 参考实现(internal/modules/payment/infrastructure/gateway)。
 */
class VirtualCoinDriverTest extends TestCase
{
    private function fakeOrder(string $no = 'ORD-1'): Payable
    {
        return new class($no) implements Payable
        {
            public function __construct(private string $no) {}

            public function getPayableKey(): string
            {
                return $this->no;
            }

            public function getPayableAmount(): int
            {
                return 1000;
            } // 10.00 元

            public function getPayableType(): string
            {
                return 'order';
            }
        };
    }

    public function test_okpay_sign_matches_reference(): void
    {
        $driver = new OkPayDriver;
        $method = new \ReflectionMethod($driver, 'sign');
        $sig = $method->invoke($driver, [
            'unique_id' => 'O1',
            'amount' => '0.14000000',
            'coin' => 'USDT',
            'id' => '12345',
        ], 'secret_token');
        // 参考实现:排序 key=value&... + "&token=" + token → md5 大写
        $expected = strtoupper(md5('amount=0.14000000&coin=USDT&id=12345&unique_id=O1&token=secret_token'));
        $this->assertSame($expected, $sig);
    }

    public function test_tokenpay_sign_matches_reference(): void
    {
        $driver = new TokenPayDriver;
        $method = new \ReflectionMethod($driver, 'sign');
        $sig = $method->invoke($driver, [
            'OutOrderId' => 'O1',
            'OrderUserKey' => 'U1',
            'ActualAmount' => '1.5',
            'Currency' => 'USDT',
        ], 'secret');
        // 参考实现:排序 key=value&... + secret(直接拼接) → md5 小写
        $expected = md5('ActualAmount=1.5&Currency=USDT&OrderUserKey=U1&OutOrderId=O1secret');
        $this->assertSame($expected, $sig);
    }

    public function test_okpay_pay_redirects_to_paylink(): void
    {
        Http::fake([
            'api.okaypay.me/*' => Http::response([
                'code' => 200,
                'msg' => 'success',
                'data' => ['order_id' => 'OK-1', 'pay_url' => 'https://pay.okaypay.me/x'],
            ]),
        ]);

        $driver = new OkPayDriver;
        $result = $driver->pay($this->fakeOrder(), [
            'merchant_id' => '12345',
            'merchant_token' => 'token',
            'exchange_rate' => '0.14',
            'coin' => 'USDT',
        ]);

        $this->assertSame('redirect', $result->type);
        $this->assertSame('https://pay.okaypay.me/x', $result->redirectUrl);

        // 断言请求带签名
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['unique_id'] === 'ORD-1'
                && $data['coin'] === 'USDT'
                && ! empty($data['sign'])
                && str_contains($request->url(), '/payLink');
        });
    }

    public function test_okpay_callback_verify(): void
    {
        $driver = new OkPayDriver;
        // 回调 form 编码:data 子数组会由 PHP 解析成嵌套数组(模拟真实请求)
        $data = [
            'id' => '12345',
            'status' => 'success',
            'data' => ['order_id' => 'OK-1', 'unique_id' => 'ORD-1', 'amount' => '1.4', 'coin' => 'USDT', 'status' => '1'],
        ];
        // 签名:data 展开成 data[xxx] 扁平键 + ksort + &token=token → md5 大写
        $flat = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $flat["{$key}[{$subKey}]"] = $subValue;
                }
            } else {
                $flat[$key] = $value;
            }
        }
        ksort($flat);
        $query = implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($flat), $flat));
        $data['sign'] = strtoupper(md5($query.'&token=token'));

        $request = Request::create('/api/payments/callback/okpay', 'POST', $data);
        $result = $driver->verifyCallback($request, ['merchant_token' => 'token', 'exchange_rate' => '0.14']);

        $this->assertNotNull($result);
        $this->assertSame('ORD-1', $result['out_trade_no']);
        // 1.4 USDT × 0.14 汇率 × 100 = 19.6 → 20 分(四舍五入)
        $this->assertSame(20, $result['amount']);
    }

    public function test_tokenpay_pay_redirects(): void
    {
        Http::fake([
            'tokenpay.test/*' => Http::response([
                'success' => true,
                'data' => 'https://pay.tokenpay.test/abc',
                'info' => ['Id' => 'TP-1'],
            ]),
        ]);

        $driver = new TokenPayDriver;
        $result = $driver->pay($this->fakeOrder(), [
            'gateway_url' => 'https://tokenpay.test',
            'notify_secret' => 'secret',
            'currency' => 'USDT',
            'exchange_rate' => '0.14',
        ]);

        $this->assertSame('redirect', $result->type);
        $this->assertSame('https://pay.tokenpay.test/abc', $result->redirectUrl);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $payload['OutOrderId'] === 'ORD-1'
                && str_contains($request->url(), '/CreateOrder')
                && ! empty($payload['Signature']);
        });
    }

    public function test_tokenpay_callback_verify(): void
    {
        $driver = new TokenPayDriver;
        $raw = [
            'Id' => 'TP-1',
            'OutOrderId' => 'ORD-1',
            'OrderUserKey' => 'ORD-1',
            'Status' => 1,
            'ActualAmount' => '1.4',
            'Currency' => 'USDT',
        ];
        $method = new \ReflectionMethod($driver, 'sign');
        $raw['Signature'] = $method->invoke($driver, $raw, 'secret');

        $request = Request::create('/api/payments/callback/tokenpay', 'POST', [], [], [], [], json_encode($raw));
        $request->headers->set('Content-Type', 'application/json');
        $result = $driver->verifyCallback($request, ['notify_secret' => 'secret', 'exchange_rate' => '0.14']);

        $this->assertNotNull($result);
        $this->assertSame('ORD-1', $result['out_trade_no']);
        $this->assertSame(20, $result['amount']); // 1.4 × 0.14 × 100 = 19.6 → 20
    }
}
