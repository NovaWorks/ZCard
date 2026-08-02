<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('upstream_source_id')->nullable()->after('merchant_id')
                ->constrained('supply_sources')->nullOnDelete()->comment('来源货源,null=本地自营');
            $table->string('upstream_product_code')->nullable()->after('upstream_source_id')
                ->comment('上游商品标识(acg-faka code/dujiao sku_id/zcard slug)');
            $table->timestamp('upstream_synced_at')->nullable()->after('upstream_product_code')
                ->comment('最近一次上游同步时间');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['upstream_source_id', 'upstream_product_code', 'upstream_synced_at']);
        });
    }
};
