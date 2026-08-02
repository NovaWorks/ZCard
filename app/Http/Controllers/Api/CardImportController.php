<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CardImport;
use App\Support\CardImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardImportController extends Controller
{
    public function import(Request $request, CardImportService $service): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'content' => 'required|string',
            'format' => 'nullable|in:single,multi',
            'delimiter' => 'nullable|string',
        ]);

        $import = $service->import(
            $data['product_id'],
            $request->user()->id,
            $data['content'],
            [
                'format' => $data['format'] ?? 'single',
                'delimiter' => $data['delimiter'] ?? null,
                'source' => 'api',
            ]
        );

        $fresh = $import->fresh();
        return response()->json([
            'import_id' => $import->id,
            'status' => $fresh->status,
            'success_count' => $fresh->success_count,
            'skipped_count' => $fresh->skipped_count,
            'failed_count' => $fresh->failed_count,
            'total' => $import->total,
        ]);
    }

    public function status(int $id): JsonResponse
    {
        $import = CardImport::findOrFail($id);
        return response()->json([
            'import_id' => $import->id,
            'status' => $import->status,
            'success_count' => $import->success_count,
            'failed_count' => $import->failed_count,
            'total' => $import->total,
        ]);
    }

    public function revoke(int $id, CardImportService $service): JsonResponse
    {
        $deleted = $service->revokeImport($id);
        return response()->json(['import_id' => $id, 'revoked_cards' => $deleted]);
    }
}
