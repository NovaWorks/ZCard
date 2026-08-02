<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\SupplySource;
use App\Supply\UpstreamOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 异步拿货任务(spec §5.3)
 * 退避重试 5 次(10s/30s/1min/5min/15min)。
 */
class FetchFromUpstream implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function __construct(public readonly int $orderId) {}

    public function handle(UpstreamOrderService $service): void
    {
        $order = Order::find($this->orderId);
        if (! $order || $order->delivery_status === 'delivered') {
            return;
        }

        $source = $order->upstream_source_id ? SupplySource::find($order->upstream_source_id) : null;
        if (! $source) {
            $source = $order->product?->upstream_source_id ? SupplySource::find($order->product->upstream_source_id) : null;
        }
        if (! $source) {
            return;
        }

        $service->fetchFromUpstream($order, $source);

        if ($order->fresh()->delivery_status !== 'delivered' && $this->attempts() >= $this->tries) {
            $service->handleTimeout($order, $source);
        }
    }
}
