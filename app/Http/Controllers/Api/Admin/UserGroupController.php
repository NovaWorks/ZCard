<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台会员等级(user_groups)管理。
 * auth:sanctum 由路由组提供。
 */
class UserGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = UserGroup::orderBy('sort')->orderBy('id')->get();

        return response()->json($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:50',
            'discount' => 'nullable|numeric|min:0|max:100',
            'min_recharge' => 'nullable|numeric|min:0',
            'sort' => 'nullable|integer|min:0',
            'status' => 'nullable|boolean',
        ]);

        $group = UserGroup::create([
            'name' => $data['name'],
            'discount' => $data['discount'] ?? 100,
            'min_recharge' => $data['min_recharge'] ?? 0,
            'sort' => $data['sort'] ?? 0,
            'status' => array_key_exists('status', $data) ? (bool) $data['status'] : true,
        ]);

        return response()->json($group, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $group = UserGroup::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:50',
            'discount' => 'sometimes|numeric|min:0|max:100',
            'min_recharge' => 'sometimes|numeric|min:0',
            'sort' => 'sometimes|integer|min:0',
            'status' => 'sometimes|boolean',
        ]);

        $group->update($data);

        return response()->json($group->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        UserGroup::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
