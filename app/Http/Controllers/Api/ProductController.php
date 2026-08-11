<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\SubsiteProductSetting;
use App\Support\CurrencyService;
use App\Support\StorefrontConfig;
use App\Support\SubsitePricingService;
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
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')])
            ->withMin(['cards as premium_min' => fn ($q) => $q->where('status', 'unused')->whereNotNull('price')], 'price');

        // 关键词搜索(商品名/描述)
        if ($keyword = $request->input('keyword')) {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            });
        }

        if ($categoryId = $request->input('category')) {
            // 父分类查询包含所有子分类的商品(谷歌邮箱下挂企业邮箱/个人邮箱的商品也要查到)
            $ids = Category::where('id', $categoryId)
                ->orWhere('parent_id', $categoryId)
                ->pluck('id');
            $query->whereIn('category_id', $ids);
        }

        // 缺货商品过滤(后台「显示缺货商品」关闭时):
        // 本地商品 = 无 unused 卡;上游商品 = stock_cache 为 0(-1 无限、null 未知均显示)
        if (! StorefrontConfig::get('show_out_of_stock', true)) {
            $query->where(function ($q) {
                $q->where(fn ($local) => $local->whereNull('upstream_source_id')
                    ->whereHas('cards', fn ($c) => $c->where('status', 'unused')))
                    ->orWhere(fn ($up) => $up->whereNotNull('upstream_source_id')
                        ->where(fn ($s) => $s->where('stock_cache', '!=', 0)->orWhereNull('stock_cache')));
            });
        }

        // 分站可见性过滤:排除分站显式下架(is_listed=false)的商品(spec §4)。
        $subsite = $request->attributes->get('subsite');
        if ($subsite) {
            $excludedIds = SubsiteProductSetting::where('merchant_id', $subsite->id)
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

        // 分站可见性校验:分站下架的商品不允许直接访问(spec §4,G1 修复)
        $subsite = request()->attributes->get('subsite');
        if ($subsite) {
            $hidden = SubsiteProductSetting::where('merchant_id', $subsite->id)
                ->where('product_id', $product->id)
                ->where('is_listed', false)
                ->exists();
            if ($hidden) {
                abort(404);
            }
        }

        return response()->json($this->transform($product, true));
    }

    public function featured(Request $request): JsonResponse
    {
        $count = (int) ($request->input('limit', StorefrontConfig::get('featured_count')));
        $query = Product::where('status', true)->where('is_featured', true);

        // 缺货商品过滤(与 index 一致,后台「显示缺货商品」关闭时)
        if (! StorefrontConfig::get('show_out_of_stock', true)) {
            $query->where(function ($q) {
                $q->where(fn ($local) => $local->whereNull('upstream_source_id')
                    ->whereHas('cards', fn ($c) => $c->where('status', 'unused')))
                    ->orWhere(fn ($up) => $up->whereNotNull('upstream_source_id')
                        ->where(fn ($s) => $s->where('stock_cache', '!=', 0)->orWhereNull('stock_cache')));
            });
        }

        // 分站可见性过滤(与 index 一致)
        $subsite = $request->attributes->get('subsite');
        if ($subsite) {
            $excludedIds = SubsiteProductSetting::where('merchant_id', $subsite->id)
                ->where('is_listed', false)->pluck('product_id')->toArray();
            if ($excludedIds) {
                $query->whereNotIn('id', $excludedIds);
            }
        }

        // 推荐商品按后台设置的 sort 排序(大号在前),sort 相同按最新;支持推荐位自定义顺序
        $products = $query->orderByDesc('sort')->latest()->limit($count)
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')])
            ->withMin(['cards as premium_min' => fn ($q) => $q->where('status', 'unused')->whereNotNull('price')], 'price')
            ->get()->map(fn ($p) => $this->transform($p));

        return response()->json($products);
    }

    /** 统一输出格式:金额分,加 sales/stock,注入显示货币字段(spec §3.5)。 */
    private function transform(Product $p, bool $detail = false): array
    {
        // 解析当前显示货币(display.currency 中间件写入的 request attribute)
        $svc = app(CurrencyService::class);
        $cur = request()->attributes->get('currency') ?? $svc->getBaseCurrency();
        // 分站定价:若请求来自分站(subsite request attribute),按分站定价引擎计算生效价(spec §4)。
        $subsite = request()->attributes->get('subsite');
        $effectivePrice = (int) $p->price;
        if ($subsite) {
            $pricing = app(SubsitePricingService::class)->resolveUnitPrice($p, null, $subsite);
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
            'stock' => $p->availableStock(),
            'sales' => $p->displaySales(),
            'is_featured' => (bool) $p->is_featured,
            // 靓号自选:列表最低价(未使用卡密 price 最小值,分;无则 null)
            'premium_min_price' => $p->premium_min !== null ? (int) $p->premium_min : null,
            'premium_min_price_display' => $p->premium_min !== null
                ? $svc->convert((int) $p->premium_min, $cur)['amount']
                : null,
        ];
        if ($detail) {
            // SEO:未自定义时自动组合(标题=商品名,关键词=分类+商品名,描述=商品描述摘要)
            $categoryName = $p->category?->name;
            $seoTitle = $p->seo_title ?: $p->name;
            $seoKeywords = $p->seo_keywords ?: implode(',', array_filter([
                $categoryName, $p->name,
            ]));
            $seoDescription = $p->seo_description
                ?: ($p->description ? mb_substr(strip_tags($p->description), 0, 150) : '');

            $data = array_merge($data, [
                'seo' => [
                    'title' => $seoTitle,
                    'keywords' => $seoKeywords,
                    'description' => $seoDescription,
                ],
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
                        'stock' => $p->availableStock(),
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
                'pick_type' => $p->pick_type ?? 'general',
            ]);
            // 靓号自选:附带可选靓号列表(未使用卡密,按价格升序)
            if (($p->pick_type ?? 'general') === 'premium') {
                $data['premium_numbers'] = $this->premiumNumbers($p, $svc, $cur);
            }
        }

        return $data;
    }

    /** 靓号自选:解析可选靓号(第一段)与价格,支持 keyword 搜索/分页/card_id 精确命中(按价格升序) */
    private function premiumNumbers(Product $p, $svc, string $cur): array
    {
        $keyword = trim((string) request()->input('keyword', ''));
        $page = max(1, (int) request()->input('page', 1));
        $perPage = min(50, max(1, (int) request()->input('per_page', 20)));
        $cardId = request()->input('card_id');

        $query = $p->cards()->where('status', 'unused');
        if ($cardId !== null && $cardId !== '') {
            $query->where('id', (int) $cardId);
        }
        $cards = $query->get();
        $list = [];
        foreach ($cards as $card) {
            $plain = $card->plainContent();
            $parts = explode('---', $plain);
            $number = trim($parts[0] ?? '');
            if ($number === '' || ($keyword !== '' && ! str_contains($number, $keyword))) {
                continue;
            }
            $priceFen = $card->price ?? (int) $p->price;
            $conv = $svc->convert($priceFen, $cur);
            $list[] = [
                'card_id' => $card->id,
                'number' => $number,
                'price' => $priceFen,
                'price_display' => $conv['amount'],
                'display_currency' => $conv['currency'],
            ];
        }
        // 按价格升序
        usort($list, fn ($a, $b) => $a['price'] <=> $b['price']);

        $total = count($list);
        $min = $total > 0 ? $list[0]['price'] : null;
        $minConv = $min !== null ? $svc->convert($min, $cur) : null;
        $slice = array_slice($list, ($page - 1) * $perPage, $perPage);

        return [
            'list' => $slice,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < $total,
            'min_price' => $min,
            'min_price_display' => $minConv['amount'] ?? null,
            'min_currency' => $minConv['currency'] ?? $cur,
        ];
    }
}
