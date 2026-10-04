<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ApiRequestContext
{
    public function handle(Request $request, Closure $next)
    {
        // Generate on the server; never trust an arbitrary client log identifier.
        $requestId = (string) Str::uuid();
        Log::withContext(['request_id' => $requestId]);

        try {
            try {
                $response = $next($request);
            } catch (Throwable $error) {
                $handler = app(ExceptionHandler::class);
                $handler->report($error);
                $response = $handler->render($request, $error);
            }

            $response->headers->set('X-Request-ID', $requestId);
            if ($response->getStatusCode() >= 400) {
                // Do not log credentials, request bodies or query parameters.
                Log::log($response->getStatusCode() >= 500 ? 'error' : 'warning', 'API request failed', [
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'status' => $response->getStatusCode(),
                ]);
            }

            return $response;
        } finally {
            Log::withoutContext();
        }
    }
}
