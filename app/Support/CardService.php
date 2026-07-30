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

    /**
     * 按筛选条件导出卡密为 CSV(含明文)。
     * 返回 [行数组, 总数]。每行: [id, product_name, content, status, card_type, note, created_at]。
     *
     * @param  array  $filters  筛选条件(同 CardController::buildQuery 的入参)
     * @param  int    $limit    单次最多导出条数(防止内存爆炸)
     * @return array{0: array<int, array>, 1: int}
     */
    public function exportFiltered(array $filters = [], int $limit = 50000): array
    {
        $query = Card::query()
            ->with(['product:id,name'])
            ->orderBy('id');

        $this->applyFilters($query, $filters);

        $total = (int) (clone $query)->count();
        $cards = $query->limit($limit)->get();

        $rows = [];
        foreach ($cards as $card) {
            $rows[] = [
                'id'           => $card->id,
                'product_name' => $card->product?->name ?? ('#' . $card->product_id),
                'content'      => $card->plainContent(),
                'status'       => $card->status,
                'card_type'    => $card->card_type ?? '',
                'note'         => $card->note ?? '',
                'created_at'   => (string) $card->created_at,
                'used_at'      => $card->used_at ? (string) $card->used_at : '',
            ];
        }

        return [$rows, $total];
    }

    /**
     * 对查询构造器应用通用筛选(product_id/status/keyword/note/owner_id/card_type/日期范围)。
     * keyword 仅匹配 content_hash 的前缀(SHA256 的 hex 不暴露明文),不做明文 LIKE。
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array                                   $filters
     * @return void
     */
    public function applyFilters($query, array $filters): void
    {
        if (!empty($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['card_type'])) {
            $query->where('card_type', $filters['card_type']);
        }
        if (isset($filters['note']) && $filters['note'] !== '' && $filters['note'] !== null) {
            $query->where('note', 'like', '%' . $filters['note'] . '%');
        }
        if (array_key_exists('owner_id', $filters) && $filters['owner_id'] !== '' && $filters['owner_id'] !== null) {
            $query->where('owner_id', $filters['owner_id']);
        }
        // keyword: 我们以 content_hash 的前缀做匹配(管理员常见做法是贴卡密让系统定位)。
        // 注意:这里直接用明文做精确 hash 匹配,而非 LIKE,避免泄漏明文长度信息。
        if (!empty($filters['keyword'])) {
            $hash = \App\Support\CardCipher::hash((string) $filters['keyword']);
            $query->where('content_hash', $hash);
        }
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }
    }

    /** 批量禁用(unused → disabled) */
    public function disable(array $cardIds): int
    {
        return Card::whereIn('id', $cardIds)
            ->whereIn('status', [Card::STATUS_UNUSED, Card::STATUS_LOCKED])
            ->update(['status' => Card::STATUS_DISABLED]);
    }

    /** 批量启用(disabled → unused) */
    public function enable(array $cardIds): int
    {
        return Card::whereIn('id', $cardIds)
            ->where('status', Card::STATUS_DISABLED)
            ->update(['status' => Card::STATUS_UNUSED]);
    }

    /** 批量锁定(unused → locked) */
    public function lock(array $cardIds): int
    {
        return Card::whereIn('id', $cardIds)
            ->where('status', Card::STATUS_UNUSED)
            ->update(['status' => Card::STATUS_LOCKED, 'locked_at' => now()]);
    }

    /** 批量解锁(locked → unused) */
    public function unlock(array $cardIds): int
    {
        return Card::whereIn('id', $cardIds)
            ->where('status', Card::STATUS_LOCKED)
            ->update(['status' => Card::STATUS_UNUSED, 'locked_at' => null]);
    }

    /** 将卡密标记为已出售(unused/locked/disabled → used) */
    public function markAsSold(array $cardIds): int
    {
        return Card::whereIn('id', $cardIds)
            ->whereIn('status', [Card::STATUS_UNUSED, Card::STATUS_LOCKED, Card::STATUS_DISABLED])
            ->update(['status' => Card::STATUS_USED, 'used_at' => now()]);
    }

    /**
     * 批量删除卡密。
     * 安全策略:只允许删除 unused / disabled 状态的卡密,锁定中/已使用的不删。
     */
    public function delete(array $cardIds): int
    {
        return Card::whereIn('id', $cardIds)
            ->whereIn('status', [Card::STATUS_UNUSED, Card::STATUS_DISABLED])
            ->delete();
    }

    /**
     * 卡密状态统计(用于顶部 4 张统计卡片)。
     * 可选 product_id 过滤。
     */
    public function stats(?int $productId = null): array
    {
        $base = Card::query();
        if ($productId) {
            $base->where('product_id', $productId);
        }

        $rows = (clone $base)
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $total = (clone $base)->count();

        return [
            'total'    => $total,
            'unused'   => (int) ($rows[Card::STATUS_UNUSED] ?? 0),
            'locked'   => (int) ($rows[Card::STATUS_LOCKED] ?? 0),
            'used'     => (int) ($rows[Card::STATUS_USED] ?? 0),
            'disabled' => (int) ($rows[Card::STATUS_DISABLED] ?? 0),
        ];
    }
}
