<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = \Illuminate\Support\Str::random(40);
        \Illuminate\Support\Facades\Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Removed strict Content-Security-Policy to allow Vite (IPv6 [::1]) and local MJPEG streams
        // $response->headers->set('Content-Security-Policy', ...);

        return $response;
    }
}
