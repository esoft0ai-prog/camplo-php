<?php

use App\Support\Ctx;

if (! function_exists('ctx')) {
    /** The current request's context (signed-in user, tenant, cookies to set). */
    function ctx(): Ctx
    {
        return app(Ctx::class);
    }
}
