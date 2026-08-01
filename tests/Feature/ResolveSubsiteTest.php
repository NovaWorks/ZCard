<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\SubsiteDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ResolveSubsiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_site_resolves_null_subsite(): void
    {
        $resp = $this->getJson('/api/products');
        $resp->assertOk();
        $this->assertNull(request()->attributes->get('subsite'));
    }

    public function test_subsite_domain_resolves_merchant(): void
    {
        config(['zcard.features.sub_site' => true]);
        Cache::flush();

        $user = User::factory()->create();
        $merchant = Merchant::create([
            'user_id' => $user->id, 'name' => 'AliceShop', 'slug' => 'alice',
            'status' => 1, 'commission_rate' => 0,
            'settings' => ['is_subsite' => true],
        ]);
        SubsiteDomain::create([
            'merchant_id' => $merchant->id, 'domain' => 'alice.com',
            'type' => 'custom', 'verification_status' => 'verified',
            'status' => 'active', 'is_primary' => true, 'verified_at' => now(),
        ]);

        $resp = $this->withHeaders(['Host' => 'alice.com'])->getJson('/api/products');
        $resp->assertOk();
    }

    public function test_host_normalization_strips_port_and_www(): void
    {
        $middleware = new \App\Http\Middleware\ResolveSubsite();
        $ref = new \ReflectionMethod($middleware, 'normalizeHost');
        $ref->setAccessible(true);
        $this->assertSame('alice.com', $ref->invoke($middleware, 'WWW.Alice.com:8080'));
        $this->assertSame('alice.com', $ref->invoke($middleware, 'alice.com.'));
        $this->assertSame('alice.com', $ref->invoke($middleware, 'ALICE.COM'));
    }
}
