<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $scriptSources = ["'self'", "'unsafe-inline'"];

        if ($request->is('admin', 'admin/*')) {
            $scriptSources[] = "'unsafe-eval'";
        }

        $scriptSources[] = 'https://static.cloudflareinsights.com';

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', sprintf(
            "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src %s; connect-src 'self' wss:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
            implode(' ', $scriptSources),
        ));

        return $response;
    }
}
