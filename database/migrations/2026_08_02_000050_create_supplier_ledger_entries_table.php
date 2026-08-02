<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_account_id')->constrained('supplier_accounts')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete()->comment('对应本地order,下单扣费时有');
            $table->string('type', 20)->comment('recharge(充值)|order(扣费)|refund(退款)|adjust(手动调)');
            $table->bigInteger('amount')->comment('有符号(分):正=入账,负=扣费');
            $table->bigInteger('balance_after')->comment('变动后余额快照(分)');
            $table->string('idempotency_key', 100)->unique()->comment('幂等键');
            $table->string('remark')->nullable();
            $table->timestamps();

            $table->index(['supplier_account_id', 'type']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_ledger_entries');
    }
};
