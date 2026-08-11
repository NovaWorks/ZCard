<?php

namespace Tests\Unit;

use App\Payment\PaymentUrlGenerator;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentUrlGeneratorTest extends TestCase
{
    public function test_uses_current_request_origin_and_notify_route(): void
    {
        app()->instance('request', Request::create('https://shop.example/admin/payment', 'GET'));

        $url = app(PaymentUrlGenerator::class)->named('payment.notify', ['channel' => 'epay']);

        $this->assertSame('https://shop.example/api/payments/notify/epay', $url);
    }

    public function test_notify_domain_overrides_current_request_origin(): void
    {
        app()->instance('request', Request::create('https://admin.example/payment', 'GET'));

        $url = app(PaymentUrlGenerator::class)->named(
            'payment.notify',
            ['channel' => 'epay'],
            ['notify_domain' => 'https://callback.example/'],
        );

        $this->assertSame('https://callback.example/api/payments/notify/epay', $url);
    }
}
