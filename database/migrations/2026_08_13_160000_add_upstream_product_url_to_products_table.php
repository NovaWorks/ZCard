<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 保存上游驱动确认过的公开商品链接，避免用对接 CODE 猜测前台路由。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('upstream_product_url', 2048)->nullable()
                ->after('upstream_product_code')->comment('上游公开商品页真实链接');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('upstream_product_url');
        });
    }
};
