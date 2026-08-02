<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * card_imports 增加 skipped_count 字段。
 * 去重跳过的卡密(内容已存在)不再混入 failed_count,单独统计。
 * failed_count 保留给真正的错误(编码异常/入库失败)。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('card_imports', function (Blueprint $table) {
            $table->unsignedInteger('skipped_count')->default(0)->after('failed_count')->comment('去重跳过数');
        });
    }

    public function down(): void
    {
        Schema::table('card_imports', function (Blueprint $table) {
            $table->dropColumn('skipped_count');
        });
    }
};
