<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\CardImport;
use App\Support\CardImportService;
use App\Support\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台卡密管理。列表(不含明文)、导入、批量禁用、批次列表。
 */
class CardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // 安全:不返回 content(明文卡密)。前端管理只看状态/来源。
        $query = Card::query()
            ->select(['id', 'product_id', 'import_id', 'status', 'locked_at', 'used_at', 'created_at', 'updated_at'])
            ->with(['product:id,name', 'import:id,source']);

        if ($productId = $request->input('product_id')) {
            $query->where('product_id', $productId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $cards = $query->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($cards);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'content'    => 'required|string',
            'format'     => 'nullable|string|in:single,multi',
            'delimiter'  => 'nullable|string',
        ]);

        $import = app(CardImportService::class)->import(
            $data['product_id'],
            $request->user()->id,
            $data['content'],
            [
                'format'    => $data['format'] ?? 'single',
                'delimiter' => $data['delimiter'] ?? null,
                'source'    => 'admin_api',
            ]
        );

        $fresh = $import->fresh();

        return response()->json([
            'import_id'    => $import->id,
            'status'       => $fresh->status,
            'success_count' => $fresh->success_count,
            'failed_count' => $fresh->failed_count,
            'total'        => $import->total,
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

    public function importBatches(Request $request): JsonResponse
    {
        $batches = CardImport::query()
            ->with('product:id,name')
            ->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($batches);
    }
}
