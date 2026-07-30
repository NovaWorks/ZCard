<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductSku;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSkuController extends Controller
{
    public function index(int $productId): JsonResponse
    {
        return response()->json(ProductSku::where('product_id', $productId)->orderBy('sort')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'name' => 'required|string|max:60',
            'price' => 'required|integer|min:0',
            'stock_type' => 'nullable|string|in:card,url,code',
            'sort' => 'nullable|integer',
            'status' => 'boolean',
        ]);
        $sku = ProductSku::create($data);

        return response()->json($sku, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $sku = ProductSku::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:60',
            'price' => 'sometimes|integer|min:0',
            'stock_type' => 'nullable|string|in:card,url,code',
            'sort' => 'sometimes|integer',
            'status' => 'boolean',
        ]);
        $sku->update($data);

        return response()->json($sku->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        ProductSku::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
