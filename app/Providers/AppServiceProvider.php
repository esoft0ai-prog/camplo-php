<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One request context per request (also per Octane request).
        $this->app->scoped(\App\Support\Ctx::class, fn ($app) => new \App\Support\Ctx($app['request']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
