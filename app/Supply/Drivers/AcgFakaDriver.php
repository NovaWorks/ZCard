<?php

namespace App\Supply\Drivers;

use App\Models\SupplySource;
use App\Supply\Contracts\SupplyDriver;
use App\Supply\Drivers\Concerns\MakesHttpRequests;
use App\Supply\Dto\UpstreamFulfillment;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use App\Supply\Exceptions\UpstreamRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class AcgFakaDriver implements SupplyDriver
{
    use MakesHttpRequests;

    private const PRODUCT_ID_TOKEN = '__UPSTREAM_PRODUCT_ID__';

    private const CATEGORY_ID_TOKEN = '__UPSTREAM_CATEGORY_ID__';

    private bool $productUrlTemplateResolved = false;

    private ?string $productUrlTemplate = null;

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
        $url = $this->baseUrl().$path;
        try {
            $resp = Http::asForm()
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->requestTimeout())
                ->post($url, $params);
        } catch (ConnectionException $e) {
            throw UpstreamRequestException::fromConnection($url, $e);
        }

        if (! $resp->successful()) {
            throw UpstreamRequestException::fromHttp($url, $resp->status(), $resp->body());
        }

        $data = $resp->json();
        // acg-faka 业务错误:code != 200 时 msg 含具体原因(如"密钥错误""商户ID不存在")
        if (isset($data['code']) && (int) $data['code'] !== 200) {
            throw UpstreamRequestException::business($url, (string) ($data['msg'] ?? '未知错误'));
        }
        // 响应不是预期 JSON 结构(可能是 WAF 拦截返回 HTML)
        if (! isset($data['code'])) {
            throw UpstreamRequestException::invalidResponse($url, $resp->body());
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
            $this->fillMissingStocks($items, $progress);
        }

        return ['items' => $items, 'total' => count($items), 'page' => 1, 'has_more' => false];
    }

    /** 并发补查缺失库存(仅同步 Job 调用;预览不查避免 4000+ 商品超时) */
    private function fillMissingStocks(array &$items, ?callable $progress = null): void
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
        $completed = 0;
        if ($progress !== null) {
            $progress('fetching_stock', 0, count($missing));
        }
        foreach ($chunks as $chunk) {
            $stockValues = [];
            $stockUrl = $this->baseUrl().'/shared/commodity/stock';
            $responses = Http::pool(fn ($pool) => collect($chunk)->map(
                fn ($dto) => $pool->as($dto->code)->asForm()
                    ->connectTimeout($this->connectTimeout())
                    ->timeout($this->requestTimeout())
                    ->post($stockUrl, $this->signedParams(['code' => $dto->code]))
            ));
            foreach ($responses as $code => $resp) {
                if ($resp instanceof ConnectionException) {
                    throw UpstreamRequestException::fromConnection($stockUrl, $resp);
                }
                if (! $resp->successful()) {
                    throw UpstreamRequestException::fromHttp($stockUrl, $resp->status(), $resp->body());
                }
                $data = $resp->json();
                if (! is_array($data) || ! isset($data['code'])) {
                    throw UpstreamRequestException::invalidResponse($stockUrl, $resp->body());
                }
                if ((int) $data['code'] !== 200) {
                    throw UpstreamRequestException::business($stockUrl, (string) ($data['msg'] ?? '库存查询失败'));
                }
                $stockValues[$code] = (int) ($data['data']['stock'] ?? -1);
            }

            foreach ($chunk as $dto) {
                if (isset($stockValues[$dto->code])) {
                    // UpstreamProduct 为 readonly,不能 clone 后赋值
                    // (PHP 8.3 不允许,8.4+ 才支持)→ 重新构造 DTO 并替换,兼容 8.3/8.4/8.5
                    $rebuilt = new UpstreamProduct(
                        code: $dto->code,
                        name: $dto->name,
                        price: $dto->price,
                        factoryPrice: $dto->factoryPrice,
                        categoryCode: $dto->categoryCode,
                        categoryName: $dto->categoryName,
                        description: $dto->description,
                        cover: $dto->cover,
                        images: $dto->images,
                        isActive: $dto->isActive,
                        skus: $dto->skus,
                        stockQuantity: $stockValues[$dto->code],
                        // 兼容在线更新前已驻留 worker 内存的旧 DTO 定义。
                        productUrl: property_exists($dto, 'productUrl') ? $dto->productUrl : null,
                    );
                    foreach ($items as $i => $it) {
                        if ($it->code === $dto->code) {
                            $items[$i] = $rebuilt;
                            break;
                        }
                    }
                }
            }
            $completed += count($chunk);
            if ($progress !== null) {
                $progress('fetching_stock', $completed, count($missing));
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
            description: $p['description'] ?? ($p['introduce'] ?? null),
            cover: $p['cover'] ?? null,
            // acg-faka 商品 status:1=上架/0=下架(下架商品同步后本地标隐藏)
            isActive: (int) ($p['status'] ?? 1) === 1,
            // items 接口对「卡密自动发货」商品(delivery_way=0)会带 stock 字段,
            // 手动发货商品不带 → 按无限(-1)处理。不读的话预览面板会把所有商品显示成无限库存。
            stockQuantity: isset($p['stock']) ? (int) $p['stock'] : -1,
            productUrl: $this->productUrl($p, $categoryId),
        );
    }

    /**
     * 获取 acg-faka 真实公开商品链接。
     *
     * 对接 API 用的是随机 code，公开页面用的是数值 id，两者不能混用。不同版本/主题
     * 返回的分享链接也不同：新版通常为 /item/{id}，旧版 Toka 为 ?cid=...&mid=...。
     * 每个驱动实例仅请求一次公开详情接口取得 share_url 规则，再套用到本批商品。
     */
    private function productUrl(array $product, mixed $categoryId): ?string
    {
        $productId = (int) ($product['id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }

        if (! $this->productUrlTemplateResolved) {
            $this->productUrlTemplateResolved = true;
            $shareUrl = is_string($product['share_url'] ?? null) ? $product['share_url'] : null;

            if (! $shareUrl) {
                try {
                    $response = Http::acceptJson()->timeout($this->requestTimeout())
                        ->get($this->baseUrl().'/user/api/index/commodityDetail', [
                            'commodityId' => $productId,
                        ]);
                    $shareUrl = $response->successful()
                        ? data_get($response->json(), 'data.share_url')
                        : null;
                } catch (\Throwable) {
                    $shareUrl = null;
                }
            }

            if (is_string($shareUrl) && $shareUrl !== '') {
                $this->productUrlTemplate = $this->inferProductUrlTemplate(
                    $shareUrl,
                    $productId,
                    $categoryId,
                );
            }
        }

        if ($this->productUrlTemplate === null) {
            return null;
        }

        return str_replace(
            [self::PRODUCT_ID_TOKEN, self::CATEGORY_ID_TOKEN],
            [(string) $productId, rawurlencode((string) ($categoryId ?? $product['category_id'] ?? ''))],
            $this->productUrlTemplate,
        );
    }

    /** 只接受同一上游域名的已知分享链接形态，避免把异常响应写成后台外链。 */
    private function inferProductUrlTemplate(string $shareUrl, int $productId, mixed $categoryId): ?string
    {
        $parts = parse_url($shareUrl);
        $sourceHost = parse_url($this->baseUrl(), PHP_URL_HOST);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || strcasecmp((string) ($parts['host'] ?? ''), (string) $sourceHost) !== 0) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '';

        // 新版 acg-faka 的标准分享链接：/item/{数值商品ID}
        $quotedId = preg_quote(rawurlencode((string) $productId), '#');
        if (preg_match("#/item/{$quotedId}/?$#", $path) === 1) {
            $path = preg_replace("#/item/{$quotedId}(/?)$#", '/item/'.self::PRODUCT_ID_TOKEN.'$1', $path);

            return $origin.$path;
        }

        // 旧版主题分享链接：/?cid={分类ID}&mid={数值商品ID}
        parse_str($parts['query'] ?? '', $query);
        if ((string) ($query['mid'] ?? '') !== (string) $productId) {
            return null;
        }
        $query['mid'] = self::PRODUCT_ID_TOKEN;
        if (isset($query['cid']) && (string) $query['cid'] === (string) $categoryId) {
            $query['cid'] = self::CATEGORY_ID_TOKEN;
        }

        return $origin.($path !== '' ? $path : '/').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
