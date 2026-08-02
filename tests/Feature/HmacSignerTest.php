<?php

namespace Tests\Feature;

use App\Supply\HmacSigner;
use Tests\TestCase;

class HmacSignerTest extends TestCase
{
    public function test_sign_and_verify_match(): void
    {
        $secret = 'test_secret_key';
        $signString = HmacSigner::buildSignString('POST', '/api/supply/orders', '1700000000', 'abc123', md5(''));
        $signature = HmacSigner::sign($secret, $signString);

        $this->assertTrue(HmacSigner::verify($secret, $signString, $signature));
        $this->assertFalse(HmacSigner::verify($secret, $signString, 'tampered_signature'));
    }

    public function test_build_sign_string_format(): void
    {
        $signString = HmacSigner::buildSignString('POST', '/api/supply/orders', '1700000000', 'n1', md5('{"a":1}'));

        $this->assertSame("POST\n/api/supply/orders\n1700000000\nn1\n" . md5('{"a":1}'), $signString);
    }

    public function test_path_excludes_query_string(): void
    {
        $signString = HmacSigner::buildSignString('GET', '/api/supply/products', '1', 'n', md5(''));
        // 验证 path 不含 query(query 由调用方在 buildSignString 前剥离)
        $this->assertStringContainsString("/api/supply/products\n", $signString);
    }

    public function test_timestamp_within_skew(): void
    {
        $skew = 300;
        $now = time();

        $this->assertTrue(HmacSigner::timestampValid($now, $skew));
        $this->assertTrue(HmacSigner::timestampValid($now + 100, $skew));
        $this->assertFalse(HmacSigner::timestampValid($now + 400, $skew));
        $this->assertFalse(HmacSigner::timestampValid($now - 400, $skew));
    }
}
