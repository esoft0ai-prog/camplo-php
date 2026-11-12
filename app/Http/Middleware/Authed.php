<?php

namespace App\Http\Middleware;

use App\Support\Ctx;
use Closure;
use Illuminate\Http\Request;

/** Requires a signed-in user of an active workspace and fills the request's Ctx. */
class Authed
{
    public function handle(Request $request, Closure $next)
    {
        app(Ctx::class)->resolve();

        return $next($request);
    }
}
