<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic security headers on every response, and a redirect to HTTPS in
 * production.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->secure()) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        if (app()->isProduction() && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
