<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SupplierAccount;
use App\Models\SupplierProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 供货专属定价(spec §7.4) —— 账号维度 + 商品维度两个入口
 */
class SupplierPriceController extends Controller
{
    /** GET /api/admin/supplier-accounts/{account}/prices */
    public function indexForAccount(SupplierAccount $account, Request $request): JsonResponse
    {
        $prices = $account->productPrices()
            ->when($request->input('product_id'), fn ($q, $pid) => $q->where('product_id', $pid))
            ->with(['product:id,name,slug', 'sku:id,name'])
            ->orderByDesc('id')->paginate($request->integer('per_page', 50));

        return response()->json($prices);
    }

    /** PUT /api/admin/supplier-accounts/{account}/prices (批量) */
    public function updateForAccount(SupplierAccount $account, Request $request): JsonResponse
    {
        $data = $request->validate([
            'prices' => 'required|array',
            'prices.*.product_id' => 'required|exists:products,id',
            'prices.*.sku_id' => 'nullable|exists:product_skus,id',
            'prices.*.price' => 'required|integer|min:1',
        ]);

        foreach ($data['prices'] as $item) {
            SupplierProductPrice::updateOrCreate(
                [
                    'supplier_account_id' => $account->id,
                    'product_id' => $item['product_id'],
                    'sku_id' => $item['sku_id'] ?? null,
                ],
                ['price' => $item['price']]
            );
        }

        return response()->json(['ok' => true, 'count' => count($data['prices'])]);
    }

    /** DELETE /api/admin/supplier-accounts/{account}/prices/{price} */
    public function destroyForAccount(SupplierAccount $account, int $priceId): JsonResponse
    {
        SupplierProductPrice::where('supplier_account_id', $account->id)->where('id', $priceId)->delete();

        return response()->json(null, 204);
    }

    /** GET /api/admin/products/{product}/supply-prices (商品维度) */
    public function indexForProduct(Product $product): JsonResponse
    {
        $prices = SupplierProductPrice::where('product_id', $product->id)
            ->with(['supplierAccount:id,name', 'sku:id,name'])
            ->orderByDesc('id')->get();

        return response()->json(['prices' => $prices]);
    }

    /** PUT /api/admin/products/{product}/supply-prices (商品维度批量) */
    public function updateForProduct(Product $product, Request $request): JsonResponse
    {
        $data = $request->validate([
            'prices' => 'required|array',
            'prices.*.supplier_account_id' => 'required|exists:supplier_accounts,id',
            'prices.*.sku_id' => 'nullable|exists:product_skus,id',
            'prices.*.price' => 'required|integer|min:1',
        ]);

        foreach ($data['prices'] as $item) {
            SupplierProductPrice::updateOrCreate(
                [
                    'supplier_account_id' => $item['supplier_account_id'],
                    'product_id' => $product->id,
                    'sku_id' => $item['sku_id'] ?? null,
                ],
                ['price' => $item['price']]
            );
        }

        return response()->json(['ok' => true, 'count' => count($data['prices'])]);
    }
}
