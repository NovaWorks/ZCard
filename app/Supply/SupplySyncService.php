<?php

namespace App\Supply;

use App\Models\Category;
use App\Models\Product;
use App\Models\SupplySource;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 商品同步服务(spec §5.1)
 * 全量/增量同步上游商品进本地 products 表,含售价保护(再次同步不动 price)。
 *
 * 售价:加价基数为**上游售价**(非上游成本价),按 default_pricing_mode 计算本地售价;
 * 手动改过价(price_manual)或货源关闭 auto_sync_price 时保护不被同步覆盖;
 * 默认 auto_sync_price 开启 → 上游调价后本地售价自动跟随重算。
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
     *                               'markup_percent'=>int,
     *                               'markup_amount'=>float(元)]
     *                               为 null 时走货源 settings 默认定价。
     * @param  array|null  $categoryMap  上游分类 code → 本地分类 id 映射(勾选导入时)
     * @param  bool  $forcePrice  显式覆盖手动价并恢复自动定价(仅管理员确认后的全量重算)
     */
    public function upsertProduct(
        SupplySource $source,
        UpstreamProduct $dto,
        ?array $pricing = null,
        ?array $categoryMap = null,
        bool $forcePrice = false,
    ): Product {
        // 含软删除查找:删除过的商品重新导入时恢复原记录。
        // ⚠️ 只用 upstream_product_code 精确匹配 —— 不能再用 slug 兜底:
        // 上游不同商品(如"美区Gemini"与"随机Gemini")的 Str::slug(name) 相同,
        // slug 匹配会把新商品误绑到旧记录,导致新商品不入库、code 错乱,
        // 表现即"导入成功但再次拉取仍显示新货源"。
        $existing = Product::withTrashed()
            ->where('upstream_source_id', $source->id)
            ->where('upstream_product_code', $dto->code)
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
                // 商品名称由本地运营所有，避免定时同步覆盖人工命名。
                'cover' => $this->normalizeCover($source, $dto->cover),
                'images' => $this->normalizeImages($source, $dto->images, $dto->cover),
                'factory_price' => $dto->factoryPrice,
                'upstream_price' => $dto->price, // 上游售价快照(列表展示/加价核对)
                'stock_cache' => $dto->stockQuantity, // 上游库存缓存
                'category_id' => $this->resolveCategoryId($source, $dto->categoryCode, $dto->categoryName, $categoryMap),
                'upstream_synced_at' => now(),
                'hide' => ! $dto->isActive ? true : $existing->hide, // 上游下架→标隐藏,不删
            ];
            if ($this->shouldSyncPublicDescription($source)) {
                $update['description'] = $this->normalizeDescription($source, $dto->description);
            }
            // 价格重算条件(任一满足):
            // 1. 勾选导入显式传了 pricing;
            // 2. 货源开启「自动跟随上游调价」(auto_sync_price,默认开启)、默认定价非 pending、
            //    且该商品售价**未被运营手动改过**(price_manual=false)——
            //    上游调价后本地售价按加价规则重算,前台价格跟随;手动改过价的商品保护不动;
            //    注意:默认 percent/fixed/equal 才自动重算,pending(待审)模式下重算会把
            //    商品反复下架,故不自动跟随,保持人工审核流程;
            // 3. price<=0 是导入定价失败的脏数据,重算。
            $autoPrice = (bool) ($source->settings['auto_sync_price'] ?? true);
            $defaultMode = (string) ($source->settings['default_pricing_mode'] ?? 'percent');
            $priceManual = (bool) $existing->price_manual;
            if ($forcePrice
                || $pricing !== null
                || ($autoPrice && ! $priceManual && $defaultMode !== 'pending')
                || (int) $existing->price <= 0) {
                $newPrice = $this->computeInitialPrice($source, $dto->factoryPrice, $dto->price, $pricing);
                $update['price'] = $newPrice ?? 0;
                if ($forcePrice) {
                    // 管理员已明确选择覆盖手动价:本次重算后恢复后续自动跟随。
                    $update['price_manual'] = false;
                }
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
        } catch (QueryException $e) {
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
            'name' => $this->truncate($dto->name, 'name'),
            'slug' => $this->truncate($this->uniqueSlug($dto->name, $dto->code, $unique), 'slug'),
            'description' => $this->normalizeDescription($source, $dto->description),
            'cover' => $this->normalizeCover($source, $dto->cover),
            'images' => $this->normalizeImages($source, $dto->images, $dto->cover),
            'price' => $price ?? 0,
            'factory_price' => $dto->factoryPrice,
            'upstream_price' => $dto->price, // 上游售价快照
            'stock_type' => 'card',
            'fulfillment_type' => Product::FULFILLMENT_UPSTREAM,
            'status' => ($price === null || ! ($source->settings['auto_list'] ?? true)) ? 0 : 1,
            'hide' => ! $dto->isActive ? true : false,
            'category_id' => $this->resolveCategoryId($source, $dto->categoryCode, $dto->categoryName, $categoryMap),
            'upstream_source_id' => $source->id,
            'upstream_product_code' => $dto->code,
            'stock_cache' => $dto->stockQuantity, // 上游库存缓存(-1=无限)
            'upstream_synced_at' => now(),
        ]);
    }

    private function shouldSyncPublicDescription(SupplySource $source): bool
    {
        return (bool) ($source->settings['sync_public_description'] ?? true);
    }

    /**
     * 按定价规则算售价(spec §5.1)。
     * 加价基数为**上游售价**优先(上游成本价是上游的上游供货价,不应用作卖价基数);
     * 上游售价缺失(0)时才回退成本价。
     * 返回 null 表示待审(pending 模式)。
     *
     * @param  array|null  $pricing  显式定价策略(勾选导入时传入,覆盖货源默认):
     *                               ['mode'=>percent|fixed|equal|pending,
     *                               'markup_percent'=>int(百分比,如10),
     *                               'markup_amount'=>float(元)]
     */
    private function computeInitialPrice(SupplySource $source, int $factoryPrice, int $upstreamPrice, ?array $pricing = null): ?int
    {
        // 加价基数 = **上游售价**(upstreamPrice)优先:站长按"上游卖多少钱"加价,
        // 而不是按上游的成本价(factory_price,即上游的上游供货价,如 7.5 vs 6.1)。
        // 上游售价缺失(0)时才回退成本价。
        $base = $upstreamPrice > 0 ? $upstreamPrice : $factoryPrice;
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

        return rtrim($source->base_url, '/').'/'.ltrim($cover, '/');
    }

    /**
     * 详情图数组归一化:相对路径拼上游 base_url;为空时用 cover 兜底
     * (acg-faka 等上游 items 不返回 images,只返回 cover,保证详情页有图)。
     */
    private function normalizeImages(SupplySource $source, array $images, ?string $cover): array
    {
        $normalized = array_values(array_filter(array_map(
            fn ($img) => $this->normalizeCover($source, $img),
            $images
        )));

        if (empty($normalized) && $cover) {
            $normalized = [$this->normalizeCover($source, $cover)];
        }

        return $normalized;
    }

    /**
     * 描述 HTML 归一化:把其中相对路径的图片地址(如 /assets/a.png 或 assets/a.png)
     * 拼上上游 base_url,否则在前台详情页会解析成本站域名 → 404。
     * 绝对地址(https://)、协议相对(//cdn.xxx)、data:、锚点(#)保持不变。
     */
    private function normalizeDescription(SupplySource $source, ?string $description): ?string
    {
        if (! $description) {
            return $description;
        }
        $base = rtrim($source->base_url, '/');

        return preg_replace_callback(
            '/(<img[^>]*\bsrc=["\'])([^"\']+)(["\'])/i',
            function ($m) use ($base) {
                $src = $m[2];
                if ($src === '' || preg_match('/^(https?:|data:|#|\/\/)/i', $src)) {
                    return $m[0];
                }
                // / 开头的站内绝对路径:直接拼 base_url;其余按相对路径拼
                $url = $src[0] === '/' ? $base.$src : $base.'/'.$src;

                return $m[1].$url.$m[3];
            },
            $description
        );
    }

    /**
     * 解析商品应归入的本地分类 id。
     * 优先级:勾选导入时的显式映射(category_map) > 上游分类 code 匹配本地 slug > 自动创建。
     *
     * 自动创建:全量/增量同步时上游分类不会预先存在本地,若只做 slug 匹配会全部落到
     * "无分类"。此处按上游分类 code 自动创建一级分类(merchant 1 下),保证同步商品有分类。
     */
    private function resolveCategoryId(SupplySource $source, ?string $upstreamCatCode, ?string $upstreamCatName = null, ?array $categoryMap = null): ?int
    {
        if ($upstreamCatCode !== null && $categoryMap !== null && isset($categoryMap[$upstreamCatCode])) {
            return (int) $categoryMap[$upstreamCatCode] ?: null;
        }
        if (! $upstreamCatCode) {
            return null;
        }

        $cat = Category::where('merchant_id', self::MAIN_MERCHANT_ID)
            ->where('slug', $upstreamCatCode)
            ->first();
        if ($cat) {
            return $cat->id;
        }

        // 自动创建上游分类(幂等:unique(merchant_id, slug))
        $cat = Category::firstOrCreate(
            ['merchant_id' => self::MAIN_MERCHANT_ID, 'slug' => mb_substr($upstreamCatCode, 0, 100)],
            [
                'name' => $upstreamCatName ? mb_substr($upstreamCatName, 0, 100) : $upstreamCatCode,
                'sort' => 0,
                'status' => 1,
            ],
        );

        return $cat->id;
    }

    /** 按数据库字段实际长度截断(上游名称可能超长,防 Data too long 报错)。
     * 运行时读取列定义(迁移扩容后=500,未迁移=150),自适应任何环境。 */
    private function truncate(string $value, string $column): string
    {
        static $limits = [];
        if (! isset($limits[$column])) {
            $limits[$column] = 150; // 兜底
            try {
                $row = DB::selectOne(
                    'SHOW COLUMNS FROM products WHERE Field = ?',
                    [$column],
                );
                if ($row && preg_match('/\((\d+)\)/', $row->Type ?? '', $m)) {
                    $limits[$column] = (int) $m[1];
                }
            } catch (\Throwable $e) {
                // 查询失败用兜底值
            }
        }
        $length = $limits[$column];

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function uniqueSlug(string $name, string $code, bool $forceUnique = false): string
    {
        $base = Str::slug($name) ?: ('p-'.$code);
        $slug = $base;
        $i = 1;
        // 必须含软删除:软删商品仍占用唯一索引(merchant_id+slug),否则重新导入会 1062 冲突。
        // forceUnique=true 时额外追加随机后缀,彻底避免边缘冲突。
        while (Product::withTrashed()->where('slug', $slug)->exists() || ($forceUnique && $i === 1)) {
            $slug = $base.'-'.($forceUnique ? $i++.'-'.Str::random(4) : $i++);
        }

        return $slug;
    }
}
