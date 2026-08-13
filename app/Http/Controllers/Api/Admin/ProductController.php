<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserGroup;
use App\Support\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('category')
            ->withCount(['cards as stock' => fn ($q) => $q->where('status', 'unused')]);

        if ($search = $request->input('keyword')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->has('status') && $request->input('status') !== null) {
            $query->where('status', $request->input('status'));
        }
        if ($categoryId = $request->input('category_id')) {
            $query->where('category_id', $categoryId);
        }
        // 按货源商筛选(上游供货商品,如某供货商跑路需整体下架)
        if ($sourceId = $request->input('upstream_source_id')) {
            $query->where('upstream_source_id', $sourceId);
        }
        if ($request->has('is_featured') && $request->input('is_featured') !== null) {
            $query->where('is_featured', $request->boolean('is_featured'));
        }
        if ($stockType = $request->input('stock_type')) {
            $query->where('stock_type', $stockType);
        }
        // 库存筛选:固定/人工视为不限量，上游读缓存，自动卡密检查卡池。
        if ($stockStatus = $request->input('stock_status')) {
            if ($stockStatus === 'out') {
                $query->where(function ($q) {
                    $q->where(fn ($auto) => $auto
                        ->whereNull('upstream_source_id')
                        ->where('fulfillment_type', Product::FULFILLMENT_AUTO_CARD)
                        ->whereDoesntHave('cards', fn ($cards) => $cards->where('status', 'unused')))
                        ->orWhere(fn ($upstream) => $upstream
                            ->where(fn ($source) => $source->whereNotNull('upstream_source_id')
                                ->orWhere('fulfillment_type', Product::FULFILLMENT_UPSTREAM))
                            ->where('stock_cache', 0));
                });
            } elseif ($stockStatus === 'available') {
                $query->where(function ($q) {
                    $q->where(fn ($auto) => $auto
                        ->whereNull('upstream_source_id')
                        ->where('fulfillment_type', Product::FULFILLMENT_AUTO_CARD)
                        ->whereHas('cards', fn ($cards) => $cards->where('status', 'unused')))
                        ->orWhere(fn ($local) => $local->whereNull('upstream_source_id')
                            ->whereIn('fulfillment_type', [Product::FULFILLMENT_FIXED, Product::FULFILLMENT_MANUAL]))
                        ->orWhere(fn ($upstream) => $upstream
                            ->where(fn ($source) => $source->whereNotNull('upstream_source_id')
                                ->orWhere('fulfillment_type', Product::FULFILLMENT_UPSTREAM))
                            ->where(fn ($stock) => $stock->where('stock_cache', '!=', 0)->orWhereNull('stock_cache')));
                });
            }
        }

        $products = $query->orderByDesc('id')->paginate($request->input('pageSize', 15));
        $products->getCollection()->each(function (Product $product) {
            $product->setAttribute('stock', $product->availableStock());
        });

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:150',
            'seo_title' => 'nullable|string|max:200',
            'seo_keywords' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:2000',
            'category_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'cover' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'price' => 'required|integer|min:0',
            'factory_price' => 'nullable|integer|min:0',
            'draft_premium' => 'nullable|integer|min:0',
            'member_price' => 'nullable|array',
            'stock_type' => 'nullable|string|in:card,url,code',
            'fulfillment_type' => 'nullable|string|in:auto_card,fixed,manual,upstream',
            'stock_visible' => 'boolean',
            'control_config' => 'nullable|array',
            'delivery_mode' => 'nullable|string|in:status,delete',
            'is_featured' => 'boolean',
            'virtual_sales' => 'nullable|integer|min:0',
            'virtual_reviews' => 'nullable|array',
            'min_order' => 'nullable|integer|min:1',
            'max_order' => 'nullable|integer|min:0',
            'contact_type' => 'nullable|string|in:email,phone,none',
            'send_email' => 'boolean',
            'delivery_message' => 'nullable|string',
            'leave_message' => 'nullable|string',
            'only_user' => 'boolean',
            'purchase_limit' => 'nullable|integer|min:0',
            'hide' => 'boolean',
            'level_disable' => 'boolean',
            'dedup' => 'boolean',
            'pick_type' => 'nullable|string|in:general,premium',
            'sort' => 'nullable|integer',
            'status' => 'boolean',
        ]);

        $data['merchant_id'] = 1;
        $this->normalizeFulfillmentData($data);
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']).'-'.Str::random(6);
        }

        $product = Product::create($data);

        return response()->json($product, 201);
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::with(['skus', 'category'])->findOrFail($id);

        $userGroups = UserGroup::where('status', true)
            ->orderBy('sort')
            ->get(['id', 'name']);

        return response()->json(
            array_merge($product->toArray(), ['user_groups' => $userGroups])
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $before = $product->only(['name', 'slug', 'price', 'status', 'hide']);

        $data = $request->validate([
            'name' => 'sometimes|string|max:150',
            'slug' => 'sometimes|string|max:150',
            'seo_title' => 'nullable|string|max:200',
            'seo_keywords' => 'nullable|string|max:255',
            'seo_description' => 'nullable|string|max:2000',
            'category_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'cover' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'price' => 'sometimes|integer|min:0',
            'price_manual' => 'nullable|boolean',
            'factory_price' => 'nullable|integer|min:0',
            'draft_premium' => 'nullable|integer|min:0',
            'member_price' => 'nullable|array',
            'stock_type' => 'nullable|string|in:card,url,code',
            'fulfillment_type' => 'nullable|string|in:auto_card,fixed,manual,upstream',
            'stock_visible' => 'boolean',
            'control_config' => 'nullable|array',
            'delivery_mode' => 'nullable|string|in:status,delete',
            'is_featured' => 'boolean',
            'virtual_sales' => 'nullable|integer|min:0',
            'virtual_reviews' => 'nullable|array',
            'min_order' => 'nullable|integer|min:1',
            'max_order' => 'nullable|integer|min:0',
            'contact_type' => 'nullable|string|in:email,phone,none',
            'send_email' => 'boolean',
            'delivery_message' => 'nullable|string',
            'leave_message' => 'nullable|string',
            'only_user' => 'boolean',
            'purchase_limit' => 'nullable|integer|min:0',
            'hide' => 'boolean',
            'level_disable' => 'boolean',
            'dedup' => 'boolean',
            'pick_type' => 'nullable|string|in:general,premium',
            'sort' => 'nullable|integer',
            'status' => 'boolean',
        ]);

        $this->normalizeFulfillmentData($data, $product);

        // 手动改价保护标记(price_manual):
        // 仅当**价格实际发生变化**时才置 true —— 前端编辑表单总是提交 price,
        // 若无条件标记,运营改过任何字段(名称/描述等)的商品都会误标为"手动改价",
        // 导致上游调价后这些商品不再跟随(历史加价不生效的根因)。
        if (array_key_exists('price', $data) && (int) $data['price'] !== (int) $product->price) {
            // 实际改价 → 标记保护(优先级最高)
            $data['price_manual'] = true;
        } elseif (array_key_exists('price_manual', $data) && $data['price_manual'] === false) {
            // 显式恢复自动定价:前端对上游商品提供「跟随上游调价」开关
            $data['price_manual'] = false;
        } else {
            // 未改价也未显式操作 → 不触碰标记
            unset($data['price_manual']);
        }

        $product->update($data);

        $after = $product->fresh()->only(array_keys($before));
        $changes = [];
        foreach ($before as $key => $value) {
            if ($value !== $after[$key]) {
                $changes[$key] = ['before' => $value, 'after' => $after[$key]];
            }
        }
        if ($changes !== []) {
            SecurityAudit::record($request, 'product.updated', Product::class, $product->id, [
                'changes' => $changes,
            ]);
        }

        return response()->json($product->fresh());
    }

    /** 保证履约方式与商品来源一致，并校验固定发货内容。 */
    private function normalizeFulfillmentData(array &$data, ?Product $product = null): void
    {
        if ($product?->upstream_source_id) {
            $data['fulfillment_type'] = Product::FULFILLMENT_UPSTREAM;
        } else {
            $type = $data['fulfillment_type'] ?? $product?->fulfillment_type ?? Product::FULFILLMENT_AUTO_CARD;
            if ($type === Product::FULFILLMENT_UPSTREAM) {
                throw ValidationException::withMessages([
                    'fulfillment_type' => '上游履约仅适用于从货源同步的商品',
                ]);
            }
            $data['fulfillment_type'] = $type;
        }

        if ($data['fulfillment_type'] === Product::FULFILLMENT_FIXED) {
            $content = array_key_exists('delivery_message', $data)
                ? trim((string) $data['delivery_message'])
                : trim((string) ($product?->delivery_message ?? ''));
            if ($content === '') {
                throw ValidationException::withMessages([
                    'delivery_message' => '固定内容发货必须填写固定发货内容',
                ]);
            }
        }

        if ($data['fulfillment_type'] !== Product::FULFILLMENT_AUTO_CARD) {
            $data['pick_type'] = 'general';
        }
    }

    public function destroy(int $id): JsonResponse
    {
        Product::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** 商品统计面板 */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => Product::count(),
            'active' => Product::where('status', 1)->count(),
            'inactive' => Product::where('status', 0)->count(),
            'featured' => Product::where('is_featured', true)->count(),
            'total_stock' => Card::where('status', 'unused')->count(),
            'total_orders' => Order::count(),
            'paid_orders' => Order::where('status', 'paid')->count(),
        ]);
    }

    /** 批量操作(上架/下架/删除) */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:activate,deactivate,delete,set_category',
            'category_id' => 'required_if:action,set_category|integer',
        ]);

        $ids = $data['ids'];
        $action = $data['action'];

        switch ($action) {
            case 'activate':
                Product::whereIn('id', $ids)->update(['status' => 1]);
                $msg = '上架成功';
                break;
            case 'deactivate':
                Product::whereIn('id', $ids)->update(['status' => 0]);
                $msg = '下架成功';
                break;
            case 'delete':
                Product::whereIn('id', $ids)->delete();
                $msg = '删除成功';
                break;
            case 'set_category':
                Product::whereIn('id', $ids)->update(['category_id' => $data['category_id']]);
                $msg = '分类设置成功';
                break;
        }

        return response()->json(['message' => $msg, 'affected' => count($ids)]);
    }
}
