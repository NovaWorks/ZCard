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
        Schema::table('cards', function (Blueprint $table) {
            $table->string('note', 255)->nullable()->comment('备注信息');
            $table->string('card_type', 20)->nullable()->comment('卡密类型(如:月卡/周卡/天卡,对应SKU)');
            $table->unsignedBigInteger('owner_id')->default(0)->comment('所属会员ID,0=系统');
            $table->decimal('draft_premium', 10, 2)->default(0)->comment('预选加价');
            $table->decimal('draft_cost', 10, 2)->default(0)->comment('预选成本');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn(['note', 'card_type', 'owner_id', 'draft_premium', 'draft_cost']);
        });
    }
};
