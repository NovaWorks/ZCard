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
}
