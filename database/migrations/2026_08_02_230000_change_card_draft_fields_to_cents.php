<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cards.draft_premium / draft_cost 从「元」(decimal) 统一为「分」(bigInteger)。
 * 与 products.draft_premium(分) 保持一致,消除同名不同单位的歧义。
 * 现有数据 *100 转换。
 */
return new class extends Migration {
    public function up(): void
    {
        // 先把现有数据从元转成分(*100)
        DB::table('cards')->where('draft_premium', '>', 0)->update([
            'draft_premium' => DB::raw('draft_premium * 100'),
        ]);
        DB::table('cards')->where('draft_cost', '>', 0)->update([
            'draft_cost' => DB::raw('draft_cost * 100'),
        ]);

        // 改列类型: decimal(10,2) → bigint(直接用 SQL,不依赖 doctrine/dbal)
        DB::statement('ALTER TABLE cards MODIFY draft_premium BIGINT NOT NULL DEFAULT 0 COMMENT "预选加价,单位分"');
        DB::statement('ALTER TABLE cards MODIFY draft_cost BIGINT NOT NULL DEFAULT 0 COMMENT "预选成本,单位分"');
    }

    public function down(): void
    {
        // 回退:先把分转回元(/100)
        DB::table('cards')->where('draft_premium', '>', 0)->update([
            'draft_premium' => DB::raw('draft_premium / 100'),
        ]);
        DB::table('cards')->where('draft_cost', '>', 0)->update([
            'draft_cost' => DB::raw('draft_cost / 100'),
        ]);

        DB::statement('ALTER TABLE cards MODIFY draft_premium DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT "预选加价"');
        DB::statement('ALTER TABLE cards MODIFY draft_cost DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT "预选成本"');
    }
};
