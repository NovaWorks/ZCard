<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_account_id')->constrained('supplier_accounts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('sku_id')->nullable()->constrained('product_skus')->cascadeOnDelete()->comment('null=商品级默认价;非null=SKU级专属价');
            $table->bigInteger('price')->default(0)->comment('给该账号的拿货价(分)');
            $table->timestamps();

            $table->unique(['supplier_account_id', 'product_id', 'sku_id'], 'uniq_supply_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_prices');
    }
};
