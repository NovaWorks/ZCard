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
        // 状态筛选
        if ($request->has('status') && $request->input('status') !== '' && $request->input('status') !== null) {
            $query->where('status', (int) $request->input('status'));
        }
        // 隐藏筛选
        if ($request->has('hide') && $request->input('hide') !== '' && $request->input('hide') !== null) {
            $query->where('hide', (int) $request->input('hide'));
        }

        $categories = $query->get();
        return response()->json($this->buildTree($categories));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100',
            'icon' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'sort' => 'nullable|integer|min:0|max:65535',
            'status' => 'boolean',
            'hide' => 'boolean',
        ]);
        $data['merchant_id'] = 1;
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']) . '-' . Str::random(4);
        }
        if (Category::where('merchant_id', 1)->where('slug', $data['slug'])->exists()) {
            return response()->json(['message' => '标识已存在,请更换'], 422);
        }
        $data['status'] = ($data['status'] ?? true) ? 1 : 0;
        $data['hide'] = ($data['hide'] ?? false) ? 1 : 0;
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
            'slug' => 'sometimes|nullable|string|max:100',
            'icon' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255',
            'parent_id' => 'nullable|integer|exists:categories,id',
            'sort' => 'sometimes|integer|min:0|max:65535',
            'status' => 'boolean',
            'hide' => 'boolean',
        ]);

        // 循环引用检测
        if (array_key_exists('parent_id', $data) && $data['parent_id']) {
            if ((int) $data['parent_id'] === $id) {
                return response()->json(['message' => '不能将分类设为自己的子分类'], 422);
            }
            if ($this->isDescendant($id, (int) $data['parent_id'])) {
                return response()->json(['message' => '不能将分类移动到其子分类下'], 422);
            }
        }

        // slug 唯一性
        if (! empty($data['slug'])) {
            $exists = Category::where('merchant_id', 1)
                ->where('slug', $data['slug'])
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return response()->json(['message' => '标识已存在,请更换'], 422);
            }
        }

        if (isset($data['status'])) {
            $data['status'] = $data['status'] ? 1 : 0;
        }
        if (isset($data['hide'])) {
            $data['hide'] = $data['hide'] ? 1 : 0;
        }
        $category->update($data);
        return response()->json($category->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        if ($category->children()->exists()) {
            return response()->json(['message' => '该分类下有子分类,无法删除'], 422);
        }
        if ($category->products()->exists()) {
            return response()->json(['message' => '该分类下有商品,无法删除'], 422);
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

    /**
     * 批量更新排序。
     */
    public function updateSort(Request $request): JsonResponse
    {
        $items = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:categories,id',
            'items.*.sort' => 'required|integer|min:0|max:65535',
            'items.*.parent_id' => 'nullable|integer|exists:categories,id',
        ])['items'];

        foreach ($items as $item) {
            $update = ['sort' => $item['sort']];
            if (array_key_exists('parent_id', $item)) {
                $update['parent_id'] = $item['parent_id'];
            }
            Category::where('id', $item['id'])->update($update);
        }

        return response()->json(['updated' => count($items)]);
    }

    /**
     * 批量启用/禁用/隐藏切换。
     * 接收 {ids: [1,2,3], field: 'status'|'hide', value: 0|1}
     */
    public function batchUpdate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:categories,id',
            'field' => 'required|string|in:status,hide',
            'value' => 'required|integer|in:0,1',
        ]);

        $count = Category::whereIn('id', $data['ids'])->update([
            $data['field'] => $data['value'],
        ]);

        return response()->json(['updated' => $count]);
    }

    private function isDescendant(int $ancestorId, int $descendantId): bool
    {
        $children = Category::where('parent_id', $ancestorId)->pluck('id');
        foreach ($children as $childId) {
            if ($childId === $descendantId) return true;
            if ($this->isDescendant($childId, $descendantId)) return true;
        }
        return false;
    }

    private function buildTree($categories, $parentId = null): array
    {
        return $categories->where('parent_id', $parentId)->map(function ($cat) use ($categories) {
            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'icon' => $cat->icon,
                'description' => $cat->description,
                'parent_id' => $cat->parent_id,
                'sort' => $cat->sort,
                'status' => (int) $cat->status,
                'hide' => (int) $cat->hide,
                'created_at' => $cat->created_at?->toDateTimeString(),
                'children' => $this->buildTree($categories, $cat->id),
            ];
        })->values()->toArray();
    }
}
