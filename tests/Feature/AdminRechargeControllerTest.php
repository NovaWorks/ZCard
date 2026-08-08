<?php

namespace Tests\Feature;

use App\Models\Recharge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * 后台充值单管理 API:列表/统计/详情(此前后台无充值管理,本次新增)。
 */
class AdminRechargeControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        foreach (['super_admin', 'merchant', 'user'] as $r) {
            Role::firstOrCreate(['name' => $r]);
        }
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin->createToken('test')->plainTextToken;
    }

    private function makeRecharge(int $userId, int $amountFen, string $status, string $target = 'balance'): Recharge
    {
        return Recharge::create([
            'recharge_no' => 'RCH'.uniqid(),
            'user_id' => $userId,
            'amount' => $amountFen,
            'status' => $status,
            'target' => $target,
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }

    public function test_index_lists_recharges_with_user(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $this->makeRecharge($user->id, 5000, Recharge::STATUS_PAID);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/admin/recharges?pageSize=15');

        $resp->assertOk();
        $resp->assertJsonCount(1, 'data');
        $this->assertSame(5000, $resp->json('data.0.amount'));
        $this->assertSame($user->username, $resp->json('data.0.user.username'));
    }

    public function test_index_filters_by_keyword_and_status(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $paid = $this->makeRecharge($user->id, 1000, Recharge::STATUS_PAID);
        $this->makeRecharge($user->id, 2000, Recharge::STATUS_PENDING);

        // 按充值单号关键字
        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/admin/recharges?keyword='.$paid->recharge_no);
        $resp->assertOk();
        $resp->assertJsonCount(1, 'data');

        // 按状态
        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/admin/recharges?status=pending');
        $resp->assertOk();
        $resp->assertJsonCount(1, 'data');
        $this->assertSame(2000, $resp->json('data.0.amount'));
    }

    public function test_stats_sums_amounts_by_status(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $this->makeRecharge($user->id, 1000, Recharge::STATUS_PAID);
        $this->makeRecharge($user->id, 2000, Recharge::STATUS_PAID);
        $this->makeRecharge($user->id, 500, Recharge::STATUS_PENDING);
        $this->makeRecharge($user->id, 300, Recharge::STATUS_CLOSED);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/admin/recharges/stats');

        $resp->assertOk();
        $this->assertSame(4, $resp->json('total_count'));
        // MySQL SUM() 返回字符串
        $this->assertSame('3800', (string) $resp->json('total_amount'));
        $this->assertSame('3000', (string) $resp->json('paid_amount'));
        $this->assertSame('500', (string) $resp->json('pending_amount'));
        $this->assertSame('300', (string) $resp->json('closed_amount'));
    }

    public function test_show_returns_recharge_detail(): void
    {
        $token = $this->adminToken();
        $user = User::factory()->create();
        $recharge = $this->makeRecharge($user->id, 5000, Recharge::STATUS_PAID);

        $resp = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/admin/recharges/'.$recharge->id);

        $resp->assertOk();
        $this->assertSame($recharge->recharge_no, $resp->json('recharge_no'));
        $this->assertSame($user->email, $resp->json('user.email'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $resp = $this->getJson('/api/admin/recharges');
        $resp->assertUnauthorized();
    }
}
