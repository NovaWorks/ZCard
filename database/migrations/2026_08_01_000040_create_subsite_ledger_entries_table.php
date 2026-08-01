<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsite_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('type', 32)->comment('order_profit/refund_deduct/withdraw_lock/withdraw_paid/manual_adjust');
            $table->bigInteger('amount')->default(0)->comment('有符号(分):正=收入,负=扣除');
            $table->string('status', 32)->default('pending')->comment('pending/available/locked/withdrawn/canceled');
            $table->timestamp('available_at')->nullable();
            $table->foreignId('withdraw_request_id')->nullable()->constrained('withdrawals')->nullOnDelete();
            $table->string('idempotency_key', 160);
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique('idempotency_key');
            $table->index(['merchant_id', 'status']);
            $table->index('available_at');
        });
    }
    public function down(): void { Schema::dropIfExists('subsite_ledger_entries'); }
};
