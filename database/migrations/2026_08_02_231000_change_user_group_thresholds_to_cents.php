<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * user_groups.min_recharge / min_consumption 从「元」(decimal) 统一为「分」(bigInteger)。
 * 与 users.total_recharge / total_consumption(分) 保持一致,
 * MemberUpgradeService 不再需要 /100 换算。
 * 现有数据 *100 转换。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 现有数据从元转分(*100)
        DB::table('user_groups')->where('min_recharge', '>', 0)->update([
            'min_recharge' => DB::raw('min_recharge * 100'),
        ]);
        DB::table('user_groups')->where('min_consumption', '>', 0)->update([
            'min_consumption' => DB::raw('min_consumption * 100'),
        ]);

        Schema::table('user_groups', function (Blueprint $table) {
            $table->bigInteger('min_recharge')->default(0)->comment('达到该累计充值自动升级,单位分')->change();
            $table->bigInteger('min_consumption')->default(0)->comment('达到该累计消费自动升级,单位分')->change();
        });
    }

    public function down(): void
    {
        // 回退:分转回元(/100)
        DB::table('user_groups')->where('min_recharge', '>', 0)->update([
            'min_recharge' => DB::raw('min_recharge / 100.0'),
        ]);
        DB::table('user_groups')->where('min_consumption', '>', 0)->update([
            'min_consumption' => DB::raw('min_consumption / 100.0'),
        ]);

        Schema::table('user_groups', function (Blueprint $table) {
            $table->decimal('min_recharge', 10, 2)->default(0)->comment('达到该累计充值自动升级')->change();
            $table->decimal('min_consumption', 10, 2)->default(0)->comment('达到该累计消费自动升级')->change();
        });
    }
};
