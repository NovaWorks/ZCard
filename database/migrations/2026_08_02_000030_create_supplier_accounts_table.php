<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('账号名/公司名');
            $table->string('api_key', 64)->unique()->comment('公开标识(32位hex),可明文返回');
            $table->string('api_secret', 128)->comment('签名密钥(64位hex),加密存储');
            $table->bigInteger('balance')->default(0)->comment('预存余额(分)');
            $table->string('status', 20)->default('active')->comment('active|disabled');
            $table->string('contact')->nullable()->comment('联系方式');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_accounts');
    }
};
