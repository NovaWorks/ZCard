<?php

namespace App\Listeners;

use App\Events\OrderPaid;
use App\Supply\UpstreamOrderService;

class FetchFromUpstreamOnOrderPaid
{
    public function __construct(private readonly UpstreamOrderService $service) {}

    public function handle(OrderPaid $event): void
    {
        if (! config('zcard.features.supply') || ! config('zcard.supply.upstream_enabled')) {
            return;
        }

        $order = $event->order;
        // 只有从上游货源来的商品才拿货
        if ($order->product && $order->product->upstream_source_id) {
            $this->service->fulfill($order);
        }
    }
}
