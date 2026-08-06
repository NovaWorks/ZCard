<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('number_hash', 64)->nullable()->unique()
                ->comment('靓号自选:靓号第一段明文 sha256(跨商品全局唯一/查号);普通卡为 null');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('number_hash');
        });
    }
};
