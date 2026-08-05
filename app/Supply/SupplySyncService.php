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
    /**
     * upsert 上游商品到本地。
     *
     * @param  array|null  $pricing  显式定价策略(勾选导入时传入,覆盖货源默认):
     *                               ['mode'=>percent|fixed|equal|pending,
     *                                'markup_percent'=>int,
     *                                'markup_amount'=>float(元)]
     *                               为 null 时走货源 settings 默认定价。
     */
    public function upsertProduct(SupplySource $source, UpstreamProduct $dto, ?array $pricing = null): Product
    {
        $existing = Product::where('upstream_source_id', $source->id)
            ->where('upstream_product_code', $dto->code)
            ->first();

        if ($existing) {
            // 已有:更新上游拥有字段,默认不动 price(售价保护)。
            // 例外1:price<=0 是导入定价失败的脏数据,重算。
            // 例外2:勾选导入显式传了 pricing → 按本次所选策略重新定价。
            $update = [
                'name' => $dto->name,
                'description' => $dto->description,
                'cover' => $this->normalizeCover($source, $dto->cover),
                'factory_price' => $dto->factoryPrice,
                'stock_cache' => $dto->stockQuantity, // 上游库存缓存
                'category_id' => $this->resolveCategoryId($source, $dto->categoryCode),
                'upstream_synced_at' => now(),
                'hide' => ! $dto->isActive ? true : $existing->hide, // 上游下架→标隐藏,不删
            ];
            if ($pricing !== null || (int) $existing->price <= 0) {
                $newPrice = $this->computeInitialPrice($source, $dto->factoryPrice, $dto->price, $pricing);
                $update['price'] = $newPrice ?? 0;
                // pending 模式新导入/重定价 → 待审不上架
                if ($newPrice === null) {
                    $update['status'] = 0;
                }
            }
            $existing->update($update);
            return $existing->fresh();
        }

        // 新建:按定价规则算初始 price
        $price = $this->computeInitialPrice($source, $dto->factoryPrice, $dto->price, $pricing);

        return Product::create([
            'merchant_id' => self::MAIN_MERCHANT_ID,
            'name' => $dto->name,
            'slug' => $this->uniqueSlug($dto->name, $dto->code),
            'description' => $dto->description,
            'cover' => $this->normalizeCover($source, $dto->cover),
            'price' => $price ?? 0,
            'factory_price' => $dto->factoryPrice,
            'stock_type' => 'card',
            'status' => ($price === null || ! ($source->settings['auto_list'] ?? true)) ? 0 : 1,
            'hide' => ! $dto->isActive ? true : false,
            'category_id' => $this->resolveCategoryId($source, $dto->categoryCode),
            'upstream_source_id' => $source->id,
            'upstream_product_code' => $dto->code,
            'stock_cache' => $dto->stockQuantity, // 上游库存缓存(-1=无限)
            'upstream_synced_at' => now(),
        ]);
    }

    /**
     * 按定价规则算初始售价(spec §5.1,仅首次同步 price 为空时)。
     * 基础价优先取上游成本价 factoryPrice;上游未设成本(0)时回退到上游售价 price,
     * 否则(如 acg-faka)下游会把 0 成本加成后仍卖出 0 元。
     * 返回 null 表示待审(pending 模式)。
     *
     * @param  array|null  $pricing  显式定价策略(勾选导入时传入,覆盖货源默认):
     *                               ['mode'=>percent|fixed|equal|pending,
     *                                'markup_percent'=>int(百分比,如10),
     *                                'markup_amount'=>float(元)]
     */
    private function computeInitialPrice(SupplySource $source, int $factoryPrice, int $upstreamPrice, ?array $pricing = null): ?int
    {
        $base = $factoryPrice > 0 ? $factoryPrice : $upstreamPrice;
        $mode = $pricing['mode'] ?? $source->settings['default_pricing_mode'] ?? 'percent';
        // markup_amount 单位:元 → 分(fixed 加价)
        $amountFen = (int) round(((float) ($pricing['markup_amount'] ?? $source->settings['default_markup_amount'] ?? 0)) * 100);

        return match ($mode) {
            'fixed' => $base + $amountFen,
            'percent' => (int) round($base * (1 + (int) ($pricing['markup_percent'] ?? $source->settings['default_markup_percent'] ?? 10) / 100)),
            'equal' => $base,
            'pending' => null,
            default => (int) round($base * 1.1),
        };
    }

    /**
     * 封面图 URL 归一化:上游返回的相对路径(/assets/... 或 assets/...)在
     * 本站浏览器会解析成本站域名 → 404。拼上上游 base_url 成为完整 URL。
     */
    private function normalizeCover(SupplySource $source, ?string $cover): ?string
    {
        if (! $cover || preg_match('/^https?:\/\//i', $cover)) {
            return $cover;
        }

        return rtrim($source->base_url, '/') . '/' . ltrim($cover, '/');
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
