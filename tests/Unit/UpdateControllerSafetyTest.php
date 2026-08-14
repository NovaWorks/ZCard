<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\UpdateController;
use App\Models\User;
use Illuminate\Cache\FileLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class UpdateControllerSafetyTest extends TestCase
{
    #[Test]
    public function git_sync_uses_the_fetched_commit_and_refreshes_origin_main(): void
    {
        $command = $this->invokePrivate('gitSyncCommand');

        $this->assertStringContainsString('main:refs/remotes/origin/main', $command);
        $this->assertStringContainsString('reset --hard FETCH_HEAD', $command);
        $this->assertStringNotContainsString('reset --hard origin/main', $command);
    }

    #[Test]
    public function failed_required_shell_command_throws_instead_of_reporting_success(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('测试命令失败(退出码 7): composer failed');

        $this->invokePrivate('shell', [
            "php -r 'fwrite(STDERR, \"composer failed\"); exit(7);'",
            true,
            '测试命令失败',
        ]);
    }

    #[Test]
    public function version_file_is_never_treated_as_a_user_change(): void
    {
        $files = $this->invokePrivate('parseDirtyFiles', [" M VERSION\n M config/app.php\n"]);

        $this->assertSame(['config/app.php'], array_values($files));
    }

    #[Test]
    public function online_update_sends_a_queue_restart_signal_and_records_it(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zcard-update-log-');
        $this->assertNotFalse($logFile);

        try {
            $this->invokePrivate('restartQueueWorkers', [$logFile]);

            $this->assertStringContainsString('已发送队列重启信号', (string) file_get_contents($logFile));
        } finally {
            @unlink($logFile);
        }
    }

    #[Test]
    public function online_update_resets_or_explicitly_reports_the_opcode_cache_state(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zcard-opcache-log-');
        $this->assertNotFalse($logFile);

        try {
            $this->invokePrivate('resetOpcodeCache', [$logFile]);

            $this->assertStringContainsString('OPcache', (string) file_get_contents($logFile));
        } finally {
            @unlink($logFile);
        }
    }

    #[Test]
    public function rejected_rollback_releases_the_operation_lock(): void
    {
        $countFile = storage_path('app/rollback.count');
        $originalCount = file_exists($countFile) ? file_get_contents($countFile) : null;
        $originalCacheStore = config('cache.default');
        config(['cache.default' => 'file']);
        file_put_contents($countFile, '3');

        $user = new User;
        $user->setRawAttributes(['password' => Hash::make('secret-password')]);
        request()->merge(['password' => 'secret-password']);
        request()->setUserResolver(fn (?string $guard = null) => $user);

        $probe = Cache::lock('zcard:system-update', 600);
        $this->assertInstanceOf(FileLock::class, $probe);
        $acquired = false;

        try {
            $response = (new UpdateController)->rollback();

            $this->assertSame(422, $response->getStatusCode());
            $this->assertStringContainsString('回滚次数已达上限', (string) $response->getData(true)['message']);
            $acquired = $probe->get();
            $this->assertTrue($acquired, '被安全校验拒绝的回滚不应继续占用操作锁');
        } finally {
            $acquired ? $probe->release() : $probe->forceRelease();

            if ($originalCount === null) {
                @unlink($countFile);
            } else {
                file_put_contents($countFile, $originalCount);
            }
            config(['cache.default' => $originalCacheStore]);
        }
    }

    private function invokePrivate(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(UpdateController::class, $method);

        return $reflection->invoke(new UpdateController, ...$arguments);
    }
}
