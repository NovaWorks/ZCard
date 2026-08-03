<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // payments.order_id 原为 NOT NULL 外键(关联发卡订单)。
        // 充值支付同样记一条流水,但不关联订单,故放宽 order_id 为可空,
        // 并新增 recharge_id 关联充值单。
        Schema::table('payments', function (Blueprint $table) {
            // 修改外键列前需先解除旧外键约束
            $table->dropForeign(['order_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            $table->foreignId('recharge_id')->nullable()->after('order_id')
                ->constrained('recharges')->cascadeOnDelete();
            $table->index('recharge_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['recharge_id']);
            $table->dropIndex(['recharge_id']);
            $table->dropColumn('recharge_id');

            $table->dropForeign(['order_id']);
            $table->foreignId('order_id')->nullable(false)->change();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }
};
