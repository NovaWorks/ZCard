<?php

namespace App\Supply;

use App\Models\SupplyNonce;
use App\Support\StorefrontConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * 防重放 nonce 存储(spec §8.5)
 * 按 StorefrontConfig('supply_nonce_store') 选后端:redis|cache|database。
 * remember():未见则记录并返回 true;已见返回 false(拒绝重放)。
 */
class NonceStore
{
    public function remember(string $nonce, int $ttlSeconds): bool
    {
        return match (StorefrontConfig::get('supply_nonce_store')) {
            'redis' => $this->rememberRedis($nonce, $ttlSeconds),
            'database' => $this->rememberDatabase($nonce, $ttlSeconds),
            default => $this->rememberCache($nonce, $ttlSeconds),
        };
    }

    /** 清理已过期的 database nonce(调度任务调用) */
    public function pruneExpiredDatabase(): void
    {
        SupplyNonce::where('expires_at', '<', now())->delete();
    }

    private function rememberCache(string $nonce, int $ttl): bool
    {
        $key = "supply:nonce:{$nonce}";
        // cache add 原子写入:已存在返回 false
        return Cache::add($key, 1, $ttl);
    }

    private function rememberRedis(string $nonce, int $ttl): bool
    {
        try {
            // SET NX 原子操作:键不存在才设置
            return (bool) Redis::connection()->set("supply:nonce:{$nonce}", 1, 'EX', $ttl, 'NX');
        } catch (\Throwable) {
            // Redis 不可用时回退到 cache
            return $this->rememberCache($nonce, $ttl);
        }
    }

    private function rememberDatabase(string $nonce, int $ttl): bool
    {
        try {
            SupplyNonce::create([
                'nonce' => $nonce,
                'expires_at' => now()->addSeconds($ttl),
            ]);
            return true;
        } catch (\Throwable $e) {
            // 唯一约束冲突 = 已存在 = 重放。
            // 兼容两种异常:Laravel QueryException(MySQL "Duplicate"/Pg "unique")
            // 与 UniqueConstraintViolationException(PDO/sqlite 直抛,Laravel 包装)。
            if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException
                || $e instanceof \Illuminate\Database\QueryException
                || str_contains($e->getMessage(), 'Duplicate')
                || str_contains($e->getMessage(), 'unique')
                || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                return false;
            }
            throw $e;
        }
    }
}
