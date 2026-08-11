<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\SecurityAuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Payment\Drivers\StripeDriver;
use App\Support\DomainVerificationService;
use App\Support\HtmlContentSanitizer;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'merchant', 'user'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
    }

    private function tokenFor(string $role, array $attributes = []): array
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return [$user, $user->createToken('security-test')->plainTextToken];
    }

    public function test_ordinary_user_cannot_access_legacy_card_management_routes(): void
    {
        [, $token] = $this->tokenFor('user');

        $this->withToken($token)->getJson('/api/cards')->assertStatus(403);
        $this->withToken($token)->get('/api/cards/export/1')->assertStatus(403);
        $this->withToken($token)->postJson('/api/cards/import', [
            'product_id' => 1,
            'content' => 'SECRET-CARD',
        ])->assertStatus(403);
    }

    public function test_disabled_user_cannot_reuse_existing_token(): void
    {
        [$user, $token] = $this->tokenFor('user');
        $user->update(['status' => 0]);

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(403);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_password_change_revokes_all_existing_tokens(): void
    {
        [$user, $token] = $this->tokenFor('user');
        $user->createToken('second-device');

        $this->withToken($token)->putJson('/api/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_storefront_settings_are_whitelisted_and_secrets_are_encrypted(): void
    {
        StorefrontConfig::setMany([
            'mail_username' => 'mailer@example.com',
            'mail_password' => 'smtp-secret',
            'sms_access_key' => 'sms-key',
            'sms_access_secret' => 'sms-secret',
            'footer_analytics' => '<script>alert(1)</script>',
            'site_notice' => '<p>公告</p><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>',
            'footer_links' => [['title' => '危险', 'url' => 'javascript:alert(1)']],
        ]);

        $stored = Setting::where('key', 'mail_password')->firstOrFail()->value;
        $this->assertNotSame('smtp-secret', $stored);
        $this->assertSame('smtp-secret', StorefrontConfig::get('mail_password'));

        $response = $this->getJson('/api/settings/storefront')->assertOk();
        $response->assertJsonMissingPath('mail_username')
            ->assertJsonMissingPath('mail_password')
            ->assertJsonMissingPath('sms_access_key')
            ->assertJsonMissingPath('sms_access_secret')
            ->assertJsonMissingPath('footer_analytics');
        $this->assertStringNotContainsString('onerror', $response->json('site_notice'));
        $this->assertStringNotContainsString('javascript:', $response->json('site_notice'));
        $this->assertSame('', $response->json('footer_links.0.url'));
    }

    public function test_payment_config_is_encrypted_and_admin_api_masks_secrets(): void
    {
        [$admin, $token] = $this->tokenFor('super_admin');
        $merchant = Merchant::first() ?? Merchant::create([
            'user_id' => $admin->id,
            'name' => '主站',
            'slug' => 'main-security',
            'status' => 1,
            'settings' => [],
        ]);
        $channel = PaymentChannel::updateOrCreate(
            ['merchant_id' => $merchant->id, 'code' => 'security-stripe'],
            [
                'name' => 'Stripe', 'driver' => StripeDriver::class,
                'config' => ['secret_key' => 'sk_live_secret', 'webhook_secret' => 'whsec_secret'],
                'fee' => 0, 'fee_type' => 'percent', 'sort' => 99, 'enabled' => false,
            ],
        );

        $raw = (string) DB::table('payment_channels')->where('id', $channel->id)->value('config');
        $this->assertStringNotContainsString('sk_live_secret', $raw);
        $this->assertSame('sk_live_secret', $channel->fresh()->config['secret_key']);

        $row = collect($this->withToken($token)->getJson('/api/admin/payment-channels')->assertOk()->json())
            ->firstWhere('id', $channel->id);
        $this->assertSame(StorefrontConfig::SECRET_MASK, $row['config']['secret_key']);
        $this->assertSame(StorefrontConfig::SECRET_MASK, $row['config']['webhook_secret']);
    }

    public function test_product_update_records_actor_and_change_diff(): void
    {
        [$admin, $token] = $this->tokenFor('super_admin');
        $merchant = Merchant::first() ?? Merchant::create([
            'user_id' => $admin->id, 'name' => '主站', 'slug' => 'main-audit',
            'status' => 1, 'settings' => [],
        ]);
        $product = Product::create([
            'merchant_id' => $merchant->id, 'name' => '旧名称', 'slug' => 'audit-product',
            'price' => 100, 'factory_price' => 100, 'stock_type' => 'card', 'status' => 1,
        ]);

        $this->withToken($token)->putJson('/api/admin/products/'.$product->id, [
            'name' => '新名称',
        ])->assertOk();

        $audit = SecurityAuditLog::where('action', 'product.updated')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('旧名称', $audit->metadata['changes']['name']['before']);
        $this->assertSame('新名称', $audit->metadata['changes']['name']['after']);
    }

    public function test_html_sanitizer_and_security_headers_block_active_content(): void
    {
        $html = HtmlContentSanitizer::sanitize(
            '<p onclick="alert(1)">ok</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>',
        );
        $this->assertStringContainsString('<p>ok</p>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        $this->getJson('/api/health')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_private_or_malformed_domains_are_rejected_before_http_request(): void
    {
        $this->assertFalse(DomainVerificationService::isSafePublicDomain('127.0.0.1'));
        $this->assertFalse(DomainVerificationService::isSafePublicDomain('localhost'));
        $this->assertFalse(DomainVerificationService::isSafePublicDomain('https://example.com/path'));
    }

    public function test_installed_system_disables_database_probe(): void
    {
        $this->postJson('/api/install/test-db', [
            'host' => '127.0.0.1', 'port' => 3306, 'database' => 'x',
            'username' => 'x', 'password' => 'x',
        ])->assertStatus(403);
    }
}
