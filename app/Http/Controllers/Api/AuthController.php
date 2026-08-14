<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AdminLoginAlertService;
use App\Support\CaptchaService;
use App\Support\MailService;
use App\Support\SecurityAudit;
use App\Support\StorefrontConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** 时序侧信道防护(V-9):用户不存在时用固定哈希做一次等代价比较 */
    private const DUMMY_BCRYPT_HASH = '$2y$12$AlsWrDJvJTgYvK5Qxg3s4.b6az4f4gg665Fw8WGt8XgjgGpCJD.m2';

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
            'password' => 'required|string|min:8|max:72',
            'captcha' => 'nullable|string',
            'referrer' => 'nullable|string|max:50',
        ];

        // 用户名最小长度(从配置读)
        $minLen = (int) (StorefrontConfig::get('username_min_length') ?? 3);
        $rules['username'] = "required|string|min:{$minLen}|max:50|unique:users,username";

        // 根据 register_type 调整必填项
        $registerType = StorefrontConfig::get('register_type') ?? 'email';
        if ($registerType === 'username') {
            $rules['email'] = 'nullable|email|max:255|unique:users,email';
        }

        // 安全(M-12):验证码校验先于唯一性校验——否则未过验证码即可枚举账号/邮箱。
        if (CaptchaService::isEnabled('register')) {
            if (! CaptchaService::verify('register', $request->input('captcha') ?? null)) {
                throw ValidationException::withMessages([
                    'captcha' => [__('messages.captcha_error')],
                ]);
            }
        }

        $data = $request->validate($rules);

        // 推广人绑定:referrer=用户名 → pid(trim 防首尾空格失配)
        $pid = 0;
        $referrerName = trim((string) ($data['referrer'] ?? ''));
        if ($referrerName !== '') {
            $referrerUser = User::where('username', $referrerName)->first();
            if ($referrerUser && $referrerUser->username !== $data['username']) {
                $pid = $referrerUser->id;
            }
        }

        $user = User::create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => 1,
            'pid' => $pid,
            'password_changed_at' => now(),
        ]);
        $user->assignRole('user');

        // 双模式认证:Bearer token 供 API 客户端;同时写入会话(web guard),
        // 前端 SPA 通过 HttpOnly Cookie 维持登录态(见 config/sanctum.php stateful)。
        $token = $user->createToken('storefront')->plainTextToken;
        auth()->login($user);
        // 安全(V-2):登录/注册成功后重建会话 ID,防会话固定攻击
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->json([
            'token' => $token,
            'user' => $this->userArray($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            // 支持邮箱或用户名登录(注册时有 username 字段;register_type 可配 email/username)
            'email' => 'required|string|max:255',
            'password' => 'required|string',
            'captcha' => 'nullable|string',
        ]);

        // 登录验证码校验(前台与后台共用;后台登录页在开启时同样显示并输入验证码)
        if (CaptchaService::isEnabled('login')) {
            if (! CaptchaService::verify('login', $data['captcha'] ?? null)) {
                throw ValidationException::withMessages([
                    'captcha' => [__('messages.captcha_error')],
                ]);
            }
        }

        // 邮箱或用户名匹配(field 参数兼容前端传 email/username/account)
        $identifier = trim((string) ($data['email'] ?? $data['account'] ?? ''));

        // 安全(M-13):账号级失败锁定——同账号连续失败 5 次锁 15 分钟(与 IP 限流互补)。
        $failKey = 'login_fail:'.hash('sha256', strtolower($identifier));
        $lockKey = 'login_lock:'.hash('sha256', strtolower($identifier));
        if (cache()->get($lockKey)) {
            SecurityAudit::record($request, 'login.locked', User::class, null, [
                'identifier' => mb_substr($identifier, 0, 100),
            ]);
            throw ValidationException::withMessages([
                'email' => [__('messages.auth.invalid_credentials')],
            ]);
        }

        $user = User::query()
            ->where(fn ($q) => $q->where('email', $identifier)->orWhere('username', $identifier))
            ->first();

        // 安全(V-9):用户不存在时也执行一次等代价哈希比较,拉平响应耗时防时序侧信道枚举用户名
        $passwordOk = $user !== null && Hash::check($data['password'], $user->password);
        if ($user === null) {
            Hash::check($data['password'], self::DUMMY_BCRYPT_HASH);
        }

        if (! $passwordOk) {
            SecurityAudit::record($request, 'login.failed', User::class, $user?->id, [
                'identifier' => mb_substr($identifier, 0, 100),
            ]);
            $fails = (int) cache()->get($failKey, 0) + 1;
            cache()->put($failKey, $fails, 900);
            if ($fails >= 5) {
                cache()->put($lockKey, true, 900);
                cache()->forget($failKey);
            }
            throw ValidationException::withMessages([
                'email' => [__('messages.auth.invalid_credentials')],
            ]);
        }

        cache()->forget($failKey);
        cache()->forget($lockKey);

        if ($user->status !== 1) {
            throw ValidationException::withMessages([
                'email' => [__('messages.auth.account_disabled')],
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('storefront')->plainTextToken;
        // 同时写入会话(web guard),SPA 通过 HttpOnly Cookie 保持登录态。
        auth()->login($user);
        // 安全(V-2):登录成功后重建会话 ID,防会话固定攻击
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        // 超级管理员:陌生 IP/设备登录告警(邮件/Telegram/企业微信,异步)。
        // 必须在登录审计**之前**执行:history 不含本次登录,陌生判定才准确
        if ($user->hasRole('super_admin')) {
            try {
                AdminLoginAlertService::checkAndAlert($request, $user);
            } catch (\Throwable $e) {
                // 告警失败不影响登录
                report($e);
            }
        }

        // 登录审计(记录 IP/UA,供后续异常登录检测)
        SecurityAudit::record($request, 'login.success', User::class, $user->id, [], 200, $user->id);

        return response()->json([
            'token' => $token,
            'user' => $this->userArray($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // 双模式登出:Bearer 令牌删除当前访问令牌;Cookie 会话直接失效(SPA 登录态)。
        if ($request->bearerToken()) {
            $token = $request->user()?->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }
        }
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('messages.auth.logout_done')]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userArray($request->user()));
    }

    /**
     * 修改密码(个人中心)。
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|max:72|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('messages.auth.invalid_current_password')],
            ]);
        }

        $user->update([
            'password' => $data['password'],
            'password_changed_at' => now(),
        ]);
        $user->tokens()->delete();
        // 安全(M-12):改密后吊销该用户全部会话(database 驱动删 sessions 行),
        // 被盗的其他 Cookie 会话一并踢出,不只当前会话。
        DB::table('sessions')->where('user_id', $user->id)->delete();
        // Cookie 会话同样失效,要求改密后重新登录。
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('messages.auth.password_changed')]);
    }

    /**
     * 更新个人资料(个人中心)。
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'sometimes|string|min:2|max:50|unique:users,username,'.$request->user()->id,
            'email' => 'sometimes|email|max:100|unique:users,email,'.$request->user()->id,
        ]);

        $user = $request->user();
        $user->update($data);

        return response()->json($this->userArray($user->fresh()));
    }

    /**
     * 发送找回密码验证码(邮箱)。
     */
    public function sendResetCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'captcha' => 'nullable|string',
        ]);

        // 图形验证码校验(找回密码场景;注册或登录任一开启即要求,防换 IP 灌验证码邮件)
        if (CaptchaService::isEnabled('register') || CaptchaService::isEnabled('login')) {
            if (! CaptchaService::verify('register', $data['captcha'] ?? null)) {
                throw ValidationException::withMessages([
                    'captcha' => [__('messages.auth.captcha_error')],
                ]);
            }
        }

        // 限频:60 秒内不可重发
        $email = strtolower(trim($data['email']));
        $emailKey = hash('sha256', $email);
        if (cache()->has("reset_code_sent:{$emailKey}")) {
            throw ValidationException::withMessages([
                'email' => [__('messages.auth.reset_code_throttle')],
            ]);
        }

        $user = User::where('email', $email)->where('status', 1)->first();
        if (! $user) {
            // 不暴露邮箱是否注册；仍写冷却键，避免枚举和滥用。
            cache()->put("reset_code_sent:{$emailKey}", true, 60);

            return response()->json(['message' => __('messages.auth.reset_code_sent')]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            MailService::sendCaptchaEmail($email, $code);
        } catch (\Throwable $e) {
            // 安全(M-11):邮件发送失败也返回与成功一致的响应,避免"已注册才 422"
            // 的邮箱枚举;失败细节只记服务日志。
            Log::error('找回密码邮件发送失败: '.$e->getMessage());
            cache()->put("reset_code_sent:{$emailKey}", true, 60);

            return response()->json(['message' => __('messages.auth.reset_code_sent')]);
        }

        // 邮件成功后才保存验证码，避免把已发送验证码覆盖为用户收不到的新值。
        cache()->put("reset_code:{$emailKey}", Hash::make($code), 300);
        cache()->put("reset_code_sent:{$emailKey}", true, 60);
        cache()->forget("reset_attempts:{$emailKey}");

        return response()->json(['message' => __('messages.auth.reset_code_sent')]);
    }

    /**
     * 重置密码(验证码+新密码)。
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|max:72',
        ]);

        $email = strtolower(trim($data['email']));
        $emailKey = hash('sha256', $email);
        $attemptKey = "reset_attempts:{$emailKey}";
        $attempts = (int) cache()->get($attemptKey, 0);
        if ($attempts >= 5) {
            throw ValidationException::withMessages([
                'code' => [__('messages.auth.reset_code_invalid')],
            ]);
        }

        $cachedCode = cache()->get("reset_code:{$emailKey}");
        $user = User::where('email', $email)->where('status', 1)->first();
        if (! is_string($cachedCode) || ! $user || ! Hash::check($data['code'], $cachedCode)) {
            cache()->put($attemptKey, $attempts + 1, 300);
            throw ValidationException::withMessages([
                'code' => [__('messages.auth.reset_code_invalid')],
            ]);
        }

        $user->update([
            'password' => $data['password'],
            'password_changed_at' => now(),
        ]);
        $user->tokens()->delete();
        // 安全(M-12):重置密码同样吊销全部会话,被盗 Cookie 一并失效
        DB::table('sessions')->where('user_id', $user->id)->delete();

        cache()->forget("reset_code:{$emailKey}");
        cache()->forget($attemptKey);

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
