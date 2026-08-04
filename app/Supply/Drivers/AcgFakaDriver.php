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
use Illuminate\Support\Facades\Http;

class AcgFakaDriver implements SupplyDriver
{
    use MakesHttpRequests;

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

    /**
     * acg-faka MD5 签名(完全对齐官方 Str::generateSignature + 客户端 Shared::post):
     * 参数(含 app_id、app_key,去掉 sign/空值)ksort → http_build_query → urldecode
     * → 末尾接 &key=app_key → md5。
     *
     * 官方客户端 post() 会把 app_id + app_key 都放入 body 再签名,服务端 unsafePost()
     * 收到同样参数用数据库 app_key 重算,两边一致。故此处保持与官方完全相同的行为。
     */
    private function sign(array $params): string
    {
        $creds = $this->credentials();
        $params['app_id'] = $creds['app_id'];
        $params['app_key'] = $creds['app_key'];
        unset($params['sign']);
        ksort($params);
        $params = array_filter($params, fn ($v) => $v !== '' && $v !== null);
        return md5(urldecode(http_build_query($params)) . '&key=' . $creds['app_key']);
    }

    private function signedPost(string $path, array $params): array
    {
        $creds = $this->credentials();
        // 与官方客户端 Shared::post 一致:app_id + app_key + sign 都放入 body
        $params['app_id'] = $creds['app_id'];
        $params['app_key'] = $creds['app_key'];
        $params['sign'] = $this->sign($params);
        $resp = Http::asForm()->timeout($this->requestTimeout())->post($this->baseUrl() . $path, $params);

        if (! $resp->successful()) {
            $hint = $resp->status() === 404
                ? '(可能是上游未配置伪静态/URL重写,请确认 acg-faka 站点地址正确且伪静态已启用)'
                : '';
            throw new \RuntimeException("上游请求失败: HTTP {$resp->status()} {$hint}");
        }

        $data = $resp->json();
        // acg-faka 业务错误:code != 200 时 msg 含具体原因(如"密钥错误""商户ID不存在")
        if (isset($data['code']) && (int) $data['code'] !== 200) {
            throw new \RuntimeException('上游返回错误: ' . ($data['msg'] ?? '未知错误'));
        }
        // 响应不是预期 JSON 结构(可能是 WAF 拦截返回 HTML)
        if (! isset($data['code'])) {
            $body = $resp->body();
            throw new \RuntimeException('上游返回格式异常(可能被 WAF 拦截或 URL 错误): ' . mb_substr($body, 0, 120));
        }

        return $data;
    }

    public function ping(): array
    {
        try {
            $data = $this->signedPost('/shared/authentication/connect', []);
            $ok = ($data['code'] ?? 0) == 200;
            return ['connected' => $ok, 'name' => $data['data']['shop']['name'] ?? null, 'balance' => isset($data['data']['balance']) ? (int) round((float) $data['data']['balance'] * 100) : null];
        } catch (\Throwable $e) { return ['connected' => false, 'error' => $e->getMessage()]; }
    }

    public function listCategories(): array { return []; }

    public function listProducts(?Carbon $updatedAfter, int $page): array
    {
        $data = $this->signedPost('/shared/commodity/items', []);
        $items = [];
        foreach (($data['data'] ?? []) as $cat) {
            foreach ($cat['children'] ?? [] as $p) {
                $items[] = $this->mapProduct($p, $cat['id'] ?? null);
            }
        }
        return ['items' => $items, 'total' => count($items), 'page' => 1, 'has_more' => false];
    }

    public function getProduct(string $code): ?UpstreamProduct
    {
        $data = $this->signedPost('/shared/commodity/item', ['code' => $code]);
        return isset($data['data']) ? $this->mapProduct($data['data']) : null;
    }

    public function getStock(string $code, ?string $skuCode = null): int
    {
        $data = $this->signedPost('/shared/commodity/stock', ['code' => $code]);
        return (int) ($data['data']['stock'] ?? -1);
    }

    public function createOrder(array $params): UpstreamOrder
    {
        $data = $this->signedPost('/shared/commodity/trade', [
            'shared_code' => $params['product_code'],
            'num' => $params['quantity'],
            'contact' => $params['contact'] ?? '',
            'request_no' => $params['downstream_order_no'],
            'card_id' => 0,
        ]);
        $secret = $data['data']['secret'] ?? null;
        $fulfillment = $secret ? new UpstreamFulfillment(status: 'delivered', cards: [$secret]) : null;
        return new UpstreamOrder(id: $params['downstream_order_no'], status: $fulfillment ? 'delivered' : 'pending', amount: 0, fulfillment: $fulfillment);
    }

    public function getOrder(string $upstreamOrderId): UpstreamOrder
    {
        $data = $this->signedPost("/shared/commodity/query/{$upstreamOrderId}", []);
        $d = $data['data'] ?? [];
        $cards = ! empty($d['secret']) ? [$d['secret']] : [];
        return new UpstreamOrder(id: $upstreamOrderId, status: $d['status'] ?? 'pending', amount: 0, fulfillment: $cards ? new UpstreamFulfillment(status: 'delivered', cards: $cards) : null);
    }

    public function cancelOrder(string $upstreamOrderId): bool { return false; }

    public function verifyCallback(Request $request): ?array { return null; }

    private function mapProduct(array $p, $categoryId = null): UpstreamProduct
    {
        return new UpstreamProduct(
            code: $p['code'] ?? (string) ($p['id'] ?? ''),
            name: $p['name'] ?? '',
            price: isset($p['price']) ? (int) round((float) $p['price'] * 100) : 0,
            factoryPrice: isset($p['factory_price']) ? (int) round((float) $p['factory_price'] * 100) : 0,
            categoryCode: $categoryId !== null ? (string) $categoryId : ($p['category_id'] ?? null),
            description: $p['introduce'] ?? ($p['description'] ?? null),
            cover: $p['cover'] ?? null,
            isActive: true,
        );
    }
}
