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

    private function invokePrivate(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(UpdateController::class, $method);

        return $reflection->invoke(new UpdateController, ...$arguments);
    }
}
