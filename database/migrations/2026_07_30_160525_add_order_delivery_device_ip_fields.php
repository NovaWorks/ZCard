<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 订单表新增发货状态、下单设备、下单IP字段(参考 acg-faka)。
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_status', 20)->default('pending')->comment('pending=未发货/delivered=已发货')->after('status');
            $table->string('create_device', 20)->nullable()->comment('win/mac/ios/android/other')->after('contact');
            $table->string('create_ip', 64)->nullable()->comment('下单IP')->after('create_device');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'create_device', 'create_ip']);
        });
    }
};
