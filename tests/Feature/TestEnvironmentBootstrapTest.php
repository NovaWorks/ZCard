<?php

namespace Tests\Feature;

use Tests\TestCase;

class TestEnvironmentBootstrapTest extends TestCase
{
    public function test_feature_tests_do_not_require_a_shared_install_lock(): void
    {
        $this->getJson('/api/health')->assertOk();
    }
}
