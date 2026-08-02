<?php

namespace App\Supply\Dto;

/** 上游分类(驱动统一输出) */
class UpstreamCategory
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $parentCode = null,
        public readonly ?string $slug = null,
        public readonly ?string $icon = null,
        public readonly int $sort = 0,
    ) {}
}
