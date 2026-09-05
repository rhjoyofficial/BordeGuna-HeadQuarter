<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a request_id and a correlation_id (honouring an inbound
 * X-Correlation-Id so a chain of calls shares one id). Laravel Context attaches them to
 * every log line written during the request and carries them into queued jobs.
 */
final class AssignCorrelationIds
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $incoming = (string) $request->header('X-Correlation-Id', '');
        $correlationId = preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $incoming) ? $incoming : $requestId;

        Context::add(['request_id' => $requestId, 'correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
