<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsite_order_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->foreignId('reseller_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('base_amount')->default(0)->comment('基础金额(分)');
            $table->bigInteger('reseller_amount')->default(0)->comment('分站售价(分)');
            $table->bigInteger('profit_amount')->default(0)->comment('利润(分)');
            $table->boolean('profit_eligible')->default(true);
            $table->string('profit_block_reason', 64)->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->json('risk_snapshot')->nullable();
            $table->timestamps();
            $table->unique('order_id');
        });
    }
    public function down(): void { Schema::dropIfExists('subsite_order_snapshots'); }
};
