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
}
