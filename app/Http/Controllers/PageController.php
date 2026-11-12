<?php

namespace App\Http\Controllers;

use App\Services\PageService as P;
use App\Support\Paginate;
use Illuminate\Http\Request;

class PageController
{
    /** The pages envelope carries the workspace average conversion rate for benchmarking. */
    public static function envelope(array $r, Request $req): array
    {
        return ['workspace_avg_conversion_rate' => $r['avgConversionRate'], ...Paginate::page($r['pages'], $req)];
    }

    public function index(Request $r)
    {
        return self::envelope(P::list(ctx()), $r);
    }

    public function show(string $id)
    {
        return P::get(ctx(), $id);
    }

    public function analytics(string $id)
    {
        return P::analyticsFor(ctx(), $id);
    }

    public function webhookHealth(string $id)
    {
        return P::webhookHealth(ctx(), $id);
    }

    public function webhookTest(string $id)
    {
        return P::testWebhook(ctx(), $id);
    }
}
