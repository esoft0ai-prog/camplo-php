<?php

namespace App\Http\Controllers;

use App\Services\CampaignService as C;
use App\Services\IntelService;
use App\Services\LeadService;
use App\Services\NoteService;
use App\Services\PageService;
use App\Services\SlaService;
use App\Support\Input;
use App\Support\Paginate;
use Illuminate\Http\Request;

class CampaignController
{
    private const MONEY = 'nullable|numeric|min:0';

    public function index(Request $r)
    {
        return Paginate::page(C::list(ctx()), $r);
    }

    public function store(Request $r)
    {
        return C::create(ctx(), Input::get($r, [
            'name' => 'required|string|min:1|max:100', 'description' => 'nullable|string', 'startDate' => ['sometimes', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
            'budget' => self::MONEY, 'cplThreshold' => self::MONEY, 'dailySpend' => self::MONEY,
        ]));
    }

    public function show(string $id)
    {
        return C::get(ctx(), $id);
    }

    public function update(Request $r, string $id)
    {
        return C::update(ctx(), $id, Input::get($r, [
            'name' => 'sometimes|string|min:1|max:100', 'description' => 'nullable|string', 'dailySpend' => self::MONEY, 'budget' => self::MONEY,
            'cplThreshold' => self::MONEY, 'startDate' => 'sometimes|string', 'status' => 'sometimes|in:paused,active',
            'change' => 'sometimes|array', 'change.type' => 'required_with:change|in:budget,audience,creative,messaging,page', 'change.description' => 'required_with:change|string|min:3|max:500',
        ]));
    }

    public function destroy(string $id)
    {
        return C::delete(ctx(), $id);
    }

    public function complete(string $id)
    {
        return C::complete(ctx(), $id);
    }

    public function pin(string $id)
    {
        return C::setPinned(ctx(), $id, true);
    }

    public function unpin(string $id)
    {
        return C::setPinned(ctx(), $id, false);
    }

    public function health(string $id)
    {
        return C::health(ctx(), $id);
    }

    public function speed(string $id)
    {
        return C::speedToLead(ctx(), $id);
    }

    public function cpl(string $id)
    {
        return C::cplInfo(ctx(), $id);
    }

    public function logs(Request $r, string $id)
    {
        $in = Input::get($r, ['from' => 'nullable|string', 'to' => 'nullable|string']);

        return Paginate::envelope(C::logs(ctx(), $id, $in['from'] ?? null, $in['to'] ?? null, Paginate::decode($r->query('cursor')), Paginate::limit($r)), 'logs');
    }

    public function memory(Request $r, string $id)
    {
        return Paginate::page(C::memoryTimeline(ctx(), $id), $r);
    }

    public function retroStatus(string $id)
    {
        return C::retrospectiveStatus(ctx(), $id);
    }

    public function retro(string $id)
    {
        return C::retrospective(ctx(), $id);
    }

    public function retroPdf(string $id)
    {
        $f = C::retrospectivePdf(ctx(), $id);

        return response($f['data'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"']);
    }

    public function shareGet(string $id)
    {
        return C::getShareLink(ctx(), $id);
    }

    public function shareCreate(string $id)
    {
        return C::createShareLink(ctx(), $id);
    }

    public function shareRevoke(string $id)
    {
        return C::revokeShareLink(ctx(), $id);
    }

    public function pages(Request $r, string $id)
    {
        return PageController::envelope(PageService::list(ctx(), $id), $r);
    }

    public function leads(Request $r, string $id)
    {
        $in = Input::get($r, ['status' => 'nullable|in:all,responded,not_responded,overdue,unassigned', 'assigneeId' => 'nullable|string', 'pageId' => 'nullable|uuid']);

        return Paginate::envelope(LeadService::list(ctx(), [
            'status' => $in['status'] ?? null, 'assigneeId' => $in['assigneeId'] ?? null, 'campaignId' => $id, 'deploymentId' => $in['pageId'] ?? null,
            'cursor' => Paginate::decode($r->query('cursor')), 'limit' => Paginate::limit($r),
        ]), 'leads');
    }

    public function insights(Request $r, string $id)
    {
        return Paginate::page(IntelService::listInsights(ctx(), $id), $r);
    }

    public function recommendations(Request $r, string $id)
    {
        return Paginate::page(IntelService::listRecommendations(ctx(), $id), $r);
    }

    public function notes(Request $r, string $id)
    {
        return Paginate::page(NoteService::campaignNotes(ctx(), $id), $r);
    }

    public function addNote(Request $r, string $id)
    {
        return NoteService::addCampaignNote(ctx(), $id, Input::get($r, ['content' => 'required|string|min:1|max:10000'])['content']);
    }

    public function editNote(Request $r, string $id, string $noteId)
    {
        return NoteService::editCampaignNote(ctx(), $id, $noteId, Input::get($r, ['content' => 'required|string|min:1|max:10000'])['content']);
    }

    public function meetingNotes(Request $r, string $id)
    {
        return Paginate::page(NoteService::meetingNotes(ctx(), $id), $r);
    }

    public function addMeetingNote(Request $r, string $id)
    {
        return NoteService::addMeetingNote(ctx(), $id, Input::get($r, ['title' => 'nullable|string|max:255', 'meetingDate' => 'nullable|date', 'content' => 'required|string|min:1']));
    }

    public function teamNotes(Request $r, string $id)
    {
        return Paginate::page(NoteService::listTeamNotes(ctx(), ['campaignId' => $id]), $r);
    }

    public function lifecycleSummary(string $id)
    {
        return LeadService::lifecycleSummary(ctx(), $id);
    }

    public function sla(string $id)
    {
        C::load(ctx(), $id);

        return SlaService::campaignSla(ctx(), $id);
    }

    public function slaTrend(Request $r, string $id)
    {
        C::load(ctx(), $id);

        return SlaService::trend(ctx(), Input::get($r, ['date' => 'nullable|string'])['date'] ?? null, $id);
    }

    public function slaTeam(Request $r, string $id)
    {
        C::load(ctx(), $id);

        return Paginate::page(SlaService::team(ctx(), $id), $r);
    }

    public function publicCampaign(string $shareToken)
    {
        return C::publicCampaign($shareToken);
    }
}
