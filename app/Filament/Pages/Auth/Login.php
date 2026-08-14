<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/**
 * Filament 登录页账号级锁定(安全审计 M-7)。
 *
 * Filament 默认登录只有「每 IP 5 次/分钟」限流,API 侧的「同账号失败 5 次锁
 * 15 分钟」对其不生效——攻击者用 IP 池可持续爆破管理员密码。本页复用 API
 * AuthController 的同键(login_lock:{sha256(identifier)})与同阈值,两套入口
 * 共享锁定状态。
 */
class Login extends BaseLogin
{
    private const LOCK_THRESHOLD = 5;

    private const LOCK_TTL_SECONDS = 900;

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $credentials = $this->getCredentialsFromFormData($data);
        $identifierKey = collect(array_keys($credentials))->first(fn ($k) => $k !== 'password');
        $identifier = strtolower(trim((string) ($credentials[$identifierKey] ?? '')));

        $failKey = 'login_fail:'.hash('sha256', $identifier);
        $lockKey = 'login_lock:'.hash('sha256', $identifier);

        // 已锁定:与凭据错误同响应,不向攻击者泄露锁定状态
        if ($identifier !== '' && cache()->get($lockKey)) {
            $this->throwFailureValidationException();
        }

        try {
            $response = parent::authenticate();
            if ($identifier !== '') {
                cache()->forget($failKey);
                cache()->forget($lockKey);
            }

            return $response;
        } catch (ValidationException $e) {
            // 凭据失败计数:与 API 侧同阈值锁 15 分钟
            if ($identifier !== '') {
                $fails = (int) cache()->get($failKey, 0) + 1;
                cache()->put($failKey, $fails, self::LOCK_TTL_SECONDS);
                if ($fails >= self::LOCK_THRESHOLD) {
                    cache()->put($lockKey, true, self::LOCK_TTL_SECONDS);
                    cache()->forget($failKey);
                }
            }
            throw $e;
        }
    }
}
