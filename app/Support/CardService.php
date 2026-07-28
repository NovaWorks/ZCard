<?php

namespace App\Support;

use App\Models\Card;
use Illuminate\Support\Facades\DB;

/**
 * 卡密库存服务(spec §5.2)。UI 无关,Filament + API 共用。
 */
class CardService
{
    /** 商品可用库存数(cards WHERE product_id AND status=unused) */
    public function countStock(int $productId): int
    {
        return (int) Card::where('product_id', $productId)
            ->where('status', Card::STATUS_UNUSED)
            ->count();
    }

    /** 批量库存(多商品,商品列表用,一次查询) */
    public function countStockForProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        return Card::whereIn('product_id', $productIds)
            ->where('status', Card::STATUS_UNUSED)
            ->select('product_id', DB::raw('count(*) as cnt'))
            ->groupBy('product_id')
            ->pluck('cnt', 'product_id')
            ->toArray();
    }

    /** 导出某商品卡密为 txt(明文,逐行) */
    public function export(int $productId): string
    {
        $cards = Card::where('product_id', $productId)
            ->where('status', Card::STATUS_UNUSED)
            ->orderBy('id')
            ->get();

        $lines = [];
        foreach ($cards as $card) {
            $lines[] = $card->plainContent();
        }
        return implode("\n", $lines);
    }

    /** 批量禁用 */
    public function disable(array $cardIds): int
    {
        return Card::whereIn('id', $cardIds)
            ->whereIn('status', [Card::STATUS_UNUSED])
            ->update(['status' => Card::STATUS_DISABLED]);
    }
}
