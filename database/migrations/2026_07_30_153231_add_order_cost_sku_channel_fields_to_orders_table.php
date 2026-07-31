<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 订单表新增金额拆分与快照字段。
     * - cost: 成本(分),下单时从 product.factory_price × qty 快照
     * - sku_name: SKU 名称快照,方便列表直接展示
     * - payment_channel: 支付渠道码快照(支付成功后写入)
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('cost')->default(0)->comment('成本,单位分')->after('amount');
            $table->string('sku_name', 100)->nullable()->comment('SKU名称快照')->after('quantity');
            $table->string('payment_channel', 30)->nullable()->comment('支付渠道码快照')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cost', 'sku_name', 'payment_channel']);
        });
    }
};
