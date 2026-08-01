<?php

namespace App\Support;

use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\SubsiteProductSetting;

/**
 * 分站定价引擎(spec §4)。4 模式:inherit/markup_percent/fixed_markup/fixed_price。
 * 优先级:SKU规则 > 商品规则 > 分站默认加价率 > 继承原价。
 * listing 与 checkout 共用同一函数(规避 acg-faka 两套公式 bug)。
 */
class SubsitePricingService
{
    /**
     * 解析某商品在某分站的售价(基础货币分)。
     * @return array{price: int, base: int, mode: string, source: string}
     */
    public function resolveUnitPrice(Product $product, ?ProductSku $sku, Merchant $subsite): array
    {
        $basePrice = $sku ? (int) $sku->price : (int) $product->price;

        // 1. SKU 级规则(非 inherit)
        if ($sku) {
            $setting = $this->findSetting($subsite->id, $product->id, $sku->id);
            if ($setting && $setting->pricing_mode !== 'inherit') {
                return $this->applyMode($setting, $basePrice, 'sku');
            }
        }

        // 2. 商品级规则(非 inherit)
        $setting = $this->findSetting($subsite->id, $product->id, 0);
        if ($setting && $setting->pricing_mode !== 'inherit') {
            return $this->applyMode($setting, $basePrice, 'product');
        }

        // 3. 分站默认加价率
        $defaultMarkup = (float) ($subsite->settings['default_markup_percent'] ?? 0);
        if ($defaultMarkup > 0) {
            $price = (int) round($basePrice * (100 + $defaultMarkup) / 100);
            return ['price' => $price, 'base' => $basePrice, 'mode' => 'markup_percent', 'source' => 'profile'];
        }

        // 4. 继承原价
        return ['price' => $basePrice, 'base' => $basePrice, 'mode' => 'inherit', 'source' => 'inherit'];
    }

    private function findSetting(int $merchantId, int $productId, int $skuId): ?SubsiteProductSetting
    {
        return SubsiteProductSetting::where('merchant_id', $merchantId)
            ->where('product_id', $productId)
            ->where('sku_id', $skuId)
            ->first();
    }

    private function applyMode(SubsiteProductSetting $s, int $base, string $source): array
    {
        $price = match ($s->pricing_mode) {
            'markup_percent' => (int) round($base * (100 + (float) $s->markup_percent) / 100),
            'fixed_markup'   => $base + (int) $s->fixed_markup_amount,
            'fixed_price'    => (int) $s->fixed_price_amount,
            default          => $base,
        };
        if ($price < $base) {
            throw new \RuntimeException('分站价不能低于基础价');
        }
        return ['price' => $price, 'base' => $base, 'mode' => $s->pricing_mode, 'source' => $source];
    }
}
