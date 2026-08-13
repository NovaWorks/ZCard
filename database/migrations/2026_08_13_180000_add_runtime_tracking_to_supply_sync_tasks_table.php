<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 同步任务运行态：心跳、阶段、取消请求、结构化错误和 worker 版本。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->timestamp('heartbeat_at')->nullable()->after('started_at');
            $table->string('current_stage', 50)->nullable()->after('heartbeat_at');
            $table->unsignedInteger('current_page')->default(0)->after('current_stage');
            $table->unsignedInteger('stage_current')->default(0)->after('current_page');
            $table->unsignedInteger('stage_total')->default(0)->after('stage_current');
            $table->timestamp('cancel_requested_at')->nullable()->after('stage_total');
            $table->string('error_code', 64)->nullable()->after('error');
            $table->json('error_context')->nullable()->after('error_code');
            $table->string('worker_version', 32)->nullable()->after('error_context');

            $table->index(['status', 'heartbeat_at'], 'supply_sync_tasks_status_heartbeat_index');
        });
    }

    public function down(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->dropIndex('supply_sync_tasks_status_heartbeat_index');
            $table->dropColumn([
                'heartbeat_at',
                'current_stage',
                'current_page',
                'stage_current',
                'stage_total',
                'cancel_requested_at',
                'error_code',
                'error_context',
                'worker_version',
            ]);
        });
    }
};
