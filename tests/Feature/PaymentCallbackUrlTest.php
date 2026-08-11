<?php

namespace Tests\Feature;

use App\Payment\PaymentUrlGenerator;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentCallbackUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_callback_domain_overrides_internal_request_origin(): void
    {
        StorefrontConfig::setMany(['payment_callback_domain' => 'https://callback.example/']);
        app()->instance('request', Request::create('http://localhost:8092/admin/payment', 'GET'));

        $url = app(PaymentUrlGenerator::class)->named('api.payments.callback', ['channel' => 'epay']);

        $this->assertSame('https://callback.example/api/payments/callback/epay', $url);
    }

    public function test_site_url_replaces_localhost_when_callback_domain_is_empty(): void
    {
        StorefrontConfig::setMany([
            'payment_callback_domain' => '',
            'site_url' => 'https://shop.example',
        ]);
        app()->instance('request', Request::create('http://localhost:8092/admin/payment', 'GET'));

        $url = app(PaymentUrlGenerator::class)->named('api.payments.callback', ['channel' => 'epay']);

        $this->assertSame('https://shop.example/api/payments/callback/epay', $url);
    }
}
