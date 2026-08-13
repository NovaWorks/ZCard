<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 同步任务统计:本次同步中价格发生变化的商品数(价格核对/调价跟随)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->unsignedInteger('price_updated_count')->default(0)->after('updated_count');
        });
    }

    public function down(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->dropColumn('price_updated_count');
        });
    }
};
