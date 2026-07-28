<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * 账号由 `php artisan zcard:install` 创建，此处不建账号（spec §7.2）。
     * 演示数据（商品/卡密样例）留给后续 Phase；生产环境不应跑 seed。
     */
    public function run(): void
    {
        // $this->call([]);
    }
}
