<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
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
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $products = $query->orderByDesc('id')->paginate($request->input('pageSize', 15));

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:150',
            'category_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'cover' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'price' => 'required|integer|min:0',
            'stock_type' => 'nullable|string|in:card,url,code',
            'stock_visible' => 'boolean',
            'delivery_mode' => 'nullable|string|in:status,delete',
            'is_featured' => 'boolean',
            'virtual_sales' => 'nullable|integer|min:0',
            'min_order' => 'nullable|integer|min:1',
            'max_order' => 'nullable|integer|min:0',
            'sort' => 'nullable|integer',
            'status' => 'boolean',
        ]);

        $data['merchant_id'] = 1;
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) . '-' . Str::random(6);
        }

        $product = Product::create($data);

        return response()->json($product, 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Product::with(['skus', 'category'])->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:150',
            'slug' => 'sometimes|string|max:150',
            'category_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'cover' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|string',
            'price' => 'sometimes|integer|min:0',
            'stock_type' => 'nullable|string|in:card,url,code',
            'stock_visible' => 'boolean',
            'delivery_mode' => 'nullable|string|in:status,delete',
            'is_featured' => 'boolean',
            'virtual_sales' => 'nullable|integer|min:0',
            'min_order' => 'nullable|integer|min:1',
            'max_order' => 'nullable|integer|min:0',
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
            'total_stock' => \App\Models\Card::where('status', 'unused')->count(),
            'total_orders' => \App\Models\Order::count(),
            'paid_orders' => \App\Models\Order::where('status', 'paid')->count(),
        ]);
    }

    /** 批量操作(上架/下架/删除) */
    public function batch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'action' => 'required|in:activate,deactivate,delete',
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
        }

        return response()->json(['message' => $msg, 'affected' => count($ids)]);
    }
}
