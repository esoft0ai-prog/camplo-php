<?php

namespace App\Http\Middleware;

use App\Support\Ctx;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Adds the Set-Cookie values a service queued on Ctx, and the security headers the Node app sent. */
class ApiHeaders
{
    public function handle(Request $request, Closure $next)
    {
        // A fresh context for every request (the container outlives requests in tests and under Octane).
        app()->instance(Ctx::class, new Ctx($request));
        $response = $next($request);
        self::apply($response);

        return $response;
    }

    public static function apply(Response $response): void
    {
        $ctx = app(Ctx::class);
        foreach ($ctx->setCookies as $c) {
            $response->headers->setCookie($c);
        }
        $ctx->setCookies = [];
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
