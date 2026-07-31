<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('base_currency', 3)->nullable()->after('amount');
            $table->char('display_currency', 3)->nullable()->after('base_currency');
            $table->decimal('exchange_rate', 20, 8)->nullable()->after('display_currency');
            $table->bigInteger('amount_display')->nullable()->comment('显示货币·最小单位')->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['base_currency', 'display_currency', 'exchange_rate', 'amount_display']);
        });
    }
};
