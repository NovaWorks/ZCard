<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('import_id')->nullable()->constrained('card_imports')->nullOnDelete();
            $table->text('content')->comment('应用层加密密文');
            $table->string('content_hash', 64)->comment('sha256 明文，去重索引用');
            $table->string('status', 10)->default('unused')->comment('unused/locked/used/disabled');
            // order_id 不加外键约束（orders 表在后续迁移创建），仅建索引(spec 计划 Task8 Step4)
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->timestamp('locked_at')->nullable()->comment('锁定发货超时释放用');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            // 产品内唯一(spec §6.1 决策3)：同一产品内卡密不重复，跨产品允许相同
            $table->unique(['product_id', 'content_hash']);
            $table->index(['product_id', 'status']); // 库存查询/发货热路径
            $table->index('import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
