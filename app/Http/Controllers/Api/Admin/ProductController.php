<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Order;
use App\Models\Product;
use App\Models\UserGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        // 库存筛选:out=缺货(无未用卡),available=有货
        if ($stockStatus = $request->input('stock_status')) {
            if ($stockStatus === 'out') {
                $query->whereDoesntHave('cards', fn ($q) => $q->where('status', 'unused'));
            } elseif ($stockStatus === 'available') {
                $query->whereHas('cards', fn ($q) => $q->where('status', 'unused'));
            }
        }

        $products = $query->orderByDesc('id')->paginate($request->input('pageSize', 15));

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
            'factory_price' => 'nullable|integer|min:0',
            'draft_premium' => 'nullable|integer|min:0',
            'member_price' => 'nullable|array',
            'stock_type' => 'nullable|string|in:card,url,code',
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

        $product->update($data);

        return response()->json($product->fresh());
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
