<?php

namespace Tests\Feature;

use App\Supply\CallbackUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CallbackUrlGuardTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function test_blocked_urls_rejected(string $url): void
    {
        $this->assertFalse(app(CallbackUrlGuard::class)->isAllowed($url), "应拒绝: {$url}");
    }

    public static function blockedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/x'],
            'localhost' => ['http://localhost/x'],
            '非http' => ['ftp://example.com/x'],
        ];
    }

    #[DataProvider('allowedUrls')]
    public function test_allowed_urls_accepted(string $url): void
    {
        $this->assertTrue(app(CallbackUrlGuard::class)->isAllowed($url), "应允许: {$url}");
    }

    public static function allowedUrls(): array
    {
        return [
            'https公网' => ['https://example.com/callback'],
        ];
    }
}
