<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\Concerns\MakesHttpRequests;
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

        return md5(urldecode(http_build_query($params)).'&key='.$creds['app_key']);
    }

    private function signedPost(string $path, array $params): array
    {
        $creds = $this->credentials();
        // 与官方客户端 Shared::post 一致:app_id + app_key + sign 都放入 body
        $params['app_id'] = $creds['app_id'];
        $params['app_key'] = $creds['app_key'];
        $params['sign'] = $this->sign($params);
        $resp = Http::asForm()->timeout($this->requestTimeout())->post($this->baseUrl().$path, $params);

        if (! $resp->successful()) {
            $hint = $resp->status() === 404
                ? '(可能是上游未配置伪静态/URL重写,请确认 acg-faka 站点地址正确且伪静态已启用)'
                : '';
            throw new \RuntimeException("上游请求失败: HTTP {$resp->status()} {$hint}");
        }

        $data = $resp->json();
        // acg-faka 业务错误:code != 200 时 msg 含具体原因(如"密钥错误""商户ID不存在")
        if (isset($data['code']) && (int) $data['code'] !== 200) {
            throw new \RuntimeException('上游返回错误: '.($data['msg'] ?? '未知错误'));
        }
        // 响应不是预期 JSON 结构(可能是 WAF 拦截返回 HTML)
        if (! isset($data['code'])) {
            $body = $resp->body();
            throw new \RuntimeException('上游返回格式异常(可能被 WAF 拦截或 URL 错误): '.mb_substr($body, 0, 120));
        }

        return $data;
    }

    public function ping(): array
    {
        try {
            $data = $this->signedPost('/shared/authentication/connect', []);
            $ok = ($data['code'] ?? 0) == 200;

            // connect 返回 {shopName, balance}(注意是 shopName 不是 shop.name)
            return ['connected' => $ok, 'name' => $data['data']['shopName'] ?? null, 'balance' => isset($data['data']['balance']) ? (int) round((float) $data['data']['balance'] * 100) : null];
        } catch (\Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
    }

    public function listCategories(): array
    {
        return [];
    }

    public function listProducts(?Carbon $updatedAfter, int $page, bool $fetchStock = false): array
    {
        $data = $this->signedPost('/shared/commodity/items', []);
        $items = [];
        foreach (($data['data'] ?? []) as $cat) {
            foreach ($cat['children'] ?? [] as $p) {
                $items[] = $this->mapProduct($p, $cat['id'] ?? null, $cat['name'] ?? null);
            }
        }

        // 同步模式:items 接口只对「卡密自动发货」商品返回 stock,
        // 手动发货商品(-1)需逐个调 /shared/commodity/stock 补查真实库存(并发 10)。
        if ($fetchStock) {
            $this->fillMissingStocks($items);
        }

        return ['items' => $items, 'total' => count($items), 'page' => 1, 'has_more' => false];
    }

    /** 并发补查缺失库存(仅同步 Job 调用;预览不查避免 4000+ 商品超时) */
    private function fillMissingStocks(array &$items): void
    {
        $missing = [];
        foreach ($items as $dto) {
            if ($dto->stockQuantity === -1) {
                $missing[] = $dto;
            }
        }
        if (empty($missing)) {
            return;
        }

        $chunks = array_chunk($missing, 10);
        foreach ($chunks as $chunk) {
            $stockValues = [];
            try {
                $responses = Http::pool(fn ($pool) => collect($chunk)->map(
                    fn ($dto) => $pool->as($dto->code)->asForm()->timeout($this->requestTimeout())
                        ->post($this->baseUrl().'/shared/commodity/stock', $this->signedParams(['code' => $dto->code]))
                ));
                foreach ($responses as $code => $resp) {
                    $data = $resp->json() ?? [];
                    $stockValues[$code] = (int) ($data['data']['stock'] ?? -1);
                }
            } catch (\Throwable $e) {
                // 补查失败保持 -1(无限),不阻断同步
            }

            foreach ($chunk as $dto) {
                if (isset($stockValues[$dto->code])) {
                    // UpstreamProduct 为 readonly:PHP 8.3 允许 clone 时重新初始化
                    $clone = clone $dto;
                    $clone->stockQuantity = $stockValues[$dto->code];
                    foreach ($items as $i => $it) {
                        if ($it->code === $dto->code) {
                            $items[$i] = $clone;
                            break;
                        }
                    }
                }
            }
        }
    }

    /** 生成带签名的请求参数(供并发补查库存复用) */
    private function signedParams(array $params): array
    {
        $creds = $this->credentials();
        $params['app_id'] = $creds['app_id'];
        $params['app_key'] = $creds['app_key'];
        $params['sign'] = $this->sign($params);

        return $params;
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

    /**
     * acg-faka 把多张卡密用 PHP_EOL 拼成**一个** secret 字符串返回
     * (Service/Bind/Order.php:1209 `$cardc .= $card->secret . PHP_EOL`)。
     * 必须拆成数组,否则 UpstreamOrderService::writeCards 会把 N 张卡当成 1 张写。
     *
     * @return array<int, string>
     */
    private function splitSecret(?string $secret): array
    {
        if ($secret === null || trim($secret) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $secret) ?: []),
            fn ($line) => $line !== ''
        ));
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
        $d = $data['data'] ?? [];
        $cards = $this->splitSecret($d['secret'] ?? null);
        $fulfillment = $cards ? new UpstreamFulfillment(status: 'delivered', cards: $cards) : null;

        return new UpstreamOrder(
            // 必须存上游自己的 trade_no:查单接口是 where("trade_no", ...) 匹配的,
            // 存我们的单号会导致重试时永远查不到(request_no 只是上游的防重键,不可查)。
            id: (string) ($d['tradeNo'] ?? $params['downstream_order_no']),
            status: $fulfillment ? 'delivered' : 'pending',
            amount: isset($d['amount']) ? (int) round((float) $d['amount'] * 100) : 0, // 元→分
            fulfillment: $fulfillment,
        );
    }

    public function getOrder(string $upstreamOrderId): UpstreamOrder
    {
        // acg-faka 的 Kernel 按 '/' 拆段:最后一段是方法名,之前的全部拼成类名。
        // 所以 /shared/commodity/query/{no} 会被解析成不存在的类 Shared\Commodity\Query → 404。
        // 正确形式是方法名结尾 + tradeNo 走 body(Collector 按参数名从 $_REQUEST 注入)。
        $data = $this->signedPost('/shared/commodity/query', ['tradeNo' => $upstreamOrderId]);
        $d = $data['data'] ?? [];
        $cards = $this->splitSecret($d['secret'] ?? null);

        // acg-faka 订单 status 是 int:0=未完成,1=已支付(orderSuccess 时置 1)。
        // 它没有"已取消"状态,故不映射 canceled。
        // 必须 status=1 才认发货 —— 否则 UpstreamOrderService 会凭 fulfillment 就写卡。
        $delivered = (int) ($d['status'] ?? 0) === 1 && $cards !== [];

        return new UpstreamOrder(
            id: $upstreamOrderId,
            status: $delivered ? 'delivered' : 'pending',
            amount: 0,
            fulfillment: $delivered ? new UpstreamFulfillment(status: 'delivered', cards: $cards) : null,
        );
    }

    public function cancelOrder(string $upstreamOrderId): bool
    {
        return false;
    }

    public function verifyCallback(Request $request): ?array
    {
        return null;
    }

    private function mapProduct(array $p, $categoryId = null, ?string $categoryName = null): UpstreamProduct
    {
        return new UpstreamProduct(
            code: $p['code'] ?? (string) ($p['id'] ?? ''),
            name: $p['name'] ?? '',
            price: isset($p['price']) ? (int) round((float) $p['price'] * 100) : 0,
            factoryPrice: isset($p['factory_price']) ? (int) round((float) $p['factory_price'] * 100) : 0,
            categoryCode: $categoryId !== null ? (string) $categoryId : ($p['category_id'] ?? null),
            categoryName: $categoryName,
            description: $p['introduce'] ?? ($p['description'] ?? null),
            cover: $p['cover'] ?? null,
            isActive: true,
            // items 接口对「卡密自动发货」商品(delivery_way=0)会带 stock 字段,
            // 手动发货商品不带 → 按无限(-1)处理。不读的话预览面板会把所有商品显示成无限库存。
            stockQuantity: isset($p['stock']) ? (int) $p['stock'] : -1,
        );
    }
}
