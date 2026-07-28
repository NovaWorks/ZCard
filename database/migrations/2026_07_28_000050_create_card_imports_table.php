<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('card_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 255)->nullable()->comment('文件名/来源');
            $table->unsignedInteger('total')->default(0)->comment('文件总行数');
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 20)->default('running')->comment('running/completed/failed');
            $table->json('error_log')->nullable()->comment('失败明细');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_imports');
    }
};
