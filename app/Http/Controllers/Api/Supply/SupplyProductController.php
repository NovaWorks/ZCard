<?php

namespace App\Http\Controllers\Api\Supply;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Supply\SupplyPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货 API 商品控制器(spec §4.3) —— 下游查询商品/库存
 */
class SupplyProductController extends Controller
{
    public function categories(Request $request): JsonResponse
    {
        $categories = Category::where('status', 1)
            ->orderBy('sort')
            ->get(['id', 'parent_id', 'name', 'slug', 'icon']);

        return response()->json(['ok' => true, 'categories' => $categories]);
    }

    public function index(Request $request): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');
        $pricing = app(SupplyPricingService::class);

        // 安全(L-5):page_size 上限 100,防止超大分页拖垮 DB。
        $pageSize = min(100, max(1, $request->integer('page_size', 50)));

        $products = Product::query()
            ->where('status', 1)
            ->where('hide', false)
            ->with(['skus' => fn ($q) => $q->where('status', 1)])
            ->orderByDesc('id')
            ->paginate($pageSize);

        return response()->json([
            'ok' => true,
            'items' => $products->getCollection()->map(function (Product $p) use ($account, $pricing) {
                return $this->transformProduct($p, $account, $pricing);
            })->values(),
            'total' => $products->total(),
            'page' => $products->currentPage(),
            'page_size' => $products->perPage(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $request->attributes->get('supplier_account');
        $pricing = app(SupplyPricingService::class);
        $product = Product::with(['skus' => fn ($q) => $q->where('status', 1)])->find($id);

        if (! $product || $product->status != 1) {
            return response()->json([
                'ok' => false,
                'error_code' => 'product_unavailable',
                'message' => __('messages.supply_api.product_unavailable'),
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'product' => $this->transformProduct($product, $account, $pricing),
        ]);
    }

    public function stock(Request $request, int $id): JsonResponse
    {
        $product = Product::find($id);
        if (! $product || $product->status != 1) {
            return response()->json([
                'ok' => false,
                'error_code' => 'product_unavailable',
                'message' => __('messages.supply_api.product_unavailable'),
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'product_id' => $id,
            'stock' => $product->availableStock(),
        ]);
    }

    private function transformProduct(Product $p, $account, SupplyPricingService $pricing): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'description' => $p->description,
            'cover' => $p->cover,
            'price' => $pricing->resolvePrice($account, $p, null),
            'category_id' => $p->category_id,
            'skus' => $p->skus->map(function ($sku) use ($account, $p, $pricing) {
                return [
                    'id' => $sku->id,
                    'name' => $sku->name,
                    'price' => $pricing->resolvePrice($account, $p, $sku),
                ];
            }),
        ];
    }
}
