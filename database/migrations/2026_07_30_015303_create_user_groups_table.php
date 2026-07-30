<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->comment('等级名称 如:普通会员/VIP1/VIP2');
            $table->decimal('discount', 5, 2)->default(100.00)->comment('折扣百分比 100=原价 80=8折');
            $table->decimal('min_recharge', 10, 2)->default(0)->comment('达到该累计充值自动升级');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_groups');
    }
};
