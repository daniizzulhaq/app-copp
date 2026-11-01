<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $r = $next($request);
        $r->headers->set('X-Content-Type-Options', 'nosniff');
        $r->headers->set('X-Frame-Options', 'DENY');
        $r->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $r->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->user()) {
            $r->headers->set('Cache-Control', 'private, no-store');
        }

        return $r;
    }
}
