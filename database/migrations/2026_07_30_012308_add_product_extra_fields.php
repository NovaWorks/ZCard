<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('contact_type', 10)->default('email')->comment('email/phone/none 联系方式类型')->after('control_config');
            $table->boolean('send_email')->default(true)->comment('支付后发邮件')->after('contact_type');
            $table->text('delivery_message')->nullable()->comment('手动发货信息(固定卡密/下载链接)')->after('send_email');
            $table->text('leave_message')->nullable()->comment('购买后显示的留言')->after('delivery_message');
            $table->boolean('only_user')->default(false)->comment('仅限会员购买')->after('leave_message');
            $table->integer('purchase_limit')->default(0)->comment('限购数量(0=不限,需登录)')->after('only_user');
            $table->boolean('hide')->default(false)->comment('隐藏商品(不在前台展示)')->after('purchase_limit');
            $table->boolean('level_disable')->default(false)->comment('禁用所有折扣')->after('hide');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'contact_type',
                'send_email',
                'delivery_message',
                'leave_message',
                'only_user',
                'purchase_limit',
                'hide',
                'level_disable',
            ]);
        });
    }
};
