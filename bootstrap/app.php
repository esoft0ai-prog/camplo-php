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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['authed' => Authed::class]);
        $middleware->api(prepend: [ApiHeaders::class]);
        // Render (and most hosts) terminate TLS at a proxy.
        $middleware->trustProxies(at: '*');
        // The web routes only serve the static client shell: no sessions, cookies or CSRF state.
        $middleware->group('web', []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiException::class]);
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
            if ($e instanceof HttpExceptionInterface || ! $isApi($r) || config('app.debug')) {
                return null;
            }

            return response()->json(['code' => 'INTERNAL_SERVER_ERROR', 'message' => 'Something went wrong on our end. Try again in a moment.'], 500);
        });
    })->create();
