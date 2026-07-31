<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->bigInteger('amount')->default(0)->comment('申请提现金额,单位分');
            $table->bigInteger('actual_amount')->default(0)->comment('实际到账金额(扣手续费后),单位分');
            $table->bigInteger('fee')->default(0)->comment('手续费,单位分');
            $table->string('method', 20)->default('alipay')->comment('alipay/wechat/usdt');
            $table->string('account', 200)->default('')->comment('收款账号');
            $table->string('account_name', 50)->default('')->comment('收款人姓名');
            $table->string('status', 20)->default('pending')->comment('pending/approved/rejected');
            $table->string('reject_reason', 200)->nullable()->comment('驳回理由');
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete()->comment('审核管理员');
            $table->timestamp('processed_at')->nullable()->comment('处理时间');
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
