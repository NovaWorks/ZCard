<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CaptchaService;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        // 注册开关
        if (! StorefrontConfig::get('register_open')) {
            throw ValidationException::withMessages([
                'username' => [__('messages.auth.register_closed')],
            ]);
        }

        $rules = [
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6|max:50',
            'captcha' => 'nullable|string',
        ];

        // 用户名最小长度(从配置读)
        $minLen = (int) (StorefrontConfig::get('username_min_length') ?? 3);
        $rules['username'] = "required|string|min:{$minLen}|max:50|unique:users,username";

        // 根据 register_type 调整必填项
        $registerType = StorefrontConfig::get('register_type') ?? 'email';
        if ($registerType === 'username') {
            $rules['email'] = 'nullable|email|max:255|unique:users,email';
        }

        $data = $request->validate($rules);

        // 注册验证码校验
        if (CaptchaService::isEnabled('register')) {
            if (! CaptchaService::verify('register', $data['captcha'] ?? null)) {
                throw ValidationException::withMessages([
                    'captcha' => [__('messages.captcha_error')],
                ]);
            }
        }

        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => 1,
            'password_changed_at' => now(),
        ]);
        $user->assignRole('user');

        $token = $user->createToken('storefront')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userArray($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'captcha' => 'nullable|string',
        ]);

        // 登录验证码校验
        if (CaptchaService::isEnabled('login')) {
            if (! CaptchaService::verify('login', $data['captcha'] ?? null)) {
                throw ValidationException::withMessages([
                    'captcha' => [__('messages.captcha_error')],
                ]);
            }
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('messages.auth.invalid_credentials')],
            ]);
        }

        if ($user->status !== 1) {
            throw ValidationException::withMessages([
                'email' => [__('messages.auth.account_disabled')],
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('storefront')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userArray($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => __('messages.auth.logout_done')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userArray($request->user()));
    }

    /**
     * 发送找回密码验证码(邮箱)。
     */
    public function sendResetCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
            'captcha' => 'nullable|string',
        ]);

        // 图形验证码校验(注册场景的验证码复用)
        if (\App\Support\CaptchaService::isEnabled('register')) {
            if (! \App\Support\CaptchaService::verify('register', $data['captcha'] ?? null)) {
                throw ValidationException::withMessages([
                    'captcha' => [__('messages.auth.captcha_error')],
                ]);
            }
        }

        // 生成 6 位验证码,存入缓存(5 分钟)
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        cache()->put("reset_code:{$data['email']}", $code, 300);

        // 限频:60 秒内不可重发
        if (cache()->has("reset_code_sent:{$data['email']}")) {
            throw ValidationException::withMessages([
                'email' => [__('messages.auth.reset_code_throttle')],
            ]);
        }
        cache()->put("reset_code_sent:{$data['email']}", true, 60);

        try {
            \App\Support\MailService::sendCaptchaEmail($data['email'], $code);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'email' => [__('messages.auth.mail_send_failed')],
            ]);
        }

        return response()->json(['message' => __('messages.auth.reset_code_sent')]);
    }

    /**
     * 重置密码(验证码+新密码)。
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:6|max:50',
        ]);

        $cachedCode = cache()->get("reset_code:{$data['email']}");
        if (! $cachedCode || $cachedCode !== $data['code']) {
            throw ValidationException::withMessages([
                'code' => [__('messages.auth.reset_code_invalid')],
            ]);
        }

        $user = User::where('email', $data['email'])->first();
        $user->update([
            'password' => $data['password'],
            'password_changed_at' => now(),
        ]);

        cache()->forget("reset_code:{$data['email']}");

        return response()->json(['message' => __('messages.auth.password_reset')]);
    }

    private function userArray(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'balance' => $user->balance,
        ];
    }
}
