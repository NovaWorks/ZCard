<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StorefrontConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferralRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // register() 流程会 assignRole('user');RefreshDatabase 抹掉权限种子,需重建。
        Role::firstOrCreate(['name' => 'user']);
        StorefrontConfig::setMany(['captcha_register' => false, 'captcha_login' => false]);
    }

    public function test_register_with_referrer_binds_pid(): void
    {
        StorefrontConfig::setMany(['register_open' => true, 'username_min_length' => 3]);
        $referrer = User::factory()->create(['username' => 'alice']);

        $resp = $this->postJson('/api/auth/register', [
            'username' => 'bob',
            'email' => 'bob@x.com',
            'password' => 'secret123',
            'referrer' => 'alice',
        ]);

        $resp->assertCreated();
        $bob = User::where('username', 'bob')->first();
        $this->assertSame($referrer->id, $bob->pid);
    }

    public function test_self_referral_rejected(): void
    {
        StorefrontConfig::setMany(['register_open' => true, 'username_min_length' => 3]);

        $resp = $this->postJson('/api/auth/register', [
            'username' => 'carol',
            'email' => 'carol@x.com',
            'password' => 'secret123',
            'referrer' => 'carol', // self
        ]);

        $resp->assertCreated();
        $this->assertSame(0, User::where('username', 'carol')->first()->pid); // pid stays 0
    }

    public function test_unknown_referrer_ignored(): void
    {
        StorefrontConfig::setMany(['register_open' => true, 'username_min_length' => 3]);

        $resp = $this->postJson('/api/auth/register', [
            'username' => 'dave',
            'email' => 'dave@x.com',
            'password' => 'secret123',
            'referrer' => 'nobody', // 不存在
        ]);

        $resp->assertCreated();
        $this->assertSame(0, User::where('username', 'dave')->first()->pid);
    }

    public function test_no_referrer_pid_stays_zero(): void
    {
        StorefrontConfig::setMany(['register_open' => true, 'username_min_length' => 3]);

        $resp = $this->postJson('/api/auth/register', [
            'username' => 'erin',
            'email' => 'erin@x.com',
            'password' => 'secret123',
        ]);

        $resp->assertCreated();
        $this->assertSame(0, User::where('username', 'erin')->first()->pid);
    }
}
