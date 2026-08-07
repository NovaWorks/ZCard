<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 支付渠道新增「手续费承担方」。
 *
 * 已有字段:
 * - fee:decimal(5,4) 手续费值(percent 时为百分比数值如 0.05=5%;fixed 时为固定金额,单位元)
 * - fee_type:percent / fixed
 *
 * 新增:
 * - fee_bearer:merchant=商户承担(应付金额不变,手续费从商户收款扣) / customer=客户承担(应付金额+手续费)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_channels', function (Blueprint $table) {
            $table->string('fee_bearer', 10)->default('merchant')->after('fee_type')
                ->comment('手续费承担方:merchant=商户承担 / customer=客户承担');
        });
    }

    public function down(): void
    {
        Schema::table('payment_channels', function (Blueprint $table) {
            $table->dropColumn('fee_bearer');
        });
    }
};
