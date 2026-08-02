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

    protected function postJson(string $path, array $body, array $headers = []): array
    {
        $url = $this->baseUrl() . $path;
        $resp = Http::withHeaders($headers)->timeout($this->requestTimeout())->post($url, $body);
        if (! $resp->successful()) {
            Log::warning('supply upstream http error', ['url' => $url, 'status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException("上游请求失败: HTTP {$resp->status()}");
        }
        return $resp->json();
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
