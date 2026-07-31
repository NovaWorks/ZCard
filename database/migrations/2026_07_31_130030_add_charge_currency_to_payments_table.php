<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->char('charged_currency', 3)->nullable()->after('amount');
            $table->bigInteger('charged_amount')->nullable()->comment('实收·最小单位')->after('charged_currency');
            $table->decimal('channel_exchange_rate', 20, 8)->nullable()->after('charged_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['charged_currency', 'charged_amount', 'channel_exchange_rate']);
        });
    }
};
