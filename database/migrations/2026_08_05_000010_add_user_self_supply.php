<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自助供货对接:
 * 1. supplier_accounts 关联 user_id(用户自助申请供货凭证)
 * 2. recharges 增加 target(充值目标: balance=个人余额, supply=供货余额)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
        });

        Schema::table('recharges', function (Blueprint $table) {
            $table->string('target', 20)->default('balance')->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_accounts', function (Blueprint $table) {
            $table->dropColumn('user_id');
        });

        Schema::table('recharges', function (Blueprint $table) {
            $table->dropColumn('target');
        });
    }
};
