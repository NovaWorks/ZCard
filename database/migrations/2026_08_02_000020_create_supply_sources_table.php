<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supply_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('运营起的名字,如「主站dujiao」');
            $table->string('driver', 30)->comment('驱动类型:dujiao_next|acg_faka|zcard');
            $table->string('base_url', 255)->comment('上游站点地址');
            $table->json('credentials')->comment('凭证(加密存储),结构随 driver 变');
            $table->string('status', 20)->default('active')->comment('active|disabled');
            $table->json('settings')->nullable()->comment('驱动相关开关:库存模式/同步/定价/发卡等');
            $table->timestamp('last_synced_at')->nullable()->comment('最近同步时间');
            $table->text('last_error')->nullable()->comment('最近一次同步/调用错误');
            $table->bigInteger('balance_cache')->nullable()->comment('上游余额缓存(分)');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'driver']);
            $table->index('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_sources');
    }
};
