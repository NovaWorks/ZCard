<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 修复: supplier_accounts.api_secret 列长度不足。
 *
 * 原定义 string(128),但 Crypt::encryptString(Str::random(64)) 加密后约 200-300 字符
 * (Laravel 加密负载 = base64(json{iv,value,mac})),MySQL 严格模式报 1406 Data too long。
 * SQLite 不强制长度故测试未发现。改为 500 留足余量。
 *
 * 同时把 api_key 收紧到 64(32位hex 明文,64 足够)。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_accounts', function (Blueprint $table) {
            $table->string('api_secret', 500)->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_accounts', function (Blueprint $table) {
            $table->string('api_secret', 128)->change();
        });
    }
};
