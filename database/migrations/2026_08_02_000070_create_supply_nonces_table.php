<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supply_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 64)->unique()->comment('随机串,防重放');
            $table->timestamp('expires_at')->comment('过期时间,5分钟后');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_nonces');
    }
};
