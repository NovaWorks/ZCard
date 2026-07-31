<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Support\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Currency::query()->delete();
        Currency::create(['code'=>'CNY','name'=>'人民币','symbol'=>'¥','symbol_position'=>'before','decimal_places'=>2,'exchange_rate'=>'1','is_base'=>true,'is_enabled'=>true,'sort'=>0]);
        Currency::create(['code'=>'USD','name'=>'美元','symbol'=>'$','symbol_position'=>'before','decimal_places'=>2,'exchange_rate'=>'0.14000000','is_base'=>false,'is_enabled'=>true,'sort'=>1]);
        Currency::create(['code'=>'EUR','name'=>'欧元','symbol'=>'€','symbol_position'=>'after','decimal_places'=>2,'exchange_rate'=>'0.13000000','is_base'=>false,'is_enabled'=>true,'sort'=>2]);
    }

    public function test_convert_base_to_usd(): void
    {
        $svc = app(CurrencyService::class);
        // 1250 分 = 12.50 CNY × 0.14 = 1.75 USD = 175 分
        $r = $svc->convert(1250, 'USD');
        $this->assertSame(175, $r['amount']);
        $this->assertSame('0.14000000', $r['rate']);
        $this->assertSame('USD', $r['currency']);
    }

    public function test_convert_to_base_returns_same(): void
    {
        $svc = app(CurrencyService::class);
        $r = $svc->convert(1250, 'CNY');
        $this->assertSame(1250, $r['amount']);
        $this->assertSame('1', $r['rate']);
    }

    public function test_format_symbol_before(): void
    {
        $svc = app(CurrencyService::class);
        $this->assertSame('¥12.50', $svc->format(1250, 'CNY'));
        $this->assertSame('$1.75', $svc->format(175, 'USD'));
    }

    public function test_get_enabled_caches_results(): void
    {
        $svc = app(CurrencyService::class);
        $svc->getEnabledCurrencies();
        $this->assertTrue(Cache::has(CurrencyService::CACHE_KEY));
    }

    public function test_format_eur_symbol_after(): void
    {
        $svc = app(CurrencyService::class);
        // 1250 分 × 0.13 = 162.5 → 163 EUR 分 → €1.63, symbol after
        $conv = $svc->convert(1250, 'EUR');
        $this->assertSame(163, $conv['amount']); // 12.50 × 0.13 = 1.625 → round → 1.63 → 163 分
        $this->assertSame('1.63€', $svc->format($conv['amount'], 'EUR'));
    }

    public function test_format_negative_amount(): void
    {
        $svc = app(CurrencyService::class);
        $this->assertSame('-¥12.50', $svc->format(-1250, 'CNY'));
    }

    public function test_convert_rounds_half_up(): void
    {
        $svc = app(CurrencyService::class);
        // 1255 分 = 12.55 CNY × 0.14 = 1.757 → round → 1.76 → 176 分 (not truncate 175)
        $r = $svc->convert(1255, 'USD');
        $this->assertSame(176, $r['amount']);
    }
}
