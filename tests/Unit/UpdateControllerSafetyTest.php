<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\Admin\UpdateController;
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
    public function rollback_uses_the_recorded_exact_commit_instead_of_new_head_parent(): void
    {
        $commitFile = storage_path('app/last_commit.txt');
        $previous = is_file($commitFile) ? file_get_contents($commitFile) : null;
        $head = $this->invokePrivate('resolveGitCommit', ['HEAD']);
        file_put_contents($commitFile, $head);

        try {
            $this->assertSame($head, $this->invokePrivate('rollbackTargetCommit'));
            $command = $this->invokePrivate('gitRollbackCommand', [$head]);
            $this->assertStringContainsString('reset --hard', $command);
            $this->assertStringContainsString($head, $command);
            $this->assertStringNotContainsString('HEAD~1', $command);
        } finally {
            if ($previous === null) {
                @unlink($commitFile);
            } else {
                file_put_contents($commitFile, $previous);
            }
        }
    }

    #[Test]
    public function rollback_rejects_non_commit_command_targets(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->invokePrivate('gitRollbackCommand', ['HEAD~1']);
    }

    #[Test]
    public function rollback_migration_batch_snapshot_is_validated(): void
    {
        $batchFile = storage_path('app/last_migration_batch.txt');
        $previous = is_file($batchFile) ? file_get_contents($batchFile) : null;

        try {
            file_put_contents($batchFile, '42');
            $this->assertSame(42, $this->invokePrivate('rollbackMigrationBatch'));

            file_put_contents($batchFile, '42; rm -rf /');
            $this->expectException(RuntimeException::class);
            $this->invokePrivate('rollbackMigrationBatch');
        } finally {
            if ($previous === null) {
                @unlink($batchFile);
            } else {
                file_put_contents($batchFile, $previous);
            }
        }
    }

    #[Test]
    public function rollback_runs_migrations_before_resetting_to_old_code(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Api/Admin/UpdateController.php'));
        $rollback = substr($source, strpos($source, 'public function rollback()'));
        $migrationPosition = strpos($rollback, "Artisan::call('migrate:rollback'");
        $resetPosition = strpos($rollback, '$this->gitRollbackCommand($targetCommit)');

        $this->assertNotFalse($migrationPosition);
        $this->assertNotFalse($resetPosition);
        $this->assertLessThan($resetPosition, $migrationPosition);
    }

    private function invokePrivate(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(UpdateController::class, $method);

        return $reflection->invoke(new UpdateController, ...$arguments);
    }
}
