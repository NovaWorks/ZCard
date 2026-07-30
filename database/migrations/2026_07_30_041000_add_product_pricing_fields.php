<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('factory_price')
                ->default(0)
                ->comment('成本价，单位分')
                ->after('price');
            $table->unsignedBigInteger('draft_premium')
                ->default(0)
                ->comment('预选卡密默认加价，单位分')
                ->after('factory_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['factory_price', 'draft_premium']);
        });
    }
};
