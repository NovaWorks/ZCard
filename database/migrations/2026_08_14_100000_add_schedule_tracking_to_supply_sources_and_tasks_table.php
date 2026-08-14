<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 货源定时任务计划：
 * 1) supply_sources 增加三类任务各自的上次执行时间(采集/价格/上下架),供调度器判断是否到期;
 * 2) supply_sync_tasks 增加 scope 列,区分任务类型(collect/price/status),任务历史里可辨识。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_sources', function (Blueprint $table) {
            $table->timestamp('last_collect_at')->nullable()->after('last_synced_at')
                ->comment('最近一次定时采集商品时间');
            $table->timestamp('last_price_sync_at')->nullable()->after('last_collect_at')
                ->comment('最近一次定时同步价格时间');
            $table->timestamp('last_status_sync_at')->nullable()->after('last_price_sync_at')
                ->comment('最近一次定时同步上下架时间');
        });

        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->string('scope', 20)->default('collect')->after('mode')
                ->comment('任务类型:collect=采集商品|price=同步价格|status=同步上下架');
            $table->index(['supply_source_id', 'scope', 'status'], 'supply_sync_tasks_scope_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->dropIndex('supply_sync_tasks_scope_status_index');
            $table->dropColumn('scope');
        });

        Schema::table('supply_sources', function (Blueprint $table) {
            $table->dropColumn(['last_collect_at', 'last_price_sync_at', 'last_status_sync_at']);
        });
    }
};
