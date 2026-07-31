<?php

namespace Tests\Feature;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_currency_uses_string_code_primary_key(): void
    {
        $c = Currency::create([
            'code' => 'USD', 'name' => '美元', 'symbol' => '$',
            'symbol_position' => 'before', 'decimal_places' => 2,
            'exchange_rate' => '0.14000000', 'is_base' => false, 'is_enabled' => true, 'sort' => 1,
        ]);
        $this->assertSame('USD', $c->code);
        $fresh = $c->fresh();
        $this->assertSame('USD', $fresh->code); // string PK 持久化
        $this->assertFalse($fresh->is_base);
    }
}
