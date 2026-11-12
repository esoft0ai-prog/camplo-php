<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SiteController;
use App\Services\PageService;
use Closure;
use Illuminate\Http\Request;

/** Requests for another host than the app's own are hosted pages ({sub}.{PAGES_BASE_DOMAIN} or a custom domain). */
class HostedSites
{
    public function handle(Request $request, Closure $next)
    {
        $host = strtolower(explode(':', (string) ($request->header('X-Forwarded-Host') ?: $request->getHost()))[0]);
        $appHost = strtolower((string) parse_url(config('camplo.app_url'), PHP_URL_HOST));
        if ($host === '' || $host === $appHost || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.onrender.com')) {
            return $next($request);
        }
        $site = PageService::resolveSite(null, $host);
        if (! $site) {
            return $next($request);
        }
        $f = PageService::serveFile($site, $request->getPathInfo());

        return $f ? SiteController::file($f) : SiteController::unavailable(PageService::siteUnavailable($site) ?? 'missing');
    }
}
