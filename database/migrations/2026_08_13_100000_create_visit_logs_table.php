<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 前台流量访问日志(数据看板 PV/UV 统计,issue #6)。
 * 中间件 TrackVisitor 写入;聚合按日 COUNT(*)/COUNT(DISTINCT ip)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('path', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_logs');
    }
};
