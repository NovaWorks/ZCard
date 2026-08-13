<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * price_manual 标记:运营在后台手动改过售价的商品,同步时保护不被上游调价覆盖;
 * 未手动改过的(默认 0)每次同步按加价规则自动跟随上游调价。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('price_manual')->default(false)->after('price')->comment('售价是否被运营手动修改(同步时保护)');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('price_manual');
        });
    }
};
