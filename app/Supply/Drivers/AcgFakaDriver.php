<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * acg-faka 上游驱动(spec §3.3)
 * 鉴权:MD5,sign=md5(ksort去空值参数+&key=app_key)
 * 端点:/shared/commodity/*
 * HTTP 调用实现见 Phase 3 Task。
 */
class AcgFakaDriver implements SupplyDriver
{
    public function __construct(public readonly SupplySource $source) {}

    public static function configSchema(): array
    {
        return [
            'base_url' => ['type' => 'url', 'label' => '站点地址', 'required' => true],
            'app_id' => ['type' => 'number', 'label' => 'App ID', 'required' => true, 'help' => '对方站用户ID'],
            'app_key' => ['type' => 'secret', 'label' => 'App Key', 'required' => true],
        ];
    }

    public static function info(): array
    {
        return ['name' => 'ACG发卡(acg-faka)', 'icon' => '🎴'];
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
        throw new \RuntimeException("AcgFakaDriver::{$method} 待 Phase 3 实现");
    }
}
