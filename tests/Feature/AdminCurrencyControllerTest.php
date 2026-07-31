<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCurrencyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        $user = User::factory()->create();
        return $user->createToken('test')->plainTextToken;
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->adminToken()];
    }

    public function test_admin_can_list_currencies(): void
    {
        Currency::create(['code'=>'CNY','name'=>'人民币','symbol'=>'¥','symbol_position'=>'before','decimal_places'=>2,'exchange_rate'=>'1','is_base'=>true,'is_enabled'=>true,'sort'=>0]);
        $resp = $this->withHeaders($this->authHeaders())->getJson('/api/admin/currencies');
        $resp->assertOk();
        $this->assertCount(1, $resp->json());
    }

    public function test_admin_can_create_currency(): void
    {
        $resp = $this->withHeaders($this->authHeaders())->postJson('/api/admin/currencies', [
            'code' => 'usd', 'name' => '美元', 'symbol' => '$',
            'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => '0.14', 'is_base' => false, 'is_enabled' => true, 'sort' => 1,
        ]);
        $resp->assertCreated();
        $this->assertDatabaseHas('currencies', ['code' => 'USD']); // code uppercased
    }

    public function test_setting_base_unsets_others(): void
    {
        Currency::create(['code'=>'CNY','name'=>'人民币','symbol'=>'¥','symbol_position'=>'before','decimal_places'=>2,'exchange_rate'=>'1','is_base'=>true,'is_enabled'=>true,'sort'=>0]);
        Currency::create(['code'=>'USD','name'=>'美元','symbol'=>'$','symbol_position'=>'before','decimal_places'=>2,'exchange_rate'=>'0.14','is_base'=>false,'is_enabled'=>true,'sort'=>1]);

        $resp = $this->withHeaders($this->authHeaders())->putJson('/api/admin/currencies/USD', ['is_base' => true]);
        $resp->assertOk();
        $this->assertTrue((bool) Currency::find('USD')->is_base);
        $this->assertFalse((bool) Currency::find('CNY')->is_base);
        // base rate forced to 1 (decimal:8 cast renders as "1.00000000", compare numerically)
        $this->assertEquals(1, (float) Currency::find('USD')->exchange_rate);
    }

    public function test_cannot_delete_base_currency(): void
    {
        Currency::create(['code'=>'CNY','name'=>'人民币','symbol'=>'¥','symbol_position'=>'before','decimal_places'=>2,'exchange_rate'=>'1','is_base'=>true,'is_enabled'=>true,'sort'=>0]);
        $resp = $this->withHeaders($this->authHeaders())->deleteJson('/api/admin/currencies/CNY');
        $resp->assertStatus(422);
    }
}
