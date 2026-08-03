<?php

namespace App\Console\Commands;

use App\Models\Recharge;
use App\Support\OrderService;
use Illuminate\Console\Command;

class CloseExpiredOrdersCommand extends Command
{
    protected $signature = 'orders:close-expired';

    protected $description = '关闭超时未支付的订单/充值单并释放资源';

    public function handle(OrderService $service): int
    {
        $count = $service->closeExpired();
        $this->info("已关闭 {$count} 个超时订单");

        // 同步清理超时未支付的充值单(pending → closed)
        $minutes = (int) (\App\Support\StorefrontConfig::get('order_close_minutes') ?? 30);
        $rechargeCount = Recharge::closeExpired($minutes);
        if ($rechargeCount > 0) {
            $this->info("已关闭 {$rechargeCount} 个超时充值单");
        }

        return self::SUCCESS;
    }
}
