<?php

namespace App\Supply\Contracts;

use App\Models\SupplySource;
use App\Supply\Dto\UpstreamCategory;
use App\Supply\Dto\UpstreamOrder;
use App\Supply\Dto\UpstreamProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * 货源驱动统一接口(spec §3.1)
 * 三家上游(dujiao_next/acg_faka/zcard)各一实现,上层透明调用。
 */
interface SupplyDriver
{
    /**
     * 驱动自描述:声明它需要的配置字段(表单按 schema 动态渲染)。
     * 返回 ['field_key' => ['type'=>'text|number|url|secret','label'=>'中文','required'=>bool,'help'=>'?']]
     */
    public static function configSchema(): array;

    /** 驱动展示名/图标,用于后台下拉 */
    public static function info(): array;

    /** 用 SupplySource 实例化驱动 */
    public function __construct(SupplySource $source);

    /** 测连通 + 返回 ['connected'=>bool,'name'=>?string,'balance'=>?int(分),'currency'=>?string,'error'=>?string] */
    public function ping(): array;

    /** @return array<int, UpstreamCategory> */
    public function listCategories(): array;

    /**
     * 分页拉商品。
     *
     * @param  Carbon|null  $updatedAfter  增量同步时传,全量传 null
     * @return array{items:UpstreamProduct[], total:int, page:int, has_more:bool}
     */
    public function listProducts(?Carbon $updatedAfter, int $page, bool $fetchStock = false): array;

    public function getProduct(string $code): ?UpstreamProduct;

    /** 库存数,-1=无限 */
    public function getStock(string $code, ?string $skuCode = null): int;

    /**
     * 下单拿货。
     *
     * @param  array{product_code:string,sku_code:?string,quantity:int,downstream_order_no:string,contact:?string,callback_url:?string}  $params
     */
    public function createOrder(array $params): UpstreamOrder;

    public function getOrder(string $upstreamOrderId): UpstreamOrder;

    public function cancelOrder(string $upstreamOrderId): bool;

    /** 接收上游异步回调:验签+解析,返回标准化数组或 null */
    public function verifyCallback(Request $request): ?array;
}
