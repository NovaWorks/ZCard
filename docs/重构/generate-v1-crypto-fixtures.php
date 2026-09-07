<?php
/**
 * 1.x 加密 golden vector 生成器（迁移工具 P0 交付物）。
 *
 * 用途：为 zcard-next `internal/migratev1/laracrypt` 提供由【真实 Laravel 加密器】
 * 产生的固定测试载荷，钉死 Go 侧解密实现与 PHP 侧逐字节兼容。
 *
 * 生成物（JSON）包含：测试密钥、逐条向量（载荷 + 期望明文 / 期望错误）。
 * 覆盖形态：Crypt 字符串（ASCII/中文/JSON/多行/空串）、serialize 载荷、卡密密文
 * （独立密钥）、payment_channels.config 双层（JSON 引号 + Crypt）、settings SECRET
 * 双层、卡密历史明文、明文渠道配置、篡改 mac 拒收、settings 里的卡密钥匙解析。
 *
 * 用法：
 *   php generate-v1-crypto-fixtures.php <输出路径.json>
 *
 * 密钥为确定性派生（非随机），重跑字节级一致；仅测试用途，绝不使用生产密钥。
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Illuminate\Encryption\Encrypter;

// ---------- 确定性测试密钥（32 字节） ----------
$appKeyRaw = substr(hash('sha256', 'zcard-v1-fixtures-app-key', true), 0, 32);
$cardKeyRaw = substr(hash('sha256', 'zcard-v1-fixtures-card-key', true), 0, 32);
if (strlen($appKeyRaw) !== 32 || strlen($cardKeyRaw) !== 32) {
    fwrite(STDERR, "密钥派生长度异常\n");
    exit(1);
}
// 1.x 的 .env 形态：base64: 前缀
$appKeyEnv = 'base64:' . base64_encode($appKeyRaw);
$cardKeyEnv = 'base64:' . base64_encode($cardKeyRaw);

$appCrypt = new Encrypter($appKeyRaw, 'AES-256-CBC');
$cardCrypt = new Encrypter($cardKeyRaw, 'AES-256-CBC');

$vectors = [];

function vec(array &$vectors, string $name, string $kind, string $payload, ?string $expect, ?string $expectError = null): void
{
    $v = ['name' => $name, 'kind' => $kind, 'payload' => $payload];
    if ($expect !== null) {
        $v['expect'] = $expect;
    }
    if ($expectError !== null) {
        $v['expect_error'] = $expectError;
    }
    $vectors[] = $v;
}

// ---------- 1. Crypt::encryptString（supply_sources.credentials / supplier_accounts.api_secret / settings SECRET） ----------
foreach ([
    'crypt_string_ascii' => 'hello zcard',
    'crypt_string_cjk' => '中文卡密内容-测试【】￥%&*()',
    'crypt_string_json' => '{"app_id":"1001","app_secret":"sk-9f8e7d","gateway":"https://pay.example.com/submit"}',
    'crypt_string_multiline' => "第一行\nsecond line\r\n第三行\ttab",
    'crypt_string_empty' => '',
    'crypt_string_255_border' => str_repeat('x', 255), // Laravel 载荷 255 分界（无影响，纯边界样本）
] as $name => $plain) {
    vec($vectors, $name, 'crypt_string', $appCrypt->encryptString($plain), $plain);
}

// ---------- 2. serialize 载荷（防御性：1.x 全用 encryptString，但 Go 侧需兼容 maybe-unserialize） ----------
$serPlain = 'serialized-string-value';
vec($vectors, 'crypt_serialized_string', 'crypt_serialized', $appCrypt->encrypt($serPlain, true), $serPlain);

// ---------- 3. 卡密密文（CardCipher = 独立密钥的 Encrypter::encryptString） ----------
foreach ([
    'card_ascii' => 'CARD-AB12-CD34-EF56',
    'card_cjk' => '月卡兑换码：优享会员30天',
    'card_json' => '{"account":"user@example.com","password":"p@ss","remark":"含引号\\"转义"}',
    'card_long' => str_repeat('K', 1024),
] as $name => $plain) {
    vec($vectors, $name, 'card', $cardCrypt->encryptString($plain), $plain);
}

// ---------- 4. payment_channels.config 双层：列值 = json_encode(密文字符串) ----------
$payCfg = json_encode(['app_id' => '10086', 'key' => '商户秘钥ABC', 'gateway' => 'https://api.epay.example/submit'], JSON_UNESCAPED_UNICODE);
vec($vectors, 'payment_config', 'payment_config', json_encode($appCrypt->encryptString($payCfg)), $payCfg);
// 历史明文渠道配置（加密上线前的存量）：整列就是明文 JSON，无引号包裹
vec($vectors, 'payment_config_legacy_plain', 'payment_config', $payCfg, $payCfg);

// ---------- 5. settings SECRET 双层：value 列 = json_encode(密文字符串) ----------
vec($vectors, 'setting_secret', 'setting_secret', json_encode($appCrypt->encryptString('smtp-relay-password-测试')), 'smtp-relay-password-测试');
// settings 里的卡密钥匙（CardCipher::resolveKey 路径）：Crypt 密文包住 base64: 钥匙串
vec($vectors, 'setting_card_key', 'setting_card_key', json_encode($appCrypt->encryptString($cardKeyEnv)), $cardKeyEnv);
// 历史明文存的卡密钥匙
vec($vectors, 'setting_card_key_legacy_plain', 'setting_card_key', json_encode($cardKeyEnv), $cardKeyEnv);

// ---------- 6. 卡密历史明文（加密开关关闭期间的存量） ----------
vec($vectors, 'plaintext_card', 'plaintext_card', 'PLAIN-CARD-0001-直接明文', 'PLAIN-CARD-0001-直接明文');

// ---------- 7. 篡改 mac（必须拒收，防密钥错配静默出乱码） ----------
$valid = json_decode(base64_decode($appCrypt->encryptString('tamper-check')), true);
$valid['mac'] = str_starts_with($valid['mac'], '0') ? '1' . substr($valid['mac'], 1) : '0' . substr($valid['mac'], 1);
vec($vectors, 'tampered_mac', 'crypt_string', base64_encode(json_encode($valid, JSON_UNESCAPED_SLASHES)), null, 'mac');

// ---------- 输出 ----------
$out = [
    'comment' => 'ZCard 1.x 加密 golden vectors（真实 Laravel Encrypter 生成；密钥为确定性测试派生，禁止用于生产）',
    'cipher' => 'AES-256-CBC',
    'app_key' => $appKeyEnv,
    'card_key' => $cardKeyEnv,
    'vectors' => $vectors,
];

$path = $argv[1] ?? null;
$json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($path === null) {
    echo $json, "\n";
} else {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        fwrite(STDERR, "无法创建目录: $dir\n");
        exit(1);
    }
    if (file_put_contents($path, $json . "\n") === false) {
        fwrite(STDERR, "写入失败: $path\n");
        exit(1);
    }
    echo sprintf("已生成 %d 条向量 → %s\n", count($vectors), $path);
}

// ---------- 自验：PHP 侧逐条回解（保证生成物本身没坏） ----------
foreach ($vectors as $v) {
    if (!isset($v['expect'])) {
        continue; // 期望错误的向量（如篡改 mac）由 Go 侧验证拒收
    }
    $plain = $v['expect'];
    switch ($v['kind']) {
        case 'crypt_string':
            $round = $appCrypt->decryptString($v['payload']);
            break;
        case 'crypt_serialized':
            $round = $appCrypt->decrypt($v['payload']); // 默认 unserialize=true 回到原值
            break;
        case 'card':
            $round = $cardCrypt->decryptString($v['payload']);
            break;
        case 'payment_config':
            $round = $v['name'] === 'payment_config_legacy_plain'
                ? $v['payload'] // 明文配置无密文可解
                : $appCrypt->decryptString(json_decode($v['payload'], true));
            break;
        case 'setting_secret':
            $round = $appCrypt->decryptString(json_decode($v['payload'], true));
            break;
        case 'setting_card_key':
            $round = $v['name'] === 'setting_card_key_legacy_plain'
                ? json_decode($v['payload'], true)
                : $appCrypt->decryptString(json_decode($v['payload'], true));
            break;
        case 'plaintext_card':
            $round = $v['payload'];
            break;
        default:
            fwrite(STDERR, "未知向量类型: {$v['kind']}\n");
            exit(1);
    }
    if ($round !== $plain) {
        fwrite(STDERR, "自验失败: {$v['name']}\n");
        exit(1);
    }
}
echo "PHP 侧自验通过\n";
