<?php

namespace App\Supply;

use App\Models\SupplyNonce;
use App\Support\StorefrontConfig;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * 防重放 nonce 存储(spec §8.5)
 * 按 StorefrontConfig('supply_nonce_store') 选后端:redis|cache|database。
 * remember():未见则记录并返回 true;已见返回 false(拒绝重放)。
 * 安全审计 L-3:key 绑定命名空间(api_key),防止账号间 nonce 互相投毒。
 */
class NonceStore
{
    public function remember(string $namespace, string $nonce, int $ttlSeconds): bool
    {
        return match (StorefrontConfig::get('supply_nonce_store')) {
            'redis' => $this->rememberRedis($namespace, $nonce, $ttlSeconds),
            'database' => $this->rememberDatabase($namespace, $nonce, $ttlSeconds),
            default => $this->rememberCache($namespace, $nonce, $ttlSeconds),
        };
    }

    /** 清理已过期的 database nonce(调度任务调用) */
    public function pruneExpiredDatabase(): void
    {
        SupplyNonce::where('expires_at', '<', now())->delete();
    }

    private function rememberCache(string $namespace, string $nonce, int $ttl): bool
    {
        $key = "supply:nonce:{$namespace}:{$nonce}";

        // cache add 原子写入:已存在返回 false
        return Cache::add($key, 1, $ttl);
    }

    private function rememberRedis(string $namespace, string $nonce, int $ttl): bool
    {
        try {
            // SET NX 原子操作:键不存在才设置
            return (bool) Redis::connection()->set("supply:nonce:{$namespace}:{$nonce}", 1, 'EX', $ttl, 'NX');
        } catch (\Throwable) {
            // Redis 不可用时回退到 cache
            return $this->rememberCache($namespace, $nonce, $ttl);
        }
    }

    private function rememberDatabase(string $namespace, string $nonce, int $ttl): bool
    {
        try {
            // 复用现有唯一约束:命名空间拼进 nonce 值(不新增列,兼容旧表结构)。
            SupplyNonce::create([
                'nonce' => $namespace.'|'.$nonce,
                'expires_at' => now()->addSeconds($ttl),
            ]);

            return true;
        } catch (\Throwable $e) {
            // 唯一约束冲突 = 已存在 = 重放。
            // 兼容两种异常:Laravel QueryException(MySQL "Duplicate"/Pg "unique")
            // 与 UniqueConstraintViolationException(PDO/sqlite 直抛,Laravel 包装)。
            if ($e instanceof UniqueConstraintViolationException
                || $e instanceof QueryException
                || str_contains($e->getMessage(), 'Duplicate')
                || str_contains($e->getMessage(), 'unique')
                || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                return false;
            }
            throw $e;
        }
    }
}
