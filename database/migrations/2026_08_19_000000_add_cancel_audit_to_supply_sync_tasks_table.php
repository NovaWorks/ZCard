<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为同步任务取消动作补齐操作者、来源和原因审计。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->foreignId('cancel_requested_by')->nullable()->after('cancel_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_requested_by_name')->nullable()->after('cancel_requested_by');
            $table->string('cancel_request_ip', 45)->nullable()->after('cancel_requested_by_name');
            $table->string('cancel_reason', 500)->nullable()->after('cancel_request_ip');
            $table->string('cancel_trigger', 32)->nullable()->after('cancel_reason')
                ->comment('admin/system');
        });
    }

    public function down(): void
    {
        Schema::table('supply_sync_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancel_requested_by');
            $table->dropColumn([
                'cancel_requested_by_name',
                'cancel_request_ip',
                'cancel_reason',
                'cancel_trigger',
            ]);
        });
    }
};
