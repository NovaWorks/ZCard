<?php

namespace App\Payment\Contracts;

/**
 * 可支付对象统一抽象:订单(Order)与充值单(Recharge)均实现此接口,
 * 使支付驱动与下单/回调链路可同时服务于"购买发卡"与"余额充值"。
 */
interface Payable
{
    /** 商户业务单号(传给第三方的 out_trade_no),形如 ORD... / RCH... */
    public function getPayableKey(): string;

    /** 金额(分,基础货币) */
    public function getPayableAmount(): int;

    /** 业务类型标识: order / recharge,用于回调分流 */
    public function getPayableType(): string;
}
