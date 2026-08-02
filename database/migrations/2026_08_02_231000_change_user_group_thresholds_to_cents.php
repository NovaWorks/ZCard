<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * user_groups.min_recharge / min_consumption 从「元」(decimal) 统一为「分」(bigInteger)。
 * 与 users.total_recharge / total_consumption(分) 保持一致,
 * MemberUpgradeService 不再需要 /100 换算。
 * 现有数据 *100 转换。
 */
return new class extends Migration {
    public function up(): void
    {
        // 现有数据从元转分(*100)
        DB::table('user_groups')->where('min_recharge', '>', 0)->update([
            'min_recharge' => DB::raw('min_recharge * 100'),
        ]);
        DB::table('user_groups')->where('min_consumption', '>', 0)->update([
            'min_consumption' => DB::raw('min_consumption * 100'),
        ]);

        // 改列类型: decimal(10,2) → bigint
        DB::statement('ALTER TABLE user_groups MODIFY min_recharge BIGINT NOT NULL DEFAULT 0 COMMENT "达到该累计充值自动升级,单位分"');
        DB::statement('ALTER TABLE user_groups MODIFY min_consumption BIGINT NOT NULL DEFAULT 0 COMMENT "达到该累计消费自动升级,单位分"');
    }

    public function down(): void
    {
        // 回退:分转回元(/100)
        DB::table('user_groups')->where('min_recharge', '>', 0)->update([
            'min_recharge' => DB::raw('min_recharge / 100'),
        ]);
        DB::table('user_groups')->where('min_consumption', '>', 0)->update([
            'min_consumption' => DB::raw('min_consumption / 100'),
        ]);

        DB::statement('ALTER TABLE user_groups MODIFY min_recharge DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT "达到该累计充值自动升级"');
        DB::statement('ALTER TABLE user_groups MODIFY min_consumption DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT "达到该累计消费自动升级"');
    }
};
