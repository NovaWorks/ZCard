<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsite_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->enum('type', ['subdomain', 'custom'])->default('custom');
            $table->string('verification_token', 128)->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'failed'])->default('pending');
            $table->enum('status', ['pending_review', 'active', 'disabled'])->default('pending_review');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique('domain');
            $table->index(['status', 'verification_status']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('subsite_domains');
    }
};
