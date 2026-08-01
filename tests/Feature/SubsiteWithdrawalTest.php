<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\SubsiteLedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsiteWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_withdraw_consumes_available_entries(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $merchant = Merchant::create(['user_id' => $user->id, 'name' => 'Sub', 'slug' => 's', 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        foreach ([300, 500, 200] as $amt) {
            SubsiteLedgerEntry::create(['merchant_id' => $merchant->id, 'type' => 'order_profit', 'amount' => $amt, 'status' => 'available', 'idempotency_key' => 'k' . $amt, 'available_at' => now()->subDay()]);
        }

        $w = \App\Support\SubsiteWithdrawalService::request($merchant->id, 700, 'alipay', 'acc@test.com', 'Test');
        $this->assertSame(700, (int) $w->amount);

        $lockedCount = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'locked')->count();
        $this->assertSame(2, $lockedCount); // 300 全锁 + 500 拆分(锁 400)
        $available = SubsiteLedgerEntry::where('merchant_id', $merchant->id)->where('status', 'available')->sum('amount');
        $this->assertSame(300, (int) $available); // 1000 总额 - 700 提现 = 300(200 原行 + 100 拆分差额)
    }

    public function test_cannot_withdraw_more_than_available(): void
    {
        $user = User::factory()->create();
        $merchant = Merchant::create(['user_id' => $user->id, 'name' => 'Sub', 'slug' => 's2', 'status' => 1, 'commission_rate' => 0, 'settings' => ['is_subsite' => true]]);
        SubsiteLedgerEntry::create(['merchant_id' => $merchant->id, 'type' => 'order_profit', 'amount' => 100, 'status' => 'available', 'idempotency_key' => 'k1', 'available_at' => now()]);

        $this->expectException(\RuntimeException::class);
        \App\Support\SubsiteWithdrawalService::request($merchant->id, 500, 'alipay', 'a@b.com', 'T');
    }
}
