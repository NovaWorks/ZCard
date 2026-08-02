<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 会员等级自动升级:为 users 增加累计充值/累计消费,为 user_groups 增加累计消费阈值。
 * - 累计充值 = 管理员手动调账 + 分销佣金(BillService::record 的 TYPE_INCOME)。
 * - 累计消费 = 用户已支付订单金额(OrderPaid 时累加,仅注册用户 user_id 非空)。
 * 金额单位:分(与 balance 保持一致)。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('total_recharge')->default(0)->comment('累计充值,单位分')->after('balance');
            $table->bigInteger('total_consumption')->default(0)->comment('累计消费,单位分')->after('total_recharge');

            $table->index('total_recharge');
            $table->index('total_consumption');
        });

        Schema::table('user_groups', function (Blueprint $table) {
            $table->decimal('min_consumption', 10, 2)->default(0)->comment('达到该累计消费自动升级')->after('min_recharge');
        });
    }

    public function down(): void
    {
        Schema::table('user_groups', function (Blueprint $table) {
            $table->dropColumn('min_consumption');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['total_consumption']);
            $table->dropIndex(['total_recharge']);
            $table->dropColumn(['total_consumption', 'total_recharge']);
        });
    }
};
