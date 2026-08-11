<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function __construct(private readonly MediaService $mediaService) {}

    /** 素材分页列表(搜索/分类/排序) */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'keyword' => $request->input('keyword'),
            'category_id' => $request->input('category_id'),
            'uncategorized' => $request->boolean('uncategorized'),
            'sort' => $request->input('sort', 'created_at'),
            'order' => $request->input('order', 'desc'),
            'per_page' => $request->input('per_page', 24),
        ];

        return response()->json($this->mediaService->paginate($filters));
    }

    /** 多文件上传 */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'required|image|mimes:jpeg,png,webp,gif|max:10240',
            'category_id' => 'nullable|integer',
        ]);

        $media = $this->mediaService->upload($data['files'], $data['category_id'] ?? null);

        return response()->json($media, 201);
    }

    /** 删除单张(物理删文件) */
    public function destroy(int $id): JsonResponse
    {
        $this->mediaService->delete($id);

        return response()->json(null, 204);
    }

    /** 批量删除 */
    public function batchDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $count = $this->mediaService->batchDelete($data['ids']);

        return response()->json(['deleted' => $count]);
    }

    /** 批量移动分类(category_id 可空=未分类) */
    public function batchMove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'category_id' => 'nullable|integer',
        ]);

        $count = $this->mediaService->batchMove($data['ids'], $data['category_id'] ?? null);

        return response()->json(['moved' => $count]);
    }
}
