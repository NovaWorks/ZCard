<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ZCard 上游驱动(spec §3.3) —— 用于「自己对接自己」或对接另一个 ZCard 实例
 * 鉴权:本系统自定义 HMAC(同 /api/supply/* 协议)
 * 端点:/api/supply/*
 * HTTP 调用实现见 Phase 3 Task。
 */
class ZCardDriver implements SupplyDriver
{
    public function __construct(public readonly SupplySource $source) {}

    public static function configSchema(): array
    {
        return [
            'base_url' => ['type' => 'url', 'label' => '站点地址', 'required' => true],
            'api_key' => ['type' => 'text', 'label' => 'API Key', 'required' => true],
            'api_secret' => ['type' => 'secret', 'label' => 'API Secret', 'required' => true],
        ];
    }

    public static function info(): array
    {
        return ['name' => 'ZCard', 'icon' => '🃏'];
    }

    public function ping(): array { return $this->notImplemented('ping'); }
    public function listCategories(): array { return $this->notImplemented('listCategories'); }
    public function listProducts(?Carbon $updatedAfter, int $page): array { return $this->notImplemented('listProducts'); }
    public function getProduct(string $code): ?UpstreamProduct { return $this->notImplemented('getProduct'); }
    public function getStock(string $code, ?string $skuCode = null): int { return $this->notImplemented('getStock'); }
    public function createOrder(array $params): UpstreamOrder { return $this->notImplemented('createOrder'); }
    public function getOrder(string $upstreamOrderId): UpstreamOrder { return $this->notImplemented('getOrder'); }
    public function cancelOrder(string $upstreamOrderId): bool { return $this->notImplemented('cancelOrder'); }
    public function verifyCallback(Request $request): ?array { return $this->notImplemented('verifyCallback'); }

    private function notImplemented(string $method): mixed
    {
        throw new \RuntimeException("ZCardDriver::{$method} 待 Phase 3 实现");
    }
}
