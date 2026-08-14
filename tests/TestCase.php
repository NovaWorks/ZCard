<?php

namespace Tests;

use App\Http\Middleware\EnsureInstalled;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 大多数功能测试覆盖已安装后的业务,不应依赖共享的 ignored 锁文件或执行顺序。
        // EnsureInstalledMiddlewareTest 会显式 withMiddleware() 覆盖此默认值。
        $this->withoutMiddleware(EnsureInstalled::class);
    }
}
