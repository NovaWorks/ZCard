<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('dedup_hash', 64)->nullable()->after('content_hash');
            $table->dropUnique(['product_id', 'content_hash']);
            $table->index(['product_id', 'content_hash'], 'cards_product_content_hash_index');
        });

        DB::table('cards')->update(['dedup_hash' => DB::raw('content_hash')]);

        Schema::table('cards', function (Blueprint $table) {
            // dedup_hash=NULL 时允许重复；有值时仍以唯一索引防止并发重复导入。
            $table->unique(['product_id', 'dedup_hash'], 'cards_product_dedup_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropUnique('cards_product_dedup_hash_unique');
            $table->dropIndex('cards_product_content_hash_index');
            $table->dropColumn('dedup_hash');
            $table->unique(['product_id', 'content_hash']);
        });
    }
};
