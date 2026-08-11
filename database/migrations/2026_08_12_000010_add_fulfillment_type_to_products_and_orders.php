<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('fulfillment_type', 20)->default('auto_card')->after('stock_type')
                ->comment('auto_card/fixed/manual/upstream');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('fulfillment_type_snapshot', 20)->default('auto_card')->after('delivery_status')
                ->comment('下单时的履约类型快照');
            $table->longText('delivery_message_snapshot')->nullable()->after('instructions_snapshot')
                ->comment('固定发货内容快照');
        });

        DB::table('products')->whereNotNull('upstream_source_id')->update(['fulfillment_type' => 'upstream']);
        DB::table('orders')->whereNotNull('upstream_source_id')->update(['fulfillment_type_snapshot' => 'upstream']);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_type_snapshot', 'delivery_message_snapshot']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('fulfillment_type');
        });
    }
};
