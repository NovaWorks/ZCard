<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true,  'is_enabled' => true, 'sort' => 0],
            ['code' => 'USD', 'name' => '美元',   'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '0.14000000', 'is_base' => false, 'is_enabled' => false, 'sort' => 1],
            ['code' => 'EUR', 'name' => '欧元',   'symbol' => '€', 'symbol_position' => 'after',  'decimal_places' => 2, 'exchange_rate' => '0.13000000', 'is_base' => false, 'is_enabled' => false, 'sort' => 2],
        ];
        foreach ($rows as $r) {
            Currency::updateOrCreate(['code' => $r['code']], $r);
        }
    }
}
