<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestResponseLogger
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = uniqid('req_');

        Log::debug("=== INCOMING REQUEST [{$requestId}] ===", [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'body' => $request->all(),
            'raw_body' => $request->getContent(),
            'ip' => $request->ip(),
        ]);

        $response = $next($request);

        $responseBody = $response->getContent();
        $decoded = json_decode($responseBody, true);

        Log::debug("=== OUTGOING RESPONSE [{$requestId}] ===", [
            'status' => $response->getStatusCode(),
            'headers' => $response->headers->all(),
            'body' => $decoded ?? $responseBody,
        ]);

        return $response;
    }

    private function sanitizeHeaders(array $headers): array
    {
        // Mask the API key value but show the header is present
        foreach (['zencoder-api-key', 'authorization'] as $sensitive) {
            if (isset($headers[$sensitive])) {
                $headers[$sensitive] = ['***REDACTED***'];
            }
        }
        return $headers;
    }
}
