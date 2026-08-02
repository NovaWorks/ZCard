<?php

namespace App\Supply;

use App\Models\Category;
use App\Models\Product;
use App\Models\SupplySource;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Support\Str;

/**
 * 商品同步服务(spec §5.1)
 * 全量/增量同步上游商品进本地 products 表,含售价保护(再次同步不动 price)。
 *
 * 售价保护:运营在后台手动设置的 price 由运营所有;再次同步时仅更新上游拥有的字段
 * (factory_price / name / description / cover / 分类 / 上游同步时间 / hide),
 * 永不覆盖 price。首次同步 price 为空时按 default_pricing_mode 计算初始售价。
 */
class SupplySyncService
{
    /**
     * 主站 merchant id(单商户约定:主站 = merchant 1)。
     */
    public const MAIN_MERCHANT_ID = 1;

    /**
     * 单个商品 upsert(供批量同步和测试调用)。
     */
    public function upsertProduct(SupplySource $source, UpstreamProduct $dto): Product
    {
        $existing = Product::where('upstream_source_id', $source->id)
            ->where('upstream_product_code', $dto->code)
            ->first();

        if ($existing) {
            // 已有:更新上游拥有字段,不动 price(售价保护)
            $existing->update([
                'name' => $dto->name,
                'description' => $dto->description,
                'cover' => $dto->cover,
                'factory_price' => $dto->factoryPrice,
                'category_id' => $this->resolveCategoryId($source, $dto->categoryCode),
                'upstream_synced_at' => now(),
                'hide' => ! $dto->isActive ? true : $existing->hide, // 上游下架→标隐藏,不删
            ]);
            return $existing->fresh();
        }

        // 新建:按定价规则算初始 price
        $price = $this->computeInitialPrice($source, $dto->factoryPrice);

        return Product::create([
            'merchant_id' => self::MAIN_MERCHANT_ID,
            'name' => $dto->name,
            'slug' => $this->uniqueSlug($dto->name, $dto->code),
            'description' => $dto->description,
            'cover' => $dto->cover,
            'price' => $price ?? 0,
            'factory_price' => $dto->factoryPrice,
            'stock_type' => 'card',
            'status' => ($price === null || ! ($source->settings['auto_list'] ?? true)) ? 0 : 1,
            'hide' => ! $dto->isActive ? true : false,
            'category_id' => $this->resolveCategoryId($source, $dto->categoryCode),
            'upstream_source_id' => $source->id,
            'upstream_product_code' => $dto->code,
            'upstream_synced_at' => now(),
        ]);
    }

    /**
     * 按定价规则算初始售价(spec §5.1,仅首次同步 price 为空时)。
     * 返回 null 表示待审(pending 模式)。
     */
    private function computeInitialPrice(SupplySource $source, int $factoryPrice): ?int
    {
        $mode = $source->settings['default_pricing_mode'] ?? 'percent';
        return match ($mode) {
            'fixed' => $factoryPrice + (int) ($source->settings['default_markup_amount'] ?? 0),
            'percent' => (int) round($factoryPrice * (1 + (int) ($source->settings['default_markup_percent'] ?? 10) / 100)),
            'equal' => $factoryPrice,
            'pending' => null,
            default => (int) round($factoryPrice * 1.1),
        };
    }

    private function resolveCategoryId(SupplySource $source, ?string $upstreamCatCode): ?int
    {
        if (! $upstreamCatCode) {
            return null;
        }
        $cat = Category::where('slug', $upstreamCatCode)->first();
        return $cat?->id;
    }

    private function uniqueSlug(string $name, string $code): string
    {
        $base = Str::slug($name) ?: ('p-' . $code);
        $slug = $base;
        $i = 1;
        while (Product::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
