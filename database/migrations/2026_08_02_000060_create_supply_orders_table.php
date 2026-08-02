<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supply_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_account_id')->constrained('supplier_accounts')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete()->comment('对应本地order(source=supply)');
            $table->string('downstream_order_no', 100)->comment('下游幂等订单号');
            $table->string('fulfillment_mode', 10)->default('sync')->comment('sync|async');
            $table->string('callback_url', 500)->nullable()->comment('下游回调地址');
            $table->string('callback_status', 20)->nullable()->comment('pending|sent|failed');
            $table->timestamps();

            $table->unique(['supplier_account_id', 'downstream_order_no'], 'uniq_supply_downstream_no');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_orders');
    }
};
