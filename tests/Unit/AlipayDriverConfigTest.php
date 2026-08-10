<?php

namespace Tests\Unit;

use App\Payment\Drivers\AlipayDriver;
use Tests\TestCase;

/**
 * 支付宝驱动配置构建:证书模式必填项(app_public_cert_path / alipay_root_cert_path)
 * 与 PEM 内容自动落盘逻辑。修复「缺少支付宝配置 -- [app_public_cert_path]」回归。
 */
class AlipayDriverConfigTest extends TestCase
{
    private function buildConfig(array $config): array
    {
        $driver = new AlipayDriver;
        $method = new \ReflectionMethod($driver, 'buildConfig');

        return $method->invoke($driver, $config);
    }

    public function test_build_config_includes_cert_paths(): void
    {
        $cfg = $this->buildConfig([
            'app_id' => '2021000000000000',
            'private_key' => '---PRIVATE---',
            'app_public_cert_path' => '/data/certs/appCertPublicKey.crt',
            'alipay_root_cert_path' => '/data/certs/alipayRootCert.crt',
        ]);

        $alipay = $cfg['alipay']['default'];
        $this->assertSame('2021000000000000', $alipay['app_id']);
        $this->assertSame('---PRIVATE---', $alipay['app_secret_cert']);
        $this->assertSame('/data/certs/appCertPublicKey.crt', $alipay['app_public_cert_path']);
        $this->assertSame('/data/certs/alipayRootCert.crt', $alipay['alipay_root_cert_path']);
        // 证书模式下不应再把公钥混用
        $this->assertArrayNotHasKey('app_public_cert', $alipay);
        $this->assertArrayNotHasKey('alipay_public_cert', $alipay);
    }

    public function test_pem_content_is_written_to_cert_dir(): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\nMIIBpzCCAQ2gAwIBAgIJAOe9Lef123456\n-----END CERTIFICATE-----";
        $cfg = $this->buildConfig([
            'app_id' => 'x',
            'private_key' => 'k',
            'app_public_cert_path' => $pem,
            'alipay_root_cert_path' => $pem,
        ]);

        $alipay = $cfg['alipay']['default'];
        $this->assertFileExists($alipay['app_public_cert_path']);
        $this->assertFileExists($alipay['alipay_root_cert_path']);
        $this->assertSame($pem, file_get_contents($alipay['app_public_cert_path']));
        // 同内容幂等:两次 build 得到同一文件
        $cfg2 = $this->buildConfig([
            'app_id' => 'x',
            'private_key' => 'k',
            'app_public_cert_path' => $pem,
            'alipay_root_cert_path' => $pem,
        ]);
        $this->assertSame($alipay['app_public_cert_path'], $cfg2['alipay']['default']['app_public_cert_path']);
    }

    public function test_pay_types_returns_configured_type(): void
    {
        $driver = new AlipayDriver;
        $this->assertSame(['scan'], $driver->getPayTypes(['pay_type' => 'scan']));
        $this->assertSame(['web'], $driver->getPayTypes([]));
        $this->assertSame(['web'], $driver->getPayTypes(['pay_type' => 'web']));
    }

    public function test_sn_mode_passes_cert_sns_without_paths(): void
    {
        $cfg = $this->buildConfig([
            'app_id' => '2021000000000000',
            'private_key' => 'k',
            'app_public_cert_sn' => 'SN-APP-123',
            'alipay_root_cert_sn' => 'SN-ROOT-456',
        ]);

        $alipay = $cfg['alipay']['default'];
        $this->assertSame('SN-APP-123', $alipay['app_public_cert_sn']);
        $this->assertSame('SN-ROOT-456', $alipay['alipay_root_cert_sn']);
        // SN 模式下证书路径可为空(yansongda 优先读 SN)
        $this->assertNull($alipay['app_public_cert_path']);
        $this->assertNull($alipay['alipay_root_cert_path']);
    }
}
