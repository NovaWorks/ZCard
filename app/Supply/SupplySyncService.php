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
     * @param  array|null  $categoryMap  上游分类 code → 本地分类 id 映射(勾选导入时)
     */
    public function upsertProduct(SupplySource $source, UpstreamProduct $dto, ?array $pricing = null, ?array $categoryMap = null): Product
    {
        // 含软删除查找:删除过的商品重新导入时恢复原记录(否则新建 slug 撞唯一索引 1062)。
        // 匹配条件放宽:同货源下按 上游code 或 生成的slug 匹配 ——
        // 上游商品 code/name 变化时也能命中原记录,避免走新建导致 slug 冲突。
        $existing = Product::withTrashed()
            ->where('upstream_source_id', $source->id)
            ->where(function ($q) use ($dto) {
                $q->where('upstream_product_code', $dto->code)
                  ->orWhere('slug', Str::slug($dto->name) ?: ('p-' . $dto->code));
            })
            ->first();

        if ($existing) {
            // 软删除的记录先恢复(回到正常状态,slug 沿用原值)
            if ($existing->trashed()) {
                $existing->restore();
            }

            // 已有:更新上游拥有字段,默认不动 price(售价保护)。
            // 例外1:price<=0 是导入定价失败的脏数据,重算。
            // 例外2:勾选导入显式传了 pricing → 按本次所选策略重新定价。
            $update = [
                'name' => $dto->name,
                'description' => $dto->description,
                'cover' => $this->normalizeCover($source, $dto->cover),
                'factory_price' => $dto->factoryPrice,
                'stock_cache' => $dto->stockQuantity, // 上游库存缓存
                'category_id' => $this->resolveCategoryId($source, $dto->categoryCode, $categoryMap),
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

        // 唯一索引(merchant_id+slug)冲突终极兜底:极少数情况下(如并发/边缘数据)
        // uniqueSlug 检查后仍撞库,捕获后换随机后缀重试一次,保证导入不中断。
        try {
            return $this->createProduct($source, $dto, $price, $pricing, $categoryMap);
        } catch (\Illuminate\Database\QueryException $e) {
            if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }

        return $this->createProduct($source, $dto, $price, $pricing, $categoryMap, unique: true);
    }

    private function createProduct(SupplySource $source, UpstreamProduct $dto, ?int $price, ?array $pricing, ?array $categoryMap, bool $unique = false): Product
    {
        return Product::create([
            'merchant_id' => self::MAIN_MERCHANT_ID,
            'name' => $dto->name,
            'slug' => $this->uniqueSlug($dto->name, $dto->code, $unique),
            'description' => $dto->description,
            'cover' => $this->normalizeCover($source, $dto->cover),
            'price' => $price ?? 0,
            'factory_price' => $dto->factoryPrice,
            'stock_type' => 'card',
            'status' => ($price === null || ! ($source->settings['auto_list'] ?? true)) ? 0 : 1,
            'hide' => ! $dto->isActive ? true : false,
            'category_id' => $this->resolveCategoryId($source, $dto->categoryCode, $categoryMap),
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

    /**
     * 解析商品应归入的本地分类 id。
     * 优先级:勾选导入时的显式映射(category_map) > 上游分类 code 匹配本地 slug > null。
     */
    private function resolveCategoryId(SupplySource $source, ?string $upstreamCatCode, ?array $categoryMap = null): ?int
    {
        if ($upstreamCatCode !== null && $categoryMap !== null && isset($categoryMap[$upstreamCatCode])) {
            return (int) $categoryMap[$upstreamCatCode] ?: null;
        }
        if (! $upstreamCatCode) {
            return null;
        }
        $cat = Category::where('slug', $upstreamCatCode)->first();

        return $cat?->id;
    }

    private function uniqueSlug(string $name, string $code, bool $forceUnique = false): string
    {
        $base = Str::slug($name) ?: ('p-' . $code);
        $slug = $base;
        $i = 1;
        // 必须含软删除:软删商品仍占用唯一索引(merchant_id+slug),否则重新导入会 1062 冲突。
        // forceUnique=true 时额外追加随机后缀,彻底避免边缘冲突。
        while (Product::withTrashed()->where('slug', $slug)->exists() || ($forceUnique && $i === 1)) {
            $slug = $base . '-' . ($forceUnique ? $i++ . '-' . Str::random(4) : $i++);
        }
        return $slug;
    }
}
