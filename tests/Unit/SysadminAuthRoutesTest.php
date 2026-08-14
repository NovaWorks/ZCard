<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SysadminAuthRoutesTest extends TestCase
{
    #[Test]
    public function sysadmin_only_exposes_working_auth_routes(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/sysadmin/src/router/routes/staticRoutes.ts');
        $login = file_get_contents($root.'/sysadmin/src/views/auth/login/index.vue');

        $this->assertStringNotContainsString("name: 'Register'", $routes);
        $this->assertStringNotContainsString("name: 'ForgetPassword'", $routes);
        $this->assertStringContainsString('href="/forget-password"', $login);
    }
}
