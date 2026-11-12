<?php

namespace App\Http\Controllers;

use App\Services\PageService as P;
use App\Support\Input;
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

    public function upload(Request $r)
    {
        $in = Input::get($r, ['name' => 'nullable|string|max:255', 'campaignId' => 'nullable|uuid', 'slug' => 'nullable|string|max:60', 'entryFile' => 'nullable|string']);

        return P::upload(ctx(), $r->file('file'), $in);
    }

    public function uploadStatus(string $id)
    {
        return P::uploadStatus(ctx(), $id);
    }

    public function update(Request $r, string $id)
    {
        return P::patch(ctx(), $id, Input::get($r, ['name' => 'sometimes|string|min:1|max:255', 'campaignId' => 'nullable|uuid', 'slug' => 'sometimes|string|max:60', 'vip' => 'sometimes|boolean']));
    }

    public function redeploy(Request $r, string $id)
    {
        return P::redeploy(ctx(), $id, $r->file('file'));
    }

    public function pause(string $id)
    {
        return P::setServingState(ctx(), $id, 'paused');
    }

    public function unpause(string $id)
    {
        return P::setServingState(ctx(), $id, 'active');
    }

    public function archive(string $id)
    {
        return P::setServingState(ctx(), $id, 'archived');
    }

    public function rollback(string $id)
    {
        return P::rollback(ctx(), $id);
    }

    public function destroy(string $id)
    {
        return P::delete(ctx(), $id);
    }

    public function addPageDomain(Request $r, string $id)
    {
        return P::addDomain(ctx(), $id, Input::get($r, ['domainName' => 'required|string|min:3'])['domainName']);
    }

    public function domains(Request $r)
    {
        return Paginate::page(P::listDomains(ctx()), $r);
    }

    public function addDomain(Request $r)
    {
        $in = Input::get($r, ['deployment_id' => 'required|uuid', 'domain_name' => 'required|string|min:3']);

        return P::addDomain(ctx(), $in['deployment_id'], $in['domain_name']);
    }

    public function verifyDomain(string $id)
    {
        return P::verifyDomain(ctx(), $id);
    }

    public function removeDomain(string $id)
    {
        return P::removeDomain(ctx(), $id);
    }
}
