<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsite_product_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('sku_id')->default(0)->comment('0=商品级;>0=SKU级');
            $table->boolean('is_listed')->default(true)->comment('此分站是否上架');
            $table->enum('pricing_mode', ['inherit', 'markup_percent', 'fixed_markup', 'fixed_price'])->default('inherit');
            $table->decimal('markup_percent', 8, 2)->default(0);
            $table->bigInteger('fixed_markup_amount')->default(0)->comment('固定加价(分)');
            $table->bigInteger('fixed_price_amount')->default(0)->comment('一口价(分)');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['merchant_id', 'product_id', 'sku_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('subsite_product_settings');
    }
};
