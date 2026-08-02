<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('subsite_profit')
                ->comment('supply=该单由供货API下单产生;null=正常顾客单');
            $table->string('upstream_order_id')->nullable()->after('source')
                ->comment('作为下游拿货时,上游返回的订单号');
            $table->foreignId('upstream_source_id')->nullable()->after('upstream_order_id')
                ->constrained('supply_sources')->nullOnDelete()->comment('作为下游拿货时,货源来源');

            $table->index('source');
            $table->index('upstream_source_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['upstream_source_id']);
            $table->dropColumn(['source', 'upstream_order_id', 'upstream_source_id']);
        });
    }
};
