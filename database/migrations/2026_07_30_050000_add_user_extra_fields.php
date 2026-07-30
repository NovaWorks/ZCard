<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('qq', 20)->nullable()->after('phone');
            $table->string('avatar')->nullable()->after('qq');
            $table->integer('points')->default(0)->comment('积分')->after('balance');
            $table->unsignedBigInteger('pid')->default(0)->comment('上级ID,0=无')->after('points');
            $table->unsignedBigInteger('group_id')->nullable()->comment('会员等级ID')->after('pid');
            $table->string('login_ip', 45)->nullable()->after('last_login_at');

            $table->index('group_id');
            $table->index('pid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['group_id']);
            $table->dropIndex(['pid']);
            $table->dropColumn(['phone', 'qq', 'avatar', 'points', 'pid', 'group_id', 'login_ip']);
        });
    }
};
