<?php

namespace App\Supply;

use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SupplierAccount;
use App\Models\SupplierProductPrice;

/**
 * 供货专属价查找(spec §7.4)
 * 优先级:SKU级专属价 → 商品级默认价 → factory_price 兜底。
 */
class SupplyPricingService
{
    /**
     * 解析某供货账号对某商品/SKU 的供货价(基础货币分)。
     *
     * @return int 供货价(分)
     */
    public function resolvePrice(SupplierAccount $account, Product $product, ?ProductSku $sku): int
    {
        // 1. SKU 级专属价(最高优先级)
        if ($sku) {
            $skuPrice = SupplierProductPrice::where('supplier_account_id', $account->id)
                ->where('product_id', $product->id)
                ->where('sku_id', $sku->id)
                ->value('price');
            if ($skuPrice !== null) {
                return (int) $skuPrice;
            }
        }

        // 2. 商品级默认价(sku_id IS NULL)
        $productPrice = SupplierProductPrice::where('supplier_account_id', $account->id)
            ->where('product_id', $product->id)
            ->whereNull('sku_id')
            ->value('price');
        if ($productPrice !== null) {
            return (int) $productPrice;
        }

        // 3. factory_price 兜底
        return (int) $product->factory_price;
    }
}
