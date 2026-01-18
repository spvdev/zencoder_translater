<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $configuredApiKey = config('app.api_key');

        // If no API key configured, allow all requests (development mode)
        if (empty($configuredApiKey)) {
            return $next($request);
        }

        // Check for API key in various locations
        $apiKey = $request->header('Zencoder-Api-Key')
            ?? $this->extractBearerToken($request->header('Authorization'))
            ?? $request->input('api_key');

        if ($apiKey !== $configuredApiKey) {
            return response()->json([
                'errors' => ['Invalid API key'],
            ], 401);
        }

        return $next($request);
    }

    /**
     * Extract token from Bearer authorization header.
     */
    private function extractBearerToken(?string $header): ?string
    {
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        return $header;
    }
}
