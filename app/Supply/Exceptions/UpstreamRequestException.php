<?php

namespace App\Supply\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/** 可向管理员展示的脱敏上游请求错误。 */
class UpstreamRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromHttp(string $url, int $status, string $body = ''): self
    {
        [$code, $message, $retryable] = match (true) {
            $status === 401 => ['UPSTREAM_AUTH_FAILED', '上游认证失败：请检查 API 凭证和签名配置', false],
            $status === 403 => ['UPSTREAM_FORBIDDEN', '上游拒绝访问：可能存在 IP 白名单、WAF 或账号权限限制', false],
            $status === 404 => ['UPSTREAM_NOT_FOUND', '上游接口不存在：请检查站点地址、接口路径和伪静态配置', false],
            $status === 429 => ['UPSTREAM_RATE_LIMITED', '上游请求过于频繁，请稍后重试', true],
            $status >= 500 => ['UPSTREAM_UNAVAILABLE', "上游服务异常（HTTP {$status}）", true],
            default => ['UPSTREAM_HTTP_ERROR', "上游请求失败（HTTP {$status}）", false],
        };

        return new self($code, $message, [
            'http_status' => $status,
            'endpoint' => self::safeEndpoint($url),
            'response_preview' => self::safePreview($body),
        ], $retryable);
    }

    /** 从完整响应构造 HTTP 异常，保留脱敏诊断元数据。 */
    public static function fromResponse(string $url, Response $response): self
    {
        $error = self::fromHttp($url, $response->status(), $response->body());

        return $error->withContext(self::responseContext(
            $url,
            $response->body(),
            self::responseMetadata($response),
        ));
    }

    public static function fromConnection(string $url, Throwable $e): self
    {
        $raw = $e->getMessage();
        [$code, $message] = match (true) {
            str_contains($raw, 'cURL error 28'), str_contains(strtolower($raw), 'timed out') => ['UPSTREAM_TIMEOUT', '上游接口响应超时'],
            str_contains($raw, 'cURL error 6'), str_contains(strtolower($raw), 'could not resolve') => ['UPSTREAM_DNS_FAILED', '无法解析上游域名，请检查 DNS 和站点地址'],
            str_contains($raw, 'cURL error 35'), str_contains($raw, 'cURL error 60') => ['UPSTREAM_TLS_FAILED', '上游 HTTPS 证书或 TLS 连接失败'],
            default => ['UPSTREAM_CONNECT_FAILED', '无法连接上游站点，请检查域名、端口、防火墙和服务器网络'],
        };

        return new self($code, $message, [
            'endpoint' => self::safeEndpoint($url),
        ], true, $e);
    }

    public static function invalidResponse(string $url, string $body): self
    {
        return new self(
            'UPSTREAM_INVALID_RESPONSE',
            '上游返回了非 JSON 内容，可能被 WAF、Cloudflare 或登录页拦截',
            self::responseContext($url, $body),
        );
    }

    /** 非 JSON 响应可能是瞬时网关/WAF 页面，库存补查可对其做有界重试。 */
    public static function fromInvalidResponse(string $url, Response $response, bool $retryable = false): self
    {
        return new self(
            'UPSTREAM_INVALID_RESPONSE',
            '上游返回了非 JSON 内容，可能被 WAF、Cloudflare 或登录页拦截',
            self::responseContext($url, $response->body(), self::responseMetadata($response)),
            $retryable,
        );
    }

    /** 返回带额外诊断上下文的新异常，避免修改 readonly 属性。 */
    public function withContext(array $context): self
    {
        return new self(
            $this->errorCode,
            $this->getMessage(),
            array_merge($this->context, $context),
            $this->retryable,
            $this->getPrevious(),
        );
    }

    public static function business(string $url, string $message): self
    {
        return new self(
            'UPSTREAM_BUSINESS_ERROR',
            '上游返回错误：'.mb_substr(trim($message), 0, 300),
            ['endpoint' => self::safeEndpoint($url)],
        );
    }

    private static function safeEndpoint(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '';
        }

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');
    }

    /** @return array<string, int|string> */
    private static function responseMetadata(Response $response): array
    {
        return array_filter([
            'http_status' => $response->status(),
            'content_type' => self::safeHeader($response->header('Content-Type')),
            'cf_ray' => self::safeHeader($response->header('CF-Ray'), 100),
            'location' => self::safeLocation($response->header('Location')),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $metadata */
    private static function responseContext(string $url, string $body, array $metadata = []): array
    {
        return array_filter(array_merge([
            'endpoint' => self::safeEndpoint($url),
            'body_bytes' => strlen($body),
            'body_sha256' => hash('sha256', $body),
            'response_preview' => self::safePreview($body),
        ], $metadata), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function safeLocation(?string $location): ?string
    {
        if ($location === null || $location === '') {
            return null;
        }

        $parts = parse_url($location);
        if (! is_array($parts)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if (! isset($parts['host'])) {
            return str_starts_with($path, '/') ? $path : null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].$path;
    }

    private static function safeHeader(?string $value, int $limit = 160): ?string
    {
        $value = trim(preg_replace('/[^\x20-\x7E]/', '', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private static function safePreview(string $body): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
        if ($text === '') {
            return null;
        }

        // 上游偶尔会在错误体回显请求参数，先对常见密钥名做二次脱敏。
        $text = preg_replace(
            '/(app_key|api_secret|token|signature|authorization)(["\'\s:=]+)[^,\s}"\']+/iu',
            '$1$2[REDACTED]',
            $text,
        ) ?? $text;

        return mb_substr($text, 0, 300);
    }
}
