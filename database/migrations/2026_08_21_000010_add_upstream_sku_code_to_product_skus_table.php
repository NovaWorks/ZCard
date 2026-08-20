<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_skus', function (Blueprint $table) {
            $table->text('upstream_sku_code')->nullable()->after('product_id')
                ->comment('上游规格编码；ACG-Faka 使用 JSON 保存 race/sku 选择');
        });
    }

    public function down(): void
    {
        Schema::table('product_skus', function (Blueprint $table) {
            $table->dropColumn('upstream_sku_code');
        });
    }
};
