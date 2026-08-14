<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * issue #22 回归:被安全校验拒绝的在线回滚不得泄漏操作锁。
 *
 * 复现要点:必须用 file/redis 这类持久锁驱动——array 测试驱动在 Lock 对象
 * 销毁时自动释放进程内锁,无法复现生产行为,这正是该 bug 此前未被测试抓住的原因。
 */
class UpdateRollbackLockReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK_NAME = 'zcard:system-update';

    protected function setUp(): void
    {
        parent::setUp();
        // 切换为 file 锁驱动:操作锁在请求结束后依然存在,可断言是否被泄漏
        config(['cache.default' => 'file']);
        Role::firstOrCreate(['name' => 'super_admin']);
    }

    public function test_rejected_rollback_releases_operation_lock(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        // 强制清理历史残留(包括此前在旧代码上泄漏、尚未过期的锁),确保锁处于空闲状态
        Cache::lock(self::LOCK_NAME, 600)->forceRelease();

        // 回滚次数已达上限 → 预检应返回 422(不触碰代码/数据库/工作区)
        $countFile = storage_path('app/rollback.count');
        file_put_contents($countFile, '3');

        try {
            $this->actingAs($admin, 'sanctum')
                ->postJson('/api/admin/update/rollback', ['password' => 'password'])
                ->assertStatus(422)
                ->assertJsonPath('message', '回滚次数已达上限(3 次),如需继续请人工介入处理');

            // 关键断言:422 拒绝后锁必须立即可重新获取。
            // 修复前该锁被占用到 600 秒 TTL 过期,期间所有更新/回退请求持续返回 409。
            $reacquire = Cache::lock(self::LOCK_NAME, 600);
            $this->assertTrue(
                $reacquire->get(),
                '被安全校验拒绝的回滚残留了操作锁(issue #22):后续 10 分钟内更新/回退将全部返回 409',
            );
            $reacquire->release();
        } finally {
            @unlink($countFile);
        }
    }
}
