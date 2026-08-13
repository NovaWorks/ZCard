<?php

namespace Tests\Unit;

use App\Supply\Exceptions\UpstreamRequestException;
use App\Supply\SupplySyncError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SupplySyncErrorTest extends TestCase
{
    #[Test]
    public function forbidden_response_is_explained_without_leaking_query_secrets(): void
    {
        $exception = UpstreamRequestException::fromHttp(
            'https://up.example.com/api/items?token=secret',
            403,
            '<html>blocked by waf token=secret-value</html>',
        );
        $diagnostic = SupplySyncError::diagnose($exception);

        $this->assertSame('UPSTREAM_FORBIDDEN', $diagnostic['code']);
        $this->assertStringContainsString('IP 白名单', $diagnostic['message']);
        $this->assertSame('https://up.example.com/api/items', $diagnostic['context']['endpoint']);
        $this->assertStringNotContainsString('?token=', $diagnostic['context']['endpoint']);
        $this->assertStringNotContainsString('secret-value', $diagnostic['context']['response_preview']);
    }

    #[Test]
    public function old_worker_dto_error_has_an_actionable_diagnostic(): void
    {
        $diagnostic = SupplySyncError::diagnose(new RuntimeException(
            'Undefined property: App\\Supply\\Dto\\UpstreamProduct::$productUrl',
        ));

        $this->assertSame('WORKER_VERSION_MISMATCH', $diagnostic['code']);
        $this->assertStringContainsString('Supervisor', $diagnostic['message']);
    }

    #[Test]
    public function stored_legacy_property_error_is_normalized_for_the_source_list(): void
    {
        $message = SupplySyncError::normalizeStoredMessage(
            'Undefined property: App\\Supply\\Dto\\UpstreamProduct::$productUrl',
        );

        $this->assertSame(
            '网站代码与队列进程版本不一致，请重启 queue:work / Supervisor 后重新同步',
            $message,
        );
    }
}
