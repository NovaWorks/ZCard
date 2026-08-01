<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('subsite_id')->nullable()->after('amount_display')->constrained('merchants')->nullOnDelete()->comment('NULL=主站订单');
            $table->string('subsite_domain', 255)->nullable()->after('subsite_id');
            $table->bigInteger('subsite_profit')->default(0)->after('subsite_domain')->comment('分站利润快照(分)');
        });
    }
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['subsite_id', 'subsite_domain', 'subsite_profit']);
        });
    }
};
