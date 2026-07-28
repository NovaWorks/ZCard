<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->bigInteger('amount')->default(0)->comment('单位分');
            $table->timestamps();
            // 无 card_ids：卡密经 cards.order_id 反查（spec §6.1 决策9）
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
