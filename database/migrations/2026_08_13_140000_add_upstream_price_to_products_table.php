<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上游售价快照(分):同步时记录上游售价,用于列表展示
 * 「上游价格/成本/加价」与核对(v1.12.78+ 客户需求)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('upstream_price')->default(0)->after('factory_price')
                ->comment('上游售价快照(分),0=未知');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('upstream_price');
        });
    }
};
