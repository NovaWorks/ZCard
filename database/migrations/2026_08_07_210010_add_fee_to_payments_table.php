<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付流水(payments)新增手续费字段,记录该笔支付按通道配置扣的手续费(分)。
 * 商户承担:手续费从商户实收扣(对账时实收 = charged_amount - fee);
 * 客户承担:手续费已加进应付金额(charged_amount 已含 fee)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('fee')->default(0)->after('amount')->comment('手续费,单位分');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('fee');
        });
    }
};
