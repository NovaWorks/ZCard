<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('channel', 50);
            $table->string('channel_order_no', 80)->nullable();
            $table->bigInteger('amount')->default(0)->comment('单位分');
            $table->string('status', 20)->default('pending')->comment('pending/success/failed');
            $table->timestamp('paid_at')->nullable();
            $table->json('raw')->nullable()->comment('回调原文');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
