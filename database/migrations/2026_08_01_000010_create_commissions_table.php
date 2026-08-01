<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete()->comment('下单买家');
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete()->comment('收佣人');
            $table->unsignedTinyInteger('tier')->comment('层级 1/2/3');
            $table->decimal('rate', 8, 4)->comment('费率快照(百分比,如 10.0000 = 10%)');
            $table->bigInteger('base_amount')->default(0)->comment('毛利基数(分)');
            $table->bigInteger('amount')->default(0)->comment('佣金(分)');
            $table->string('status', 20)->default('available')->comment('available/pending/paid');
            $table->timestamps();
            $table->unique(['order_id', 'tier']); // 幂等防重复
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
