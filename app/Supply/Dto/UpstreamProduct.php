<?php

namespace App\Supply\Dto;

/** 上游商品(驱动统一输出,金额已转为分) */
class UpstreamProduct
{
    /**
     * @param  array<int, array{code:?string, name:string, price:int, stock_quantity:int, is_active:bool}>  $skus
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly int $price,              // 售价(分),驱动内部元→分转换后
        public readonly int $factoryPrice,       // 拿货价(分)
        public readonly ?string $categoryCode = null,
        public readonly ?string $categoryName = null, // 上游分类名(驱动能拿到时填充,用于展示,避免"分类 #4")
        public readonly ?string $description = null,
        public readonly ?string $cover = null,
        public readonly array $images = [],
        public readonly bool $isActive = true,
        public readonly array $skus = [],        // 见 @param
        public readonly int $stockQuantity = -1, // -1=无限
        public readonly ?string $productUrl = null, // 上游公开商品页(驱动确认后的真实链接)
    ) {}
}
