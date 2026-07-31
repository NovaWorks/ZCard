<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('amount')->default(0)->comment('金额,单位分(正数)');
            $table->bigInteger('balance_after')->default(0)->comment('变动后余额快照,单位分');
            $table->unsignedTinyInteger('type')->default(1)->comment('0=支出,1=收入');
            $table->string('log', 200)->default('')->comment('交易说明');
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete()->comment('关联订单');
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete()->comment('操作管理员');
            $table->timestamps();
            $table->index(['user_id', 'type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
