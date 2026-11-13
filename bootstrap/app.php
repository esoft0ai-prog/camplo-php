<?php

use App\Http\Middleware\ApiHeaders;
use App\Http\Middleware\Authed;
use App\Support\ApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered by class name rather than by scanning app/Console/Commands, which can miss them on some setups.
    ->withCommands([\App\Console\Commands\SeedDemo::class, \App\Console\Commands\Sweep::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['authed' => Authed::class]);
        // Custom domains and page subdomains are answered before any app route.
        $middleware->prepend(\App\Http\Middleware\HostedSites::class);
        $middleware->api(prepend: [ApiHeaders::class]);
        // Render (and most hosts) terminate TLS at a proxy.
        $middleware->trustProxies(at: '*');
        // The web routes only serve the static client shell: no sessions, cookies or CSRF state.
        $middleware->group('web', []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiException::class]);
        // Unexpected errors are grouped in error_events (Super Admin → Errors) and forwarded to Sentry when configured.
        $exceptions->report(function (Throwable $e) {
            $r = request();
            $user = app()->bound(\App\Support\Ctx::class) ? app(\App\Support\Ctx::class)->user : null;
            \App\Support\Errors::capture($e, ['source' => 'server', 'route' => $r ? $r->method().' /'.ltrim($r->path(), '/') : null, 'userId' => $user->id ?? null, 'tenantId' => $user->tenant_id ?? null]);
        });
        $isApi = fn (Request $r) => $r->is('api/*') || $r->is('api') || $r->expectsJson();
        $exceptions->render(function (ApiException $e) {
            return response()->json($e->toArray(), $e->status);
        });
        $exceptions->render(function (ValidationException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return response()->json(['defined' => false, 'code' => 'BAD_REQUEST', 'status' => 400, 'message' => $e->validator->errors()->first() ?: 'Input validation failed'], 400);
            }
        });
        $exceptions->render(function (NotFoundHttpException|MethodNotAllowedHttpException $e, Request $r) use ($isApi) {
            if ($isApi($r)) {
                return response()->json(['code' => 'NOT_FOUND', 'message' => 'Not found.'], 404);
            }
        });
        $exceptions->render(function (Throwable $e, Request $r) use ($isApi) {
            if ($e instanceof HttpExceptionInterface || ! $isApi($r)) {
                return null;
            }
            // With APP_DEBUG on, put the real error in `message` so the web client shows it instead of a generic line.
            $message = config('app.debug')
                ? class_basename($e).': '.$e->getMessage().' ('.str_replace(base_path().DIRECTORY_SEPARATOR, '', $e->getFile()).':'.$e->getLine().')'
                : 'Something went wrong on our end. Try again in a moment.';

            return response()->json(['code' => 'INTERNAL_SERVER_ERROR', 'message' => $message], 500);
        });
    })->create();
