<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\Concerns\MakesHttpRequests;
use App\Supply\Dto\UpstreamCategory;
use App\Supply\Dto\UpstreamFulfillment;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DujiaoNextDriver implements SupplyDriver
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
        return ['name' => '独角数卡(dujiao-next)', 'icon' => '🦄'];
    }

    /**
     * dujiao-next HMAC 三头签名(spec §3.3):
     * sign = hex(HMAC_SHA256(api_secret, "METHOD\nPATH\nts\nmd5(body)"))
     *
     * $rawBody 传「原始请求体字符串」,md5 只在这里做一次 —— 与本驱动
     * verifyCallback() 里 md5($request->getContent()) 的口径保持一致。
     * 调用方不要预先 md5(曾因此变成双重哈希)。
     */
    private function signedHeaders(string $method, string $path, string $rawBody = ''): array
    {
        $creds = $this->credentials();
        $ts = (string) time();
        $signString = implode("\n", [$method, $path, $ts, md5($rawBody)]);
        $sig = hash_hmac('sha256', $signString, $creds['api_secret']);

        return [
            'Dujiao-Next-Api-Key' => $creds['api_key'],
            'Dujiao-Next-Timestamp' => $ts,
            'Dujiao-Next-Signature' => $sig,
        ];
    }

    public function ping(): array
    {
        try {
            $path = '/api/v1/upstream/ping';
            $data = $this->postRaw($path, '', $this->signedHeaders('POST', $path));

            return [
                'connected' => $data['ok'] ?? false,
                'name' => $data['site_name'] ?? null, // 上游返回 site_name
                'balance' => isset($data['balance']) ? (int) round((float) $data['balance'] * 100) : null,
                'currency' => $data['currency'] ?? 'CNY',
            ];
        } catch (\Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    public function listCategories(): array
    {
        $path = '/api/v1/upstream/categories';
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));

        return collect($data['categories'] ?? [])->map(fn ($c) => new UpstreamCategory(
            code: (string) $c['id'], name: $c['name'], parentCode: isset($c['parent_id']) ? (string) $c['parent_id'] : null,
            icon: $c['icon'] ?? null, sort: $c['sort_order'] ?? 0,
        ))->all();
    }

    public function listProducts(?Carbon $updatedAfter, int $page): array
    {
        $path = '/api/v1/upstream/products';
        $query = ['page' => $page, 'page_size' => 50];
        if ($updatedAfter) {
            $query['updated_after'] = $updatedAfter->toIso8601String();
        }
        $data = $this->getJson($path, $query, $this->signedHeaders('GET', $path));
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

        return ['items' => $items, 'total' => $data['total'] ?? 0, 'page' => $page, 'has_more' => ($page * 50) < ($data['total'] ?? 0)];
    }

    public function getProduct(string $code): ?UpstreamProduct
    {
        $path = "/api/v1/upstream/products/{$code}";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));

        return isset($data['product']) ? $this->mapProduct($data['product']) : null;
    }

    public function getStock(string $code, ?string $skuCode = null): int
    {
        $p = $this->getProduct($code);

        return $p?->stockQuantity ?? -1;
    }

    public function createOrder(array $params): UpstreamOrder
    {
        $path = '/api/v1/upstream/orders';

        // dujiao-next 按 sku_id 下单(不是商品 id)!先拉商品详情取第一个启用 SKU 的 id。
        // 显式传入的 sku_code 优先,否则用商品第一个 SKU。
        $skuId = $params['sku_code'] ?? null;
        if (! $skuId) {
            $product = $this->getProduct((string) $params['product_code']);
            $skuId = ! empty($product->skus) ? $product->skus[0]['code'] : null;
        }

        // 签名与发送共用 $bodyStr,保证上游 md5(原始 body) 与本地口径一致
        $bodyStr = $this->encodeBody([
            'sku_id' => (int) ($skuId ?? $params['product_code']),
            'quantity' => $params['quantity'],
            'downstream_order_no' => $params['downstream_order_no'],
            'callback_url' => $params['callback_url'] ?? null,
        ]);
        $data = $this->postRaw($path, $bodyStr, $this->signedHeaders('POST', $path, $bodyStr));

        return new UpstreamOrder(
            id: (string) ($data['order_id'] ?? ''),
            status: $data['status'] ?? 'pending',
            amount: isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : 0,
            currency: $data['currency'] ?? 'CNY',
        );
    }

    public function getOrder(string $upstreamOrderId): UpstreamOrder
    {
        $path = "/api/v1/upstream/orders/{$upstreamOrderId}";
        $data = $this->getJson($path, [], $this->signedHeaders('GET', $path));
        $fulfillment = null;
        if (isset($data['fulfillment']) && ($data['fulfillment']['status'] ?? '') === 'delivered') {
            $cards = [];
            $payload = $data['fulfillment']['payload'] ?? null;
            if ($payload) {
                $cards[] = $payload;
            }
            $fulfillment = new UpstreamFulfillment(status: 'delivered', cards: $cards, deliveredAt: $data['fulfillment']['delivered_at'] ?? null);
        }

        return new UpstreamOrder(
            id: (string) ($data['order_id'] ?? $upstreamOrderId),
            status: $data['status'] ?? 'pending',
            amount: isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : 0,
            currency: $data['currency'] ?? 'CNY',
            fulfillment: $fulfillment,
        );
    }

    public function cancelOrder(string $upstreamOrderId): bool
    {
        $path = "/api/v1/upstream/orders/{$upstreamOrderId}/cancel";
        $data = $this->postRaw($path, '', $this->signedHeaders('POST', $path));

        return $data['ok'] ?? false;
    }

    public function verifyCallback(Request $request): ?array
    {
        $creds = $this->credentials();
        $sig = $request->header('Dujiao-Next-Signature');
        $ts = $request->header('Dujiao-Next-Timestamp');
        // 上游(dujiao-next)发回调时签名 path 固定为 /api/v1/upstream/callback,
        // 不是我们接收回调的实际路径,否则签名校验恒失败。
        $path = '/api/v1/upstream/callback';
        $expected = hash_hmac('sha256', implode("\n", ['POST', $path, $ts, md5($request->getContent())]), $creds['api_secret']);
        if (! hash_equals($expected, $sig)) {
            return null;
        }
        $data = $request->json()->all();
        $cards = [];
        if (($data['fulfillment']['payload'] ?? null)) {
            $cards[] = $data['fulfillment']['payload'];
        }

        return [
            'upstream_order_id' => (string) ($data['order_id'] ?? ''),
            'status' => $data['status'] ?? '',
            'cards' => $cards,
            'downstream_order_no' => $data['downstream_order_no'] ?? null,
        ];
    }

    /**
     * @param  array<string, string>  $catNames  分类 code → name 映射(用于填充 categoryName)
     */
    private function mapProduct(array $p, array $catNames = []): UpstreamProduct
    {
        // dujiao-next 库存/价格在 SKU 层,商品级 price_amount 为默认售价。
        // 填充 skus(取启用的 SKU),stockQuantity 取第一个启用 SKU。
        $skus = [];
        foreach (($p['skus'] ?? []) as $s) {
            if (empty($s['is_active'])) {
                continue;
            }
            $skus[] = [
                'code' => (string) ($s['id'] ?? ''),          // SKU id,下单用
                'name' => $s['sku_code'] ?? (string) ($s['id'] ?? ''),
                'price' => isset($s['price_amount']) ? (int) round((float) $s['price_amount'] * 100) : 0,
                'stock_quantity' => (int) ($s['stock_quantity'] ?? -1),
                'is_active' => true,
            ];
        }

        $categoryCode = isset($p['category_id']) ? (string) $p['category_id'] : null;

        return new UpstreamProduct(
            code: (string) ($p['id'] ?? ''),
            name: $p['title'] ?? '',
            price: isset($p['price_amount']) ? (int) round((float) $p['price_amount'] * 100) : 0,
            // wholesale_prices 是批发价阶梯 {min_quantity, unit_price},取第一档 unit_price 作为拿货价
            factoryPrice: isset($p['wholesale_prices'][0]['unit_price'])
                ? (int) round((float) $p['wholesale_prices'][0]['unit_price'] * 100)
                : (isset($p['price_amount']) ? (int) round((float) $p['price_amount'] * 100) : 0),
            categoryCode: $categoryCode,
            categoryName: $categoryCode !== null ? ($catNames[$categoryCode] ?? null) : null,
            description: $p['description'] ?? null,
            cover: $p['images'][0] ?? null,
            images: $p['images'] ?? [],
            isActive: $p['is_active'] ?? true,
            skus: $skus,
            stockQuantity: ! empty($skus) ? $skus[0]['stock_quantity'] : -1,
        );
    }
}
