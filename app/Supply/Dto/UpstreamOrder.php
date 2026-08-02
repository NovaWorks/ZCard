<?php

namespace App\Supply\Dto;

/** 上游订单(驱动统一输出) */
class UpstreamOrder
{
    public function __construct(
        public readonly string $id,                // 上游订单号
        public readonly string $status,            // pending|paid|delivered|canceled
        public readonly int $amount,               // 实付(分)
        public readonly string $currency = 'CNY',
        public readonly ?UpstreamFulfillment $fulfillment = null,
    ) {}
}
