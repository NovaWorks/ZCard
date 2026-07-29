<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->default(1)->constrained('merchants')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 30);
            $table->string('driver', 100);
            $table->json('config')->nullable();
            $table->decimal('fee', 5, 4)->default(0);
            $table->string('fee_type', 10)->default('percent');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['merchant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_channels');
    }
};
