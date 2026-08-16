<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderService;
use App\Support\StorefrontConfig;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CloseExpiredOrdersPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiration_scan_batches_orders_and_slow_payment_lookup(): void
    {
        StorefrontConfig::setMany([
            'order_close_minutes' => 15,
            'slow_channel_close_grace_minutes' => 60,
        ]);

        $merchant = Merchant::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Expiration scan',
            'slug' => 'expiration-scan',
            'status' => 1,
            'commission_rate' => 0,
        ]);
        $product = Product::create([
            'merchant_id' => $merchant->id,
            'name' => 'Expiration scan product',
            'slug' => 'expiration-scan-product',
            'price' => 100,
            'stock_type' => 'code',
            'status' => 1,
        ]);

        $createdAt = now()->subHour();
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = [
                'order_no' => sprintf('EXP-%04d', $i),
                'merchant_id' => $merchant->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'amount' => 100,
                'status' => 'pending',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }
        Order::insert($rows);

        $protectedOrders = Order::orderBy('id')->limit(5)->get();
        foreach ($protectedOrders as $order) {
            Payment::create([
                'order_id' => $order->id,
                'channel' => 'usdt',
                'amount' => 100,
                'status' => 'pending',
                'created_at' => now()->subMinutes(5),
            ]);
        }

        $paymentQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$paymentQueries): void {
            if (str_contains(strtolower($query->sql), 'payments')) {
                $paymentQueries++;
            }
        });

        $closed = app(OrderService::class)->closeExpired();

        $this->assertSame(245, $closed);
        $this->assertSame(5, Order::where('status', 'pending')->count());
        $this->assertLessThanOrEqual(2, $paymentQueries, '慢通道保护查询应按批次执行,不能随订单数线性增长');
    }
}
