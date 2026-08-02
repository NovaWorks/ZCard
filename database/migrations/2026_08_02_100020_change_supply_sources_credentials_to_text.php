<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 修复: supply_sources.credentials 列类型错误。
 *
 * 原为 json 列,但模型用 encrypted:array cast:
 * 写入时 json_encode → Crypt::encryptString → 存的是一个加密字符串(非合法 JSON)。
 * MySQL json 列拒绝非法 JSON(SQLSTATE 22032),SQLite 不校验 JSON 故测试/本地未发现。
 * 改为 text 列(存储加密字符串)。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('supply_sources', function (Blueprint $table) {
            $table->text('credentials')->comment('凭证(encrypted:array 加密字符串),结构随 driver 变')->change();
        });
    }

    public function down(): void
    {
        Schema::table('supply_sources', function (Blueprint $table) {
            $table->json('credentials')->comment('凭证(加密存储),结构随 driver 变')->change();
        });
    }
};
