<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'created_at', 'id'], 'orders_expiration_scan_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(
                ['order_id', 'status', 'channel', 'created_at'],
                'payments_slow_pending_scan_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_slow_pending_scan_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_expiration_scan_index');
        });
    }
};
