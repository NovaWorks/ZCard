<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 记录同步时自动软删除的失效商品数量。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->unsignedInteger('deleted_count')->default(0)
                ->after('hidden_count')->comment('同步时自动软删除的失效商品数');
        });
    }

    public function down(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->dropColumn('deleted_count');
        });
    }
};
