<?php

use Illuminate\Support\Facades\Route;

/*
 * The single-page web client lives in public/ (index.html, app.js, styles). Any path that is not a file or an API
 * route gets the app shell, like the Node version did.
 */
Route::fallback(function () {
    return response()->file(public_path('index.html'), ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
});

// The Super Admin console and the documentation are separate static pages.
Route::get('/admin', fn () => response()->file(public_path('admin.html'), ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']));
Route::get('/docs', fn (\Illuminate\Http\Request $r) => str_ends_with($r->getPathInfo(), '/')
    ? response()->file(public_path('docs/index.html'), ['Content-Type' => 'text/html; charset=utf-8'])
    : redirect('/docs/', 301));
