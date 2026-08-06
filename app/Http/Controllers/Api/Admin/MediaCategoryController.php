<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MediaCategoryController extends Controller
{
    public function __construct(private readonly MediaService $mediaService) {}

    /** 分类列表(含各分类图片数量、未分类数量) */
    public function index(): JsonResponse
    {
        return response()->json($this->mediaService->categories());
    }

    /** 新增分类 */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:30']);

        try {
            $category = $this->mediaService->createCategory($data['name']);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()['name'][0] ?? '分类名称无效'], 422);
        }

        return response()->json($category, 201);
    }

    /** 修改分类名称 */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:30']);

        try {
            $category = $this->mediaService->renameCategory($id, $data['name']);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()['name'][0] ?? '分类名称无效'], 422);
        }

        return response()->json($category);
    }

    /** 删除分类(有图片时 422 提示先迁移) */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->mediaService->deleteCategory($id);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()['category'][0] ?? '无法删除'], 422);
        }

        return response()->json(null, 204);
    }

    /** 迁移分类下图片到目标分类后删除当前分类(target_category_id 可空=未分类) */
    public function move(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['target_category_id' => 'nullable|integer']);

        try {
            $this->mediaService->moveCategory($id, $data['target_category_id'] ?? null);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()['category'][0] ?? '迁移失败'], 422);
        }

        return response()->json(['deleted' => true]);
    }
}
