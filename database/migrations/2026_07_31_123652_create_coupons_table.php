<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('券码');
            $table->string('type', 10)->default('fixed')->comment('fixed=固定金额,percent=百分比');
            $table->bigInteger('value')->default(0)->comment('fixed=分,percent=百分比如10=9折');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete()->comment('适用商品,null=全场');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete()->comment('适用分类,null=不限');
            $table->bigInteger('min_amount')->default(0)->comment('最低消费,单位分');
            $table->string('status', 20)->default('active')->comment('active/used/disabled');
            $table->timestamp('expires_at')->nullable()->comment('过期时间');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('note', 100)->default('')->comment('备注');
            $table->timestamps();
            $table->index('status');
            $table->index('product_id');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
