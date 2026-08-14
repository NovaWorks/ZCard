<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\Concerns\MakesHttpRequests;
use App\Supply\Dto\UpstreamCategory;
use App\Supply\Dto\UpstreamFulfillment;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use App\Supply\HmacSigner;
use App\Support\StorefrontConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ZCardDriver implements SupplyDriver
{
    use MakesHttpRequests;

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

    /**
     * 本系统自定义 HMAC 四头签名(同 /api/supply/* 协议,spec §4.2)。
     *
     * $rawBody 传「原始请求体字符串」,md5 只在这里做一次 —— 与服务端
     * SupplyAuth::handle() 的 md5($request->getContent()) 严格对齐。
     * 调用方不要预先 md5(曾因此变成双重哈希,导致恒定 invalid_signature)。
     */
    private function signedHeaders(string $method, string $path, string $rawBody = '', array $query = []): array
    {
        $creds = $this->credentials();
        $ts = (string) time();
        $nonce = 'zcard_'.uniqid();
        // v1.12.90+ 起新口径:签名串追加 query md5 段(上游服务端双口径兼容验签)。
        // query 构建必须与实际发送字节一致(HTTP 客户端按 http_build_query 默认风格)。
        $rawQuery = $query === [] ? '' : http_build_query($query);
        $ss = HmacSigner::buildSignStringWithQuery($method, $path, $rawQuery, $ts, $nonce, md5($rawBody));

        return [
            'X-Supply-Key' => $creds['api_key'],
            'X-Supply-Timestamp' => $ts,
            'X-Supply-Nonce' => $nonce,
            'X-Supply-Signature' => HmacSigner::sign($creds['api_secret'], $ss),
        ];
    }

    public function ping(): array
    {
        try {
            $path = '/api/supply/ping';
            $data = $this->postRaw($path, '', $this->signedHeaders('POST', $path));

            return ['connected' => $data['ok'] ?? false, 'name' => $data['name'] ?? null, 'balance' => $data['balance'] ?? null, 'currency' => $data['currency'] ?? 'CNY'];
        } catch (\Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    public function listCategories(): array
    {
        $path = '/api/supply/categories';
        $data = $this->postRaw($path, '', $this->signedHeaders('POST', $path));

        return collect($data['categories'] ?? [])->map(fn ($c) => new UpstreamCategory(code: (string) $c['id'], name: $c['name'], parentCode: isset($c['parent_id']) ? (string) $c['parent_id'] : null))->all();
    }

    public function supportsIncrementalProductSync(): bool
    {
        return false;
    }

    public function listProducts(
        ?Carbon $updatedAfter,
        int $page,
        bool $fetchStock = false,
        ?callable $progress = null,
    ): array {
        $path = '/api/supply/products';
        $query = ['page' => $page];
        $data = $this->getJson($path, $query, $this->signedHeaders('GET', $path, '', $query));
        // 构建分类 code → name 映射,让商品预览/导入能显示上游真实分类名而非"分类 #id"
        $catNames = [];
        try {
            foreach ($this->listCategories() as $cat) {
                $catNames[$cat->code] = $cat->name;
            }
        } catch (\Throwable $e) {
            // 分类接口失败不阻塞商品拉取
        }
        $items = collect($data['items'] ?? [])->map(fn ($p) => $this->mapProduct($p, $catNames))->all();

        // 分页:上游按 page_size(默认50)分页返回 total,必须推导 has_more,
        // 否则 listAllProducts 只取第 1 页,第 2 页起商品丢失(导入/同步漏商品)。
        $total = (int) ($data['total'] ?? 0);
        $pageSize = (int) ($data['page_size'] ?? 50);
        $hasMore = $pageSize > 0 && ($page * $pageSize) < $total;

        return ['items' => $items, 'total' => $total, 'page' => $page, 'has_more' => $hasMore];
    }

    public function getProduct(string $code): ?UpstreamProduct
    {
        $path = "/api/supply/products/{$code}";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        $p = $data['product'] ?? null;

        return $p ? $this->mapProduct($p) : null;
    }

    public function getStock(string $code, ?string $skuCode = null): int
    {
        $path = "/api/supply/products/{$code}/stock";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));

        return $data['stock'] ?? -1;
    }

    public function createOrder(array $params): UpstreamOrder
    {
        $path = '/api/supply/orders';
        // 签名与发送共用 $bodyStr,保证服务端 md5(原始 body) 与本地口径一致
        $bodyStr = $this->encodeBody([
            'product_id' => (int) $params['product_code'],
            'quantity' => $params['quantity'],
            'downstream_order_no' => $params['downstream_order_no'],
            'callback_url' => $params['callback_url'] ?? null,
        ]);
        $data = $this->postRaw($path, $bodyStr, $this->signedHeaders('POST', $path, $bodyStr));
        $payload = $data['fulfillment'] ?? [];
        $fulfillment = ($payload['status'] ?? '') === 'delivered'
            ? new UpstreamFulfillment(
                status: 'delivered',
                cards: $payload['cards'] ?? [],
                instructions: $payload['instructions'] ?? null,
            )
            : null;

        return new UpstreamOrder(id: (string) $data['supply_order_id'], status: $payload['status'] ?? 'pending', amount: $data['amount'] ?? 0, fulfillment: $fulfillment);
    }

    public function getOrder(string $upstreamOrderId): UpstreamOrder
    {
        $path = "/api/supply/orders/{$upstreamOrderId}";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        $payload = $data['fulfillment'] ?? [];
        $fulfillment = ($payload['status'] ?? '') === 'delivered'
            ? new UpstreamFulfillment(
                status: 'delivered',
                cards: $payload['cards'] ?? [],
                instructions: $payload['instructions'] ?? null,
            )
            : null;

        return new UpstreamOrder(id: $upstreamOrderId, status: $payload['status'] ?? 'pending', amount: $data['amount'] ?? 0, fulfillment: $fulfillment);
    }

    public function cancelOrder(string $upstreamOrderId): bool
    {
        $path = "/api/supply/orders/{$upstreamOrderId}/cancel";
        $data = $this->postRaw($path, '', $this->signedHeaders('POST', $path));

        return $data['ok'] ?? false;
    }

    public function verifyCallback(Request $request): ?array
    {
        $creds = $this->credentials();
        $sig = $request->header('X-Supply-Signature');
        $ts = $request->header('X-Supply-Timestamp');
        $nonce = $request->header('X-Supply-Nonce');
        if (! $sig || ! $ts || ! $nonce) {
            return null;
        }
        $skew = (int) StorefrontConfig::get('supply_timestamp_skew', 300);
        if (! HmacSigner::timestampValid((int) $ts, $skew)) {
            return null;
        }
        $ss = HmacSigner::buildSignString('POST', $request->getPathInfo(), $ts, $nonce, md5($request->getContent() ?: ''));
        if (! HmacSigner::verify($creds['api_secret'], $ss, $sig)) {
            return null;
        }
        // 防重放(安全审计 M-6):验签通过后再写 nonce(只允许使用一次,按货源隔离),
        // 避免未验签请求污染 nonce 缓存。
        if (! Cache::add('upstream_cb:'.$this->source->id.':'.$nonce, 1, $skew)) {
            return null;
        }
        $data = $request->json()->all();

        return [
            'upstream_order_id' => (string) ($data['supply_order_id'] ?? ''),
            'status' => $data['status'] ?? '',
            'cards' => $data['fulfillment']['cards'] ?? [],
            'instructions' => $data['fulfillment']['instructions'] ?? null,
            'downstream_order_no' => $data['downstream_order_no'] ?? null,
        ];
    }

    /** @param array<string, string> $categoryNames */
    private function mapProduct(array $p, array $categoryNames = []): UpstreamProduct
    {
        $categoryCode = isset($p['category_id']) ? (string) $p['category_id'] : null;

        return new UpstreamProduct(
            code: (string) $p['id'],
            name: $p['name'] ?? '',
            price: $p['price'] ?? 0,
            factoryPrice: $p['price'] ?? 0,
            categoryCode: $categoryCode,
            categoryName: $categoryCode !== null ? ($categoryNames[$categoryCode] ?? null) : null,
            description: $p['description'] ?? null,
            cover: $p['cover'] ?? null,
            stockQuantity: -1,
        );
    }
}
