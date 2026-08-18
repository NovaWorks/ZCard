<?php

namespace Tests\Unit;

use App\Payment\Contracts\Payable;
use App\Payment\Drivers\BEpusdtDriver;
use App\Payment\Drivers\EpuSdtDriver;
use App\Payment\Drivers\PaypalDriver;
use App\Payment\Drivers\StripeDriver;
use App\Payment\FiatAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

class FiatPaymentConversionTest extends TestCase
{
    private function payable(): Payable
    {
        return new class implements Payable
        {
            public function getPayableKey(): string
            {
                return 'ORD-FIAT-100';
            }

            public function getPayableAmount(): int
            {
                return 10000;
            }

            public function getPayableType(): string
            {
                return 'order';
            }
        };
    }

    private function conversionConfig(array $extra = []): array
    {
        return [
            'target_currency' => 'USD',
            'exchange_rate' => '0.14',
            ...$extra,
        ];
    }

    public function test_paypal_sends_and_reports_converted_target_amount(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'token-fiat-test',
                'expires_in' => 3600,
            ]),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-ORDER-1',
                'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
            ]),
        ]);

        $result = (new PaypalDriver)->pay($this->payable(), $this->conversionConfig([
            'client_id' => 'client-fiat-test',
            'client_secret' => 'secret',
            'mode' => 'sandbox',
        ]));

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v2/checkout/orders')) {
                return false;
            }
            $amount = $request->data()['purchase_units'][0]['amount'];

            return $amount['currency_code'] === 'USD' && $amount['value'] === '14.00';
        });
        $this->assertSame('USD', $result->currencySent);
        $this->assertSame(1400, $result->amountSent);
    }

    public function test_stripe_sends_and_reports_converted_target_amount(): void
    {
        $client = new class implements ClientInterface
        {
            public array $params = [];

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
            {
                $this->params = $params;

                return [json_encode([
                    'id' => 'cs_fiat_test',
                    'object' => 'checkout.session',
                    'url' => 'https://stripe.test/checkout',
                ]), 200, []];
            }
        };
        ApiRequestor::setHttpClient($client);

        try {
            $result = (new StripeDriver)->pay($this->payable(), $this->conversionConfig([
                'secret_key' => 'sk_test_fiat',
            ]));
        } finally {
            ApiRequestor::setHttpClient(CurlClient::instance());
        }

        $price = $client->params['line_items'][0]['price_data'];
        $this->assertSame('usd', $price['currency']);
        $this->assertSame(1400, $price['unit_amount']);
        $this->assertSame('USD', $result->currencySent);
        $this->assertSame(1400, $result->amountSent);
    }

    public function test_epusdt_sends_and_reports_converted_fiat_amount(): void
    {
        Http::fake(['epusdt.test/*' => Http::response([
            'status_code' => 200,
            'data' => ['payment_url' => 'https://epusdt.test/pay/1'],
        ])]);

        $result = (new EpuSdtDriver)->pay($this->payable(), $this->conversionConfig([
            'api_url' => 'https://epusdt.test',
            'pid' => '1001',
            'secret_key' => 'secret',
            'currency' => 'usd',
        ]));

        Http::assertSent(fn ($request) => (string) $request->data()['amount'] === '14.00'
            && $request->data()['currency'] === 'usd');
        $this->assertSame('USD', $result->currencySent);
        $this->assertSame(1400, $result->amountSent);
    }

    public function test_bepusdt_sends_and_reports_converted_fiat_amount(): void
    {
        Http::fake(['bepusdt.test/*' => Http::response([
            'status_code' => 200,
            'data' => ['payment_url' => 'https://bepusdt.test/pay/1'],
        ])]);

        $result = (new BEpusdtDriver)->pay($this->payable(), $this->conversionConfig([
            'api_url' => 'https://bepusdt.test',
            'api_token' => 'secret',
            'fiat' => 'USD',
        ]));

        Http::assertSent(fn ($request) => (float) $request->data()['amount'] === 14.0
            && $request->data()['fiat'] === 'USD');
        $this->assertSame('USD', $result->currencySent);
        $this->assertSame(1400, $result->amountSent);
    }

    public function test_fiat_amount_uses_currency_minor_units_and_decimal_rounding(): void
    {
        $this->assertSame(1400, FiatAmount::convertFromBase(10000, '0.14', 'USD'));
        $this->assertSame(14, FiatAmount::convertFromBase(10000, '0.14', 'JPY'));
        $this->assertSame(1001, FiatAmount::fromMajor('10.005', 'USD'));
        $this->assertSame('14', FiatAmount::formatMinor(14, 'JPY'));
        $this->assertNull(FiatAmount::fromMajor('not-a-number', 'USD'));
    }

    public function test_paypal_callback_normalizes_target_amount_and_rejects_currency_mismatch(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'token-fiat-callback',
                'expires_in' => 3600,
            ]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/*/capture' => Http::response([
                'id' => 'PAYPAL-CAPTURE-1',
                'status' => 'COMPLETED',
                'purchase_units' => [[
                    'reference_id' => 'ORD-FIAT-100',
                    'payments' => ['captures' => [[
                        'amount' => ['currency_code' => 'USD', 'value' => '14.00'],
                    ]]],
                ]],
            ]),
        ]);

        $config = $this->conversionConfig([
            'client_id' => 'client-fiat-callback',
            'client_secret' => 'secret',
            'mode' => 'sandbox',
        ]);
        $request = Request::create('/callback', 'POST', ['token' => 'PAYPAL12345']);

        $this->assertSame(1400, (new PaypalDriver)->verifyCallback($request, $config)['amount']);

        $config['target_currency'] = 'EUR';
        $this->assertNull((new PaypalDriver)->verifyCallback($request, $config));
    }

    public function test_stripe_callback_uses_target_minor_amount_and_rejects_currency_mismatch(): void
    {
        $payload = json_encode([
            'id' => 'evt_fiat_test',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_fiat_test',
                'object' => 'checkout.session',
                'payment_intent' => 'pi_fiat_test',
                'client_reference_id' => 'ORD-FIAT-100',
                'amount_total' => 1400,
                'currency' => 'usd',
            ]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = time();
        $secret = 'whsec_fiat_test';
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $request = Request::create('/callback', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);

        $config = $this->conversionConfig(['webhook_secret' => $secret]);
        $this->assertSame(1400, (new StripeDriver)->verifyCallback($request, $config)['amount']);

        $config['target_currency'] = 'EUR';
        $this->assertNull((new StripeDriver)->verifyCallback($request, $config));
    }

    public function test_epusdt_and_bepusdt_callbacks_normalize_the_configured_fiat(): void
    {
        $epu = new class extends EpuSdtDriver
        {
            public function signature(array $data, string $secret): string
            {
                return $this->sign($data, $secret);
            }
        };
        $epuData = [
            'status' => 2,
            'trade_id' => 'EPU-1',
            'order_id' => 'ORD-FIAT-100',
            'currency' => 'usd',
            'amount' => '14.00',
        ];
        $epuData['signature'] = $epu->signature($epuData, 'secret');
        $epuResult = $epu->verifyCallback(
            Request::create('/callback', 'POST', $epuData),
            $this->conversionConfig(['currency' => 'usd', 'secret_key' => 'secret'])
        );
        $this->assertSame(1400, $epuResult['amount']);

        $be = new class extends BEpusdtDriver
        {
            public function signature(array $data, string $secret): string
            {
                return $this->sign($data, $secret);
            }
        };
        $beData = [
            'status' => 2,
            'trade_id' => 'BE-1',
            'order_id' => 'ORD-FIAT-100',
            'fiat' => 'USD',
            'amount' => '14.00',
        ];
        $beData['signature'] = $be->signature($beData, 'secret');
        $beResult = $be->verifyCallback(
            Request::create('/callback', 'POST', $beData),
            $this->conversionConfig(['fiat' => 'USD', 'api_token' => 'secret'])
        );
        $this->assertSame(1400, $beResult['amount']);

        $beData['fiat'] = 'EUR';
        $beData['signature'] = $be->signature($beData, 'secret');
        $this->assertNull($be->verifyCallback(
            Request::create('/callback', 'POST', $beData),
            $this->conversionConfig(['fiat' => 'USD', 'api_token' => 'secret'])
        ));
    }
}
