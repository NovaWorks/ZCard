<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 上游商品名可能很长(如「💎【带余额…】美国-香港-…」几百字符),
     * name/slug 由 150 扩容到 500/250,避免货源同步 Data too long 报错。
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('name', 500)->change();
            $table->string('slug', 250)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('name', 150)->change();
            $table->string('slug', 150)->change();
        });
    }
};
