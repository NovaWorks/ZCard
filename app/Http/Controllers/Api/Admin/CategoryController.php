<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::with('parent')->orderBy('sort');
        if ($search = $request->input('keyword')) {
            $query->where('name', 'like', "%{$search}%");
        }
        $categories = $query->get();
        // 构建树
        $tree = $this->buildTree($categories);
        return response()->json($tree);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'sort' => 'nullable|integer',
            'status' => 'boolean',
        ]);
        $data['merchant_id'] = 1;
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) . '-' . Str::random(4);
        }
        $data['status'] = ($data['status'] ?? true) ? 1 : 0;
        $category = Category::create($data);
        return response()->json($category, 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Category::with('parent', 'children')->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'slug' => 'sometimes|string|max:100',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'sort' => 'sometimes|integer',
            'status' => 'boolean',
        ]);
        if (isset($data['status'])) {
            $data['status'] = $data['status'] ? 1 : 0;
        }
        $category->update($data);
        return response()->json($category->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        // 检查是否有子分类
        if ($category->children()->exists()) {
            return response()->json(['message' => '该分类下有子分类，无法删除'], 422);
        }
        $category->delete();
        return response()->json(null, 204);
    }

    /** 所有分类(扁平,供下拉选择用) */
    public function all(): JsonResponse
    {
        $categories = Category::where('status', 1)
            ->orderBy('sort')
            ->get(['id', 'name', 'parent_id']);
        return response()->json($this->buildTree($categories));
    }

    private function buildTree($categories, $parentId = null): array
    {
        return $categories->where('parent_id', $parentId)->map(function ($cat) use ($categories) {
            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'parent_id' => $cat->parent_id,
                'sort' => $cat->sort,
                'status' => $cat->status,
                'children' => $this->buildTree($categories, $cat->id),
            ];
        })->values()->toArray();
    }
}
