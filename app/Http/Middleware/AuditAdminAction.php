<?php

namespace App\Http\Middleware;

use App\Support\SecurityAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 记录后台写操作以及卡密导出/查看等敏感读操作，不记录请求正文。 */
class AuditAdminAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldAudit($request)) {
            $route = $request->route();
            $parameters = [];
            foreach (($route?->parameters() ?? []) as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $parameters[$key] = $value;
                } elseif (is_object($value) && isset($value->id)) {
                    $parameters[$key] = $value->id;
                }
            }

            SecurityAudit::record(
                $request,
                (string) ($route?->getActionName() ?: 'admin.request'),
                metadata: ['route_parameters' => $parameters],
                statusCode: $response->getStatusCode(),
            );
        }

        return $response;
    }

    private function shouldAudit(Request $request): bool
    {
        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        return (bool) preg_match('#(?:cards/(?:export|\d+/reveal)|update/log)$#', $request->path());
    }
}
