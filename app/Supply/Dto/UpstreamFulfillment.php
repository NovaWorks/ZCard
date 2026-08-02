<?php

namespace App\Supply\Dto;

/** 上游发货物(卡密等) */
class UpstreamFulfillment
{
    /**
     * @param  string[]  $cards  卡密内容数组
     */
    public function __construct(
        public readonly string $type = 'auto',     // auto|manual
        public readonly string $status = 'pending', // pending|delivered
        public readonly array $cards = [],
        public readonly ?string $deliveredAt = null,
    ) {}

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }
}
