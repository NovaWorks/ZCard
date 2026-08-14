<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\User;
use App\Support\BillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            'email' => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:10|max:72',
            'name' => 'nullable|string|max:60',
            'status' => 'nullable|integer|in:0,1',
            'phone' => 'nullable|string|max:30',
            'qq' => 'nullable|string|max:20',
            'avatar' => 'nullable|string|max:255',
            'points' => 'nullable|integer|min:0',
            'pid' => 'nullable|integer|min:0',
            'group_id' => 'nullable|integer|min:0',
            'balance' => 'nullable|integer',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'name' => $data['name'] ?? null,
            'status' => isset($data['status']) ? (int) $data['status'] : 1,
            'phone' => $data['phone'] ?? null,
            'qq' => $data['qq'] ?? null,
            'avatar' => $data['avatar'] ?? null,
            'points' => $data['points'] ?? 0,
            'pid' => $data['pid'] ?? 0,
            'group_id' => $data['group_id'] ?? 0,
            'balance' => $data['balance'] ?? 0,
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
            'username' => 'sometimes|string|max:60|unique:users,username,'.$id,
            'email' => 'sometimes|email|max:150|unique:users,email,'.$id,
            'password' => 'sometimes|string|min:10|max:72',
            'name' => 'nullable|string|max:60',
            'status' => 'sometimes|integer|in:0,1',
            'phone' => 'nullable|string|max:30',
            'qq' => 'nullable|string|max:20',
            'avatar' => 'nullable|string|max:255',
            'points' => 'nullable|integer|min:0',
            'pid' => 'nullable|integer|min:0',
            'group_id' => 'nullable|integer|min:0',
            // 安全(H2 整改后恢复):余额修改不再直写 users.balance,
            // 而是计算差额后经 BillService 生成账单流水(账单管理可查、含操作人),
            // 与 POST /api/admin/bills/adjust 同一套口径;禁止修改本人余额。
            'balance' => 'nullable|integer|min:0',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        // 余额变动先校验并入账(失败时不落任何用户字段,保证一致)。
        if (array_key_exists('balance', $data) && (int) $data['balance'] !== (int) $user->balance) {
            if ($user->id === $request->user()->id) {
                throw ValidationException::withMessages(['balance' => ['不允许修改本人账户余额']]);
            }

            $delta = (int) $data['balance'] - (int) $user->balance;
            $type = $delta > 0 ? Bill::TYPE_INCOME : Bill::TYPE_EXPENSE;
            try {
                BillService::record(
                    $user->id,
                    abs($delta),
                    $type,
                    '管理员调整余额(用户管理)',
                    null,
                    $request->user()->id,
                );
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['balance' => [$e->getMessage()]]);
            }
        }

        $user->update([
            'username' => $data['username'] ?? $user->username,
            'email' => $data['email'] ?? $user->email,
            'password' => $data['password'] ?? $user->password,
            'name' => array_key_exists('name', $data) ? $data['name'] : $user->name,
            'status' => array_key_exists('status', $data) ? (int) $data['status'] : $user->status,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $user->phone,
            'qq' => array_key_exists('qq', $data) ? $data['qq'] : $user->qq,
            'avatar' => array_key_exists('avatar', $data) ? $data['avatar'] : $user->avatar,
            'points' => array_key_exists('points', $data) ? (int) $data['points'] : $user->points,
            'pid' => array_key_exists('pid', $data) ? (int) $data['pid'] : $user->pid,
            'group_id' => array_key_exists('group_id', $data) ? (int) $data['group_id'] : $user->group_id,
        ]);

        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles'] ?? []);
        }

        // 权限、状态或密码变化后，旧令牌不得继续沿用原授权。
        if (array_key_exists('roles', $data)
            || array_key_exists('status', $data)
            || array_key_exists('password', $data)) {
            if (array_key_exists('password', $data)) {
                $user->forceFill(['password_changed_at' => now()])->save();
            }
            $user->tokens()->delete();
        }

        return response()->json($user->fresh()->load(['roles', 'userGroup', 'parent']));
    }

    public function destroy(int $id): JsonResponse
    {
        User::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
