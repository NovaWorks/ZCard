<?php

namespace App\Console\Commands;

use App\Support\OrderService;
use Illuminate\Console\Command;

class CloseExpiredOrdersCommand extends Command
{
    protected $signature = 'orders:close-expired';

    protected $description = '关闭超时未支付的订单并释放卡密';

    public function handle(OrderService $service): int
    {
        $count = $service->closeExpired();
        $this->info("已关闭 {$count} 个超时订单");

        return self::SUCCESS;
    }
}
