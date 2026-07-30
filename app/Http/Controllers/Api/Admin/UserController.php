<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 后台用户管理(spec §7.x)。用户 CRUD + 角色分配。
 * auth:sanctum 由路由组提供。
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles', 'userGroup', 'parent']);

        // 关键词：用户名 / 邮箱 / 手机
        if ($keyword = trim((string) $request->input('keyword', ''))) {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                  ->orWhere('email', 'like', "%{$keyword}%")
                  ->orWhere('phone', 'like', "%{$keyword}%");
            });
        }

        // 状态：1=正常 0=禁用
        if ($request->has('status') && $request->input('status') !== null && $request->input('status') !== '') {
            $query->where('status', (int) $request->input('status'));
        }

        // 会员等级
        if ($groupId = $request->input('group_id')) {
            $query->where('group_id', (int) $groupId);
        }

        $users = $query->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($users);
    }

    /**
     * 用户统计：总数 / 正常 / 禁用 / 今日新增。
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total' => User::count(),
            'active' => User::where('status', 1)->count(),
            'disabled' => User::where('status', 0)->count(),
            'todayNew' => User::whereDate('created_at', today())->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:60|unique:users,username',
            'email'    => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:6|max:60',
            'name'     => 'nullable|string|max:60',
            'status'   => 'nullable|integer|in:0,1',
            'phone'    => 'nullable|string|max:30',
            'qq'       => 'nullable|string|max:20',
            'avatar'   => 'nullable|string|max:255',
            'points'   => 'nullable|integer|min:0',
            'pid'      => 'nullable|integer|min:0',
            'group_id' => 'nullable|integer|min:0',
            'balance'  => 'nullable|integer',
            'roles'    => 'nullable|array',
            'roles.*'  => 'string|exists:roles,name',
        ]);

        $user = User::create([
            'username' => $data['username'],
            'email'    => $data['email'],
            'password' => $data['password'],
            'name'     => $data['name'] ?? null,
            'status'   => isset($data['status']) ? (int) $data['status'] : 1,
            'phone'    => $data['phone'] ?? null,
            'qq'       => $data['qq'] ?? null,
            'avatar'   => $data['avatar'] ?? null,
            'points'   => $data['points'] ?? 0,
            'pid'      => $data['pid'] ?? 0,
            'group_id' => $data['group_id'] ?? 0,
            'balance'  => $data['balance'] ?? 0,
        ]);

        if (! empty($data['roles'])) {
            $user->assignRole($data['roles']);
        }

        return response()->json($user->load(['roles', 'userGroup', 'parent']), 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(User::with(['roles', 'userGroup', 'parent'])->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'username' => 'sometimes|string|max:60|unique:users,username,' . $id,
            'email'    => 'sometimes|email|max:150|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:6|max:60',
            'name'     => 'nullable|string|max:60',
            'status'   => 'sometimes|integer|in:0,1',
            'phone'    => 'nullable|string|max:30',
            'qq'       => 'nullable|string|max:20',
            'avatar'   => 'nullable|string|max:255',
            'points'   => 'nullable|integer|min:0',
            'pid'      => 'nullable|integer|min:0',
            'group_id' => 'nullable|integer|min:0',
            'balance'  => 'nullable|integer',
            'roles'    => 'nullable|array',
            'roles.*'  => 'string|exists:roles,name',
        ]);

        $user->update([
            'username' => $data['username'] ?? $user->username,
            'email'    => $data['email'] ?? $user->email,
            'password' => $data['password'] ?? $user->password,
            'name'     => array_key_exists('name', $data) ? $data['name'] : $user->name,
            'status'   => array_key_exists('status', $data) ? (int) $data['status'] : $user->status,
            'phone'    => array_key_exists('phone', $data) ? $data['phone'] : $user->phone,
            'qq'       => array_key_exists('qq', $data) ? $data['qq'] : $user->qq,
            'avatar'   => array_key_exists('avatar', $data) ? $data['avatar'] : $user->avatar,
            'points'   => array_key_exists('points', $data) ? (int) $data['points'] : $user->points,
            'pid'      => array_key_exists('pid', $data) ? (int) $data['pid'] : $user->pid,
            'group_id' => array_key_exists('group_id', $data) ? (int) $data['group_id'] : $user->group_id,
            'balance'  => array_key_exists('balance', $data) ? (int) $data['balance'] : $user->balance,
        ]);

        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles'] ?? []);
        }

        return response()->json($user->fresh()->load(['roles', 'userGroup', 'parent']));
    }

    public function destroy(int $id): JsonResponse
    {
        User::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
