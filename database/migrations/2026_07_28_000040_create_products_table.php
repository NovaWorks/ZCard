<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->longText('description')->nullable();
            $table->bigInteger('price')->default(0)->comment('单位分');
            $table->json('member_price')->nullable()->comment('按会员等级 {level: price}');
            $table->string('cover')->nullable();
            $table->json('images')->nullable();
            $table->string('stock_type', 20)->default('card')->comment('card/url/code');
            $table->boolean('stock_visible')->default(true)->comment('是否显示库存数');
            $table->json('control_config')->nullable()->comment('自定义控件配置');
            $table->string('delivery_mode', 10)->default('status')->comment('status=保留 delete=删除');
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['merchant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
