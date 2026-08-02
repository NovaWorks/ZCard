<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 修复: supply_orders.callback_url 列长度不足。
 *
 * 原默认 varchar(255),但 SupplyOrderController 验证允许 max:500,
 * 256-500 字符的回调地址通过验证后会在 MySQL 严格模式报 1406 Data too long。
 * 与 api_secret 同类 bug(SQLite 不强制长度故测试未发现)。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('supply_orders', function (Blueprint $table) {
            $table->string('callback_url', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supply_orders', function (Blueprint $table) {
            $table->string('callback_url')->nullable()->change();
        });
    }
};
