<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付流水支持聚合支付:一次支付关联多个订单(购物车收银台)。
 * order_ids 为空时仍为单订单支付(order_id 为准),兼容存量数据。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->json('order_ids')->nullable()->after('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('order_ids');
        });
    }
};
