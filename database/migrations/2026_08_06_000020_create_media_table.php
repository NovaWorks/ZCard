<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable()->comment('分类ID(空=未分类)');
            $table->string('original_name', 255)->comment('原始文件名(展示用)');
            $table->string('filename', 255)->comment('磁盘存储文件名(随机串+扩展名)');
            $table->string('path', 255)->comment('disk相对路径,如 media/2026/08/AbCd1234.png');
            $table->string('url', 255)->comment('访问URL,如 /storage/media/2026/08/AbCd1234.png');
            $table->string('mime_type', 100)->comment('MIME 类型,如 image/png');
            $table->bigInteger('size')->comment('文件大小(字节)');
            $table->unsignedInteger('width')->nullable()->comment('图片宽(px),SVG等无尺寸为 null');
            $table->unsignedInteger('height')->nullable()->comment('图片高(px)');
            $table->timestamps();
            $table->softDeletes();

            $table->index('category_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
