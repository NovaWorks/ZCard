<?php

namespace App\Payment\Contracts;

use App\Payment\PaymentResult;
use Illuminate\Http\Request;

interface PaymentDriver
{
    /**
     * 向网关发起收款。
     * $payable 可能是订单(Order)或充值单(Recharge),两者都实现 Payable 接口,
     * 驱动只需读取单号与金额。
     */
    public function pay(Payable $payable, array $config): PaymentResult;

    public function verifyCallback(Request $request, array $config): ?array;

    public function getConfigFields(): array;

    public function getInfo(): array;

    /**
     * 此驱动支持的支付方式标识列表(收银台展示用)。
     * 标识与前端支付方式映射对应:alipay / wechat / paypal / stripe / qqpay / bank / jdpay / usdt / tron。
     * 单个驱动可支持多种(如易支付聚合通道同时支持 alipay+wxpay)。
     *
     * @param  array  $config  通道配置(config JSON)
     */
    public function getPayTypes(array $config): array;

    /**
     * 此驱动支持的货币 code 列表(spec §5.1)。
     * 法币驱动如支付宝返回 ['CNY'];PayPal 返回其通道配置的目标货币。
     */
    public function getSupportedCurrencies(): array;
}
