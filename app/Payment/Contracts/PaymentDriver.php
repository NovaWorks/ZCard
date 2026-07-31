<?php

namespace App\Payment\Contracts;

use App\Models\Order;
use App\Payment\PaymentResult;
use Illuminate\Http\Request;

interface PaymentDriver
{
    public function pay(Order $order, array $config): PaymentResult;
    public function verifyCallback(Request $request, array $config): ?array;
    public function getConfigFields(): array;
    public function getInfo(): array;

    /**
     * 此驱动支持的货币 code 列表(spec §5.1)。
     * 法币驱动如支付宝返回 ['CNY'];PayPal 返回其通道配置的目标货币。
     */
    public function getSupportedCurrencies(): array;
}
