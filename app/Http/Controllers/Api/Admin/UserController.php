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
        $query = User::query()->with('roles');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('id')
            ->paginate($request->integer('pageSize', 15));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:60|unique:users,username',
            'email'    => 'required|email|max:150|unique:users,email',
            'password' => 'required|string|min:6|max:60',
            'name'     => 'nullable|string|max:60',
            'status'   => 'nullable|string|in:active,disabled',
            'roles'    => 'nullable|array',
            'roles.*'  => 'string|exists:roles,name',
        ]);

        $user = User::create([
            'username' => $data['username'],
            'email'    => $data['email'],
            'password' => $data['password'],
            'name'     => $data['name'] ?? null,
            'status'   => $data['status'] ?? 'active',
        ]);

        if (! empty($data['roles'])) {
            $user->assignRole($data['roles']);
        }

        return response()->json($user->load('roles'), 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(User::with('roles')->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'username' => 'sometimes|string|max:60|unique:users,username,' . $id,
            'email'    => 'sometimes|email|max:150|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:6|max:60',
            'name'     => 'nullable|string|max:60',
            'status'   => 'sometimes|string|in:active,disabled',
            'roles'    => 'nullable|array',
            'roles.*'  => 'string|exists:roles,name',
        ]);

        $user->update([
            'username' => $data['username'] ?? $user->username,
            'email'    => $data['email'] ?? $user->email,
            'password' => $data['password'] ?? $user->password,
            'name'     => array_key_exists('name', $data) ? $data['name'] : $user->name,
            'status'   => $data['status'] ?? $user->status,
        ]);

        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles'] ?? []);
        }

        return response()->json($user->fresh()->load('roles'));
    }

    public function destroy(int $id): JsonResponse
    {
        User::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
