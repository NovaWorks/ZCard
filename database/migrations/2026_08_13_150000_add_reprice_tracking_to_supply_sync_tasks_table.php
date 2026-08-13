<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 同步任务重新定价追踪:记录是否覆盖手动价，以及普通同步因手动价保护跳过的数量。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->boolean('force_reprice')->default(false)->after('mode')
                ->comment('是否覆盖手动价并恢复自动定价');
            $table->unsignedInteger('manual_price_skipped_count')->default(0)
                ->after('price_updated_count')->comment('因手动价保护跳过重新定价的商品数');
        });
    }

    public function down(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->dropColumn(['force_reprice', 'manual_price_skipped_count']);
        });
    }
};
