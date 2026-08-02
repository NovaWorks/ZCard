<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上游商品库存缓存。
 * 上游商品无本地卡(availableStock 永远0),用此列缓存上游返回的库存数,
 * 同步时从 UpstreamProduct.stockQuantity 写入。-1=无限,null=本地商品(数本地卡)。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('stock_cache')->nullable()->after('upstream_synced_at')->comment('上游库存缓存(上游商品用);-1=无限,null=本地商品');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_cache');
        });
    }
};
