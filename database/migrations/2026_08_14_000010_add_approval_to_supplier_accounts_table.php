<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 供货账号审核制(安全审计 H-2):
 * 1. 新增 approved 字段 —— 用户自助开通的供货账号默认待审核,
 *    管理员在后台审核通过前,SupplyAuth 拒绝其调用供货 API(防止任意注册用户
 *    直接以 factory_price 成本价拿货);
 * 2. 存量账号(含管理员手动创建)全部回填 approved=1,升级不影响现有对接;
 * 3. user_id 收紧为唯一索引 —— 修复并发调用 /supplier-account/me 可为同一用户
 *    创建多个账号的竞态(MySupplyController 同步改为捕获唯一键冲突后重查)。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 先清理历史脏数据:同一 user_id 保留最新一个,其余物理删除
        // (模型用 SoftDeletes,这里按业务语义直接清理,避免唯一索引创建失败)
        $dupIds = DB::table('supplier_accounts')
            ->selectRaw('MAX(id) as keep_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('keep_id');
        if ($dupIds->isNotEmpty()) {
            DB::table('supplier_accounts')
                ->whereNotNull('user_id')
                ->whereNotIn('id', $dupIds)
                ->delete();
        }

        Schema::table('supplier_accounts', function (Blueprint $table) {
            $table->boolean('approved')->default(false)->after('status')->comment('审核通过:自助开通的账号需管理员审核后方可调用供货API');
            $table->unique('user_id');
        });

        // 存量账号一律视为已审核(管理员手动创建/历史自助创建,升级不中断现有对接)
        DB::table('supplier_accounts')->update(['approved' => true]);
    }

    public function down(): void
    {
        Schema::table('supplier_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->dropColumn('approved');
        });
    }
};
