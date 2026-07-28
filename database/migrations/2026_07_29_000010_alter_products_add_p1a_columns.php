<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('status');
            $table->unsignedInteger('virtual_sales')->default(0)->after('is_featured');
            $table->json('virtual_reviews')->nullable()->after('virtual_sales');
            $table->unsignedInteger('min_order')->default(1)->after('virtual_reviews');
            $table->unsignedInteger('max_order')->default(0)->after('min_order'); // 0=不限
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_featured', 'virtual_sales', 'virtual_reviews', 'min_order', 'max_order']);
        });
    }
};
