<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentChannel;
use App\Payment\Contracts\PaymentDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PaymentService
{
    public function getEnabledChannels(): Collection
    {
        return PaymentChannel::where('enabled', true)->orderBy('sort')->get();
    }

    public function getAllChannels(): Collection
    {
        return PaymentChannel::orderBy('sort')->get();
    }

    public function createPayment(Order $order, int $channelId): array
    {
        $channel = PaymentChannel::findOrFail($channelId);
        if (! $channel->enabled) {
            throw new \RuntimeException('该支付通道未启用');
        }

        $driver = $this->resolveDriver($channel);
        $result = $driver->pay($order, $channel->config ?? []);

        Payment::create([
            'order_id' => $order->id,
            'channel' => $channel->code,
            'amount' => $order->amount,
            'status' => 'pending',
        ]);

        return $result->toArray();
    }

    public function handleCallback(string $channelCode, Request $request): string
    {
        $channel = PaymentChannel::where('code', $channelCode)->first();
        if (! $channel) {
            return 'fail: channel not found';
        }

        $driver = $this->resolveDriver($channel);
        $data = $driver->verifyCallback($request, $channel->config ?? []);

        if (! $data) {
            return 'fail: verify failed';
        }

        $order = Order::where('order_no', $data['order_no'])->first();
        if (! $order || (int) $order->amount !== (int) $data['amount']) {
            return 'fail: amount mismatch';
        }

        Payment::where('order_id', $order->id)->where('channel', $channelCode)
            ->update(['status' => 'success', 'paid_at' => now(), 'raw' => $request->all()]);

        app(OrderService::class)->markPaid($order->order_no);

        return 'success';
    }

    private function resolveDriver(PaymentChannel $channel): PaymentDriver
    {
        $driverClass = $channel->driver;
        if (! class_exists($driverClass)) {
            throw new \RuntimeException("支付 Driver 不存在: {$driverClass}");
        }
        return new $driverClass();
    }

    public function saveChannelConfig(int $channelId, array $config): void
    {
        PaymentChannel::where('id', $channelId)->update(['config' => $config]);
    }

    public function toggleChannel(int $channelId, bool $enabled): void
    {
        PaymentChannel::where('id', $channelId)->update(['enabled' => $enabled]);
    }
}
