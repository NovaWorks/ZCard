<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardImport;
use App\Support\CardImportService;
use App\Support\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 后台卡密管理。列表(不含明文)、导入、批量禁用/删除、统计、导出、批次列表。
 *
 * 安全约定:
 *  - index 不返回 content(明文卡密)
 *  - export 才下发明文,且仅限管理员(Sanctum + 权限中间件)
 */
class CardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // 安全:不返回 content(明文卡密)。前端管理只看状态/来源/类型/备注。
        $query = Card::query()
            ->select([
                'id', 'product_id', 'status', 'note', 'card_type',
                'owner_id', 'draft_premium', 'draft_cost',
                'order_id', 'import_id', 'content_hash',
                'locked_at', 'used_at', 'created_at', 'updated_at',
            ])
            ->with([
                'product:id,name',
                'import:id,source',
                'order:id,order_no',
            ]);

        app(CardService::class)->applyFilters($query, $this->filters($request));

        $cards = $query->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($cards);
    }

    /** 顶部统计卡片:总/未使用/已使用/已禁用 */
    public function stats(Request $request): JsonResponse
    {
        $stats = app(CardService::class)->stats(
            $request->integer('product_id') ?: null
        );

        return response()->json($stats);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'content'    => 'required|string',
            'format'     => 'nullable|string|in:single,multi',
            'delimiter'  => 'nullable|string',
            'note'       => 'nullable|string|max:255',
            'card_type'  => 'nullable|string|max:20',
        ]);

        $import = app(CardImportService::class)->import(
            $data['product_id'],
            $request->user()->id,
            $data['content'],
            [
                'format'    => $data['format'] ?? 'single',
                'delimiter' => $data['delimiter'] ?? null,
                'source'    => 'admin_api',
                'note'      => $data['note'] ?? null,
                'card_type' => $data['card_type'] ?? null,
            ]
        );

        $fresh = $import->fresh();

        return response()->json([
            'import_id'     => $import->id,
            'status'        => $fresh->status,
            'success_count' => $fresh->success_count,
            'failed_count'  => $fresh->failed_count,
            'total'         => $import->total,
        ], 201);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer|exists:cards,id',
        ]);

        $count = app(CardService::class)->disable($data['ids']);
        return response()->json(['disabled' => $count]);
    }

    public function enable(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|exists:cards,id']);
        $count = app(CardService::class)->enable($data['ids']);
        return response()->json(['enabled' => $count]);
    }

    public function lock(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|exists:cards,id']);
        $count = app(CardService::class)->lock($data['ids']);
        return response()->json(['locked' => $count]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|exists:cards,id']);
        $count = app(CardService::class)->unlock($data['ids']);
        return response()->json(['unlocked' => $count]);
    }

    /** 批量删除(只删 unused/disabled,保护锁定中/已售出) */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer|exists:cards,id',
        ]);

        $count = app(CardService::class)->delete($data['ids']);

        return response()->json(['deleted' => $count]);
    }

    /**
     * 导出筛选后的卡密为 CSV(明文)。
     * 走流式输出,避免大结果集占用内存。
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $limit = (int) ($request->integer('limit') ?: 50000);

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="cards-export-' . date('Ymd-His') . '.csv"',
            'X-Accel-Buffering'   => 'no',
        ];

        return response()->stream(function () use ($filters, $limit) {
            // UTF-8 BOM,保证 Excel 直接打开 CSV 中文不乱码
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'wb');
            fputcsv($out, ['ID', '商品', '卡密明文', '状态', '卡密类型', '备注', '入库时间', '出售时间']);

            [$rows] = app(CardService::class)->exportFiltered($filters, $limit);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['product_name'],
                    $row['content'],
                    $row['status'],
                    $row['card_type'],
                    $row['note'],
                    $row['created_at'],
                    $row['used_at'],
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }

    public function importBatches(Request $request): JsonResponse
    {
        $batches = CardImport::query()
            ->with('product:id,name')
            ->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($batches);
    }

    /**
     * 从请求里抽出统一的筛选数组(供 applyFilters 用)。
     */
    private function filters(Request $request): array
    {
        return [
            'product_id' => $request->input('product_id'),
            'status'     => $request->input('status'),
            'card_type'  => $request->input('card_type'),
            'note'       => $request->input('note'),
            'owner_id'   => $request->input('owner_id'),
            'keyword'    => $request->input('keyword'),
            'date_from'  => $request->input('date_from'),
            'date_to'    => $request->input('date_to'),
        ];
    }

    /**
     * 查看单条卡密明文(管理员核对用)。
     */
    public function reveal(int $id): JsonResponse
    {
        $card = Card::with(['product:id,name', 'order:id,order_no'])->findOrFail($id);
        return response()->json([
            'id' => $card->id,
            'content' => $card->plainContent(),
            'status' => $card->status,
            'note' => $card->note,
            'card_type' => $card->card_type,
            'draft_premium' => $card->draft_premium,
            'draft_cost' => $card->draft_cost,
            'owner_id' => $card->owner_id,
            'product_name' => $card->product?->name,
            'order_no' => $card->order?->order_no,
            'created_at' => $card->created_at,
            'used_at' => $card->used_at,
        ]);
    }

    /** 编辑卡密(更新备注/类型/成本/加价) */
    public function update(Request $request, int $id): JsonResponse
    {
        $card = Card::findOrFail($id);
        $data = $request->validate([
            'note' => 'nullable|string|max:255',
            'card_type' => 'nullable|string|max:20',
            'draft_premium' => 'nullable|numeric|min:0',
            'draft_cost' => 'nullable|numeric|min:0',
            'status' => 'nullable|string|in:unused,locked,used,disabled',
        ]);
        $card->update($data);
        return response()->json($card->fresh());
    }
}
