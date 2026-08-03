<?php

use App\Models\Currency;
use Illuminate\Database\Migrations\Migration;

/**
 * 预置默认货币数据(系统基础数据)。
 *
 * 原本靠 CurrencySeeder 填充,但 DatabaseSeeder 在生产环境不执行,
 * 且在线更新只跑 migrate 不跑 seed,导致 currencies 表为空,
 * 前台货币切换器显示空下拉。
 * 这里用 updateOrCreate 幂等创建,确保 migrate 后自动拥有基础货币。
 */
return new class extends Migration {
    public function up(): void
    {
        $rows = [
            ['code' => 'CNY', 'name' => '人民币', 'symbol' => '¥', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '1', 'is_base' => true, 'is_enabled' => true, 'sort' => 0],
            ['code' => 'USD', 'name' => '美元', 'symbol' => '$', 'symbol_position' => 'before', 'decimal_places' => 2, 'exchange_rate' => '0.14000000', 'is_base' => false, 'is_enabled' => false, 'sort' => 1],
            ['code' => 'EUR', 'name' => '欧元', 'symbol' => '€', 'symbol_position' => 'after', 'decimal_places' => 2, 'exchange_rate' => '0.13000000', 'is_base' => false, 'is_enabled' => false, 'sort' => 2],
        ];

        foreach ($rows as $r) {
            Currency::updateOrCreate(['code' => $r['code']], $r);
        }
    }

    public function down(): void
    {
        // 不删除货币数据(可能已被运营修改),down 留空
    }
};
