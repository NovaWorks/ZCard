<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 货源同步任务表(issue:同步改异步 + 任务状态/进度跟踪)。
 * 状态流转:queued → running → success | failed | cancelled。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supply_sync_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('supply_source_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 20)->default('incremental')->comment('incremental/full');
            $table->string('status', 20)->default('queued')
                ->comment('queued/running/success/failed/cancelled');
            $table->unsignedInteger('total_products')->default(0)->comment('总商品数(分页拉取中累加)');
            $table->unsignedInteger('processed_products')->default(0)->comment('已处理商品数(进度)');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('hidden_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supply_source_id', 'status']);
            $table->index(['supply_source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_sync_tasks');
    }
};
