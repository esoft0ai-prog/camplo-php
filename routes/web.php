<?php

use App\Http\Controllers\SiteController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The single-page web client lives in public/ (index.html, app.js, styles). Any path that is not a file or an API
 * route gets the app shell, like the Node version did.
 */
// Hosted landing pages on the app host.
Route::get('/sites/{subdomain}/{path?}', [SiteController::class, 'serve'])->where(['subdomain' => '[a-z0-9-]+', 'path' => '.*']);

// The HTML shells use relative asset paths; <base> points them at the app's root, which may be a sub-folder (/camplo).
$shell = fn (Request $r, string $file) => response(
    preg_replace('/<head>/', '<head>'."\n  ".'<base href="'.e($r->getBaseUrl()).'/" />', file_get_contents(public_path($file)), 1),
    200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache'],
);

Route::fallback(fn (Request $r) => $shell($r, 'index.html'));

// The Super Admin console and the documentation are separate static pages.
Route::get('/admin', fn (Request $r) => $shell($r, 'admin.html'));
Route::get('/docs', fn (Request $r) => str_ends_with($r->getPathInfo(), '/')
    ? response()->file(public_path('docs/index.html'), ['Content-Type' => 'text/html; charset=utf-8'])
    : new RedirectResponse($r->getBaseUrl().'/docs/', 301));
