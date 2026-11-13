<?php

namespace App\Http\Controllers;

use App\Services\PageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Serves hosted landing pages: /sites/{subdomain}/…, {sub}.{PAGES_BASE_DOMAIN} and verified custom domains. */
class SiteController
{
    /** HTML is never cached, so pausing, rolling back or redeploying takes effect on the next request. */
    public static function file(array $f, bool $isolated = false)
    {
        $headers = ['Content-Type' => $f['contentType'], 'X-Content-Type-Options' => 'nosniff'];
        if (preg_match('#text/html#', $f['contentType'])) {
            $headers += ['Cache-Control' => 'no-store, max-age=0', 'CDN-Cache-Control' => 'no-store'];
        } else {
            $headers['Cache-Control'] = 'public, max-age=300';
        }
        if ($isolated) {
            $headers['Content-Security-Policy'] = "frame-ancestors 'none'";
        }

        return response($f['data'], 200, $headers);
    }

    public static function unavailable(string $why)
    {
        [$title, $text] = [
            'missing' => ['Page not found', 'This page does not exist or has been removed.'],
            'paused' => ['This page is paused', 'The owner has paused this page. Please check back later.'],
            'offline' => ['This page is offline', 'This page is temporarily unavailable.'],
        ][$why];
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>'.$title.'</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,sans-serif;background:#f6f6f8;color:#1a1a24}div{text-align:center;padding:24px}h1{font-size:22px;margin:0 0 8px}p{margin:0;color:#5a5a70}</style></head><body><div><h1>'.$title.'</h1><p>'.$text.'</p></div></body></html>';

        return response($html, $why === 'missing' ? 404 : 503, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public function serve(Request $r, string $subdomain)
    {
        // Laravel's route matching ignores trailing slashes; the raw path keeps them (directory indexes need them).
        $raw = rawurldecode($r->getPathInfo());
        $prefix = "/sites/{$subdomain}";
        if ($raw === $prefix) {
            return new RedirectResponse($r->getBaseUrl()."{$prefix}/", 301);
        }
        $path = substr($raw, strlen($prefix) + 1);
        $site = PageService::resolveSite($subdomain);
        $f = $site ? PageService::serveFile($site, $path) : null;

        return $f ? self::file($f, true) : self::unavailable(PageService::siteUnavailable($site) ?? 'missing');
    }

    /** D-26: Caddy on-demand TLS asks before issuing a certificate — only live page hosts and verified domains. */
    public function caddyAsk(Request $r)
    {
        $host = rtrim(strtolower((string) $r->query('domain', '')), '.');
        $base = strtolower(config('camplo.pages_base_domain'));
        $ok = false;
        if (str_ends_with($host, ".{$base}")) {
            $ok = DB::table('deployments')->where('subdomain', substr($host, 0, -strlen($base) - 1))->where('status', 'ready')->exists();
        } elseif ($host !== '') {
            $ok = DB::table('domains')->where('domain_name', $host)->where('status', 'verified')->exists();
        }

        return response('', $ok ? 200 : 404);
    }
}
