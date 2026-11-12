<?php

namespace App\Http\Controllers;

use App\Services\LeadService as L;
use App\Services\NoteService;
use App\Support\Input;
use App\Support\Paginate;
use Illuminate\Http\Request;

class LeadController
{
    public function index(Request $r)
    {
        $in = Input::get($r, [
            'status' => 'nullable|in:all,responded,not_responded,overdue,unassigned,NOT_RESPONDED,RESPONDED', 'campaign_id' => 'nullable|uuid', 'deployment_id' => 'nullable|uuid',
            'assigneeId' => 'nullable|string', 'assignee_id' => 'nullable|string', 'q' => 'nullable|string|max:100',
        ]);

        return Paginate::envelope(L::list(ctx(), [
            'status' => isset($in['status']) ? strtolower($in['status']) : null, 'campaignId' => $in['campaign_id'] ?? null, 'deploymentId' => $in['deployment_id'] ?? null,
            'assigneeId' => $in['assigneeId'] ?? $in['assignee_id'] ?? null, 'q' => $in['q'] ?? null, 'cursor' => Paginate::decode($r->query('cursor')), 'limit' => Paginate::limit($r),
        ]), 'leads');
    }

    public function show(string $id)
    {
        return L::get(ctx(), $id);
    }

    public function respond(string $id)
    {
        return L::respond(ctx(), $id);
    }

    public function assign(Request $r, string $id)
    {
        return L::assign(ctx(), $id, Input::get($r, ['assigneeId' => 'required|uuid'])['assigneeId']);
    }

    public function lifecycle(Request $r, string $id)
    {
        return Paginate::page(L::lifecycle(ctx(), $id), $r);
    }

    public function notes(Request $r, string $id)
    {
        return Paginate::page(NoteService::leadNotes(ctx(), $id), $r);
    }

    public function addNote(Request $r, string $id)
    {
        return NoteService::addLeadNote(ctx(), $id, Input::get($r, ['content' => 'required|string|min:1|max:10000'])['content']);
    }

    public function editNote(Request $r, string $id, string $noteId)
    {
        return NoteService::editLeadNote(ctx(), $id, $noteId, Input::get($r, ['content' => 'required|string|min:1|max:10000'])['content']);
    }

    public function editAnyNote(Request $r, string $id)
    {
        return NoteService::editAnyNote(ctx(), $id, Input::get($r, ['content' => 'required|string|min:1|max:10000'])['content']);
    }

    public function audit(Request $r, string $id)
    {
        $a = L::audit(ctx(), $id);

        return ['status' => $a['status'], ...Paginate::page($a['events'], $r)];
    }

    public function teamNotes(Request $r, string $id)
    {
        L::load(ctx(), $id);

        return Paginate::page(NoteService::listTeamNotes(ctx(), ['leadId' => $id]), $r);
    }

    public function ackPreview(Request $r)
    {
        return L::ackPreview(Input::get($r, ['token' => 'required|string|min:10'])['token']);
    }

    public function acknowledge(Request $r)
    {
        return L::acknowledge(Input::get($r, ['token' => 'required|string|min:10'])['token']);
    }
}
