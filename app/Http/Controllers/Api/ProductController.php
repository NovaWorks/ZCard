<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pageSize = (int) StorefrontConfig::get('page_size');
        $order = $request->input('order', StorefrontConfig::get('default_order'));

        $query = Product::where('status', true)
            ->with(['skus' => fn ($q) => $q->where('status', true)->orderBy('sort')])
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')]);

        if ($categoryId = $request->input('category')) {
            $query->where('category_id', $categoryId);
        }

        // 分站可见性过滤:排除分站显式下架(is_listed=false)的商品(spec §4)。
        $subsite = $request->attributes->get('subsite');
        if ($subsite) {
            $excludedIds = \App\Models\SubsiteProductSetting::where('merchant_id', $subsite->id)
                ->where('is_listed', false)->pluck('product_id')->toArray();
            if ($excludedIds) {
                $query->whereNotIn('id', $excludedIds);
            }
        }

        $query = match ($order) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            'sort' => $query->orderBy('sort'),
            default => $query->latest(),
        };

        $products = $query->paginate($pageSize);
        $products->getCollection()->transform(fn ($p) => $this->transform($p));

        return response()->json($products);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->where('status', true)
            ->with(['skus' => fn ($q) => $q->where('status', true)->orderBy('sort')])
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')])
            ->firstOrFail();

        return response()->json($this->transform($product, true));
    }

    public function featured(Request $request): JsonResponse
    {
        $count = (int) ($request->input('limit', StorefrontConfig::get('featured_count')));
        $products = Product::where('status', true)->where('is_featured', true)
            ->latest()->limit($count)
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')])
            ->get()->map(fn ($p) => $this->transform($p));

        return response()->json($products);
    }

    /** 统一输出格式:金额分,加 sales/stock,注入显示货币字段(spec §3.5)。 */
    private function transform(Product $p, bool $detail = false): array
    {
        // 解析当前显示货币(display.currency 中间件写入的 request attribute)
        $svc = app(\App\Support\CurrencyService::class);
        $cur = request()->attributes->get('currency') ?? $svc->getBaseCurrency();
        // 分站定价:若请求来自分站(subsite request attribute),按分站定价引擎计算生效价(spec §4)。
        $subsite = request()->attributes->get('subsite');
        $effectivePrice = (int) $p->price;
        if ($subsite) {
            $pricing = app(\App\Support\SubsitePricingService::class)->resolveUnitPrice($p, null, $subsite);
            $effectivePrice = $pricing['price'];
        }
        $conv = $svc->convert($effectivePrice, $cur);

        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'cover' => $p->cover,
            'price' => $effectivePrice,                    // 兼容旧字段(=基础货币分,分站含加价)
            'price_base' => $effectivePrice,
            'price_display' => $conv['amount'],
            'display_currency' => $conv['currency'],
            'exchange_rate' => $conv['rate'],
            'stock' => (int) $p->stock,
            'sales' => $p->displaySales(),
            'is_featured' => (bool) $p->is_featured,
        ];
        if ($detail) {
            $data = array_merge($data, [
                'description' => $p->description,
                'images' => $p->images ?? [],
                'category' => $p->category?->only(['id', 'name', 'slug']),
                'skus' => $p->skus->map(function ($s) use ($svc, $cur) {
                    $sconv = $svc->convert((int) $s->price, $cur);
                    return [
                        'id' => $s->id, 'name' => $s->name,
                        'price' => (int) $s->price,
                        'price_base' => (int) $s->price,
                        'price_display' => $sconv['amount'],
                        'display_currency' => $sconv['currency'],
                        'exchange_rate' => $sconv['rate'],
                        'stock' => (int) $p->stock,
                    ];
                }),
                'virtual_reviews' => $p->virtual_reviews,
                'min_order' => $p->min_order, 'max_order' => $p->max_order,
                'stock_type' => $p->stock_type, 'delivery_mode' => $p->delivery_mode,
                'control_config' => $p->control_config ?? [],
                'member_price' => is_array($p->member_price)
                    ? array_map(fn ($price) => $svc->convert((int) $price, $cur)['amount'], $p->member_price)
                    : $p->member_price,
                'member_price_base' => $p->member_price,
                'contact_type' => $p->contact_type ?? 'email',
                'only_user' => (bool) $p->only_user,
                'send_email' => (bool) $p->send_email,
                'leave_message' => $p->leave_message,
                'purchase_limit' => $p->purchase_limit ?? 0,
            ]);
        }
        return $data;
    }
}
