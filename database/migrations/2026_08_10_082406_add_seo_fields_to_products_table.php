<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 商品 SEO 字段:自定义标题/关键词/描述(留空时前端自动用商品名+分类名+摘要组合)。
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('seo_title')->nullable()->after('name');
            $table->string('seo_keywords')->nullable()->after('seo_title');
            $table->text('seo_description')->nullable()->after('seo_keywords');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['seo_title', 'seo_keywords', 'seo_description']);
        });
    }
};
