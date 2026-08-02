<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * dujiao-next 上游驱动(spec §3.3)
 * 鉴权:HMAC-SHA256,三头 Dujiao-Next-Api-Key/Timestamp/Signature
 * 端点:/api/v1/upstream/*
 * HTTP 调用实现见 Phase 3 Task。
 */
class DujiaoNextDriver implements SupplyDriver
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
        return ['name' => '独角数卡(dujiao-next)', 'icon' => '🦄'];
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

    /** Phase 3 实现 HTTP 调用前抛此异常 */
    private function notImplemented(string $method): mixed
    {
        throw new \RuntimeException("DujiaoNextDriver::{$method} 待 Phase 3 实现");
    }
}
