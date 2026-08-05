<?php

namespace App\Supply\Drivers\Concerns;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 驱动 HTTP 请求公共封装。
 * 注意:用 Laravel Http facade(底层 Guzzle),带超时。
 */
trait MakesHttpRequests
{
    protected function requestTimeout(): int
    {
        return (int) ($this->source->settings['timeout'] ?? 30);
    }

    protected function baseUrl(): string
    {
        return rtrim($this->source->base_url, '/');
    }

    protected function credentials(): array
    {
        return $this->source->credentials ?? [];
    }

    /**
     * 把请求体编码成「即将发送的 JSON 字符串」。
     *
     * 带 HMAC 签名的上游要求 md5(原始 body) 参与签名,而签名与实际发送必须基于同一份字节,
     * 所以统一在这里编码:调用方拿到的字符串既用于算签名,也原样交给 postRaw() 发出。
     * 空数组编码为空串(而非 "[]"),与「无 body」的 md5('') 口径一致。
     */
    protected function encodeBody(array $body): string
    {
        return $body === [] ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 发送原始 JSON 字符串(不交给 HTTP 客户端二次编码)。
     *
     * 唯一的 POST 入口 —— 刻意不提供 post(array) 便捷版:一旦客户端自己编码,
     * 发出的字节就可能与签名时哈希的字节不一致(如空数组会被编码成 "[]"),
     * 服务端验签必失败。带 body 时先 encodeBody() 拿到字符串,
     * 再把同一份字符串同时喂给签名函数和本方法。
     */
    protected function postRaw(string $path, string $rawBody, array $headers = []): array
    {
        $url = $this->baseUrl() . $path;
        $resp = Http::withHeaders($headers)->timeout($this->requestTimeout())
            ->withBody($rawBody, 'application/json')->post($url);
        if (! $resp->successful()) {
            Log::warning('supply upstream http error', ['url' => $url, 'status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException("上游请求失败: HTTP {$resp->status()}");
        }
        // 上游返回非 JSON(WAF 拦截页等)时 json() 为 null,归一成空数组交由调用方判定
        return $resp->json() ?? [];
    }

    protected function getJson(string $path, array $query = [], array $headers = []): array
    {
        $url = $this->baseUrl() . $path;
        $resp = Http::withHeaders($headers)->timeout($this->requestTimeout())->get($url, $query);
        if (! $resp->successful()) {
            throw new \RuntimeException("上游请求失败: HTTP {$resp->status()}");
        }
        return $resp->json();
    }
}
