<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AttachBearerToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // get cookie auth_token
        $token = $request->cookie('auth_token');

        // if the token is not null, set it in the Authorization header
        if ($token && ! $request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }
        if (str_starts_with($request->bearerToken() ?? '', \App\Services\EmployeePreviewService::PREFIX)) {
            return app(EmployeePreview::class)->handle($request, $next);
        }

        return $next($request);
    }
}
