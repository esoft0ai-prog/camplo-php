<?php

namespace App\Http\Controllers;

use App\Services\NoteService;
use App\Services\SlaService as S;
use App\Support\Input;
use App\Support\Paginate;
use Illuminate\Http\Request;

class SlaController
{
    public function live()
    {
        return S::live(ctx());
    }

    public function overdue(Request $r)
    {
        $o = S::overdue(ctx(), Input::get($r, ['campaignId' => 'nullable|uuid'])['campaignId'] ?? null);

        return ['count' => $o['count'], ...Paginate::page($o['leads'], $r)];
    }

    public function trend(Request $r)
    {
        return S::trend(ctx(), Input::get($r, ['date' => ['nullable', 'regex:/^\d{4}-\d{2}-\d{2}$/']])['date'] ?? null);
    }

    public function team(Request $r)
    {
        return Paginate::page(S::team(ctx()), $r);
    }

    public function crossTool(Request $r)
    {
        $b = S::crossToolBreaches(ctx());

        return ['connected' => $b['connected'], ...Paginate::page($b['breaches'], $r)];
    }

    public function notify(string $leadId)
    {
        return S::notifyNow(ctx(), $leadId);
    }

    public function config()
    {
        return S::config(ctx());
    }

    public function patchConfig(Request $r)
    {
        return S::patchConfig(ctx(), Input::get($r, [
            'sla_threshold_minutes' => 'nullable|numeric', 'vip_lead_enabled' => 'nullable|boolean', 'vip_sla_threshold_minutes' => 'nullable|numeric',
            'daily_summary_time' => 'nullable|string', 'vipPages' => 'sometimes|array', 'vipPages.*' => 'uuid',
            'notificationRules' => 'sometimes|array', 'notificationRules.*.id' => 'required|uuid', 'notificationRules.*.notifyEnabled' => 'required|boolean',
            'notificationRules.*.notifyChannel' => 'required|in:email,telegram,both',
        ]));
    }

    public function crossToolConfig(Request $r)
    {
        return Paginate::page(S::crossToolConfig(ctx()), $r, fn ($i) => $i['integrationId']);
    }

    public function patchCrossTool(Request $r)
    {
        $in = Input::get($r, [
            'integrationId' => 'required|uuid', 'rules' => 'present|array', 'rules.*.id' => 'required|uuid', 'rules.*.thresholdValue' => 'required|numeric',
            'rules.*.enabled' => 'required|boolean', 'rules.*.notificationChannels' => 'present|array', 'rules.*.notificationChannels.*' => 'in:ai_panel,email,telegram',
        ]);

        return S::patchCrossTool(ctx(), $in['integrationId'], $in['rules']);
    }

    // ------------------------------------------------------------- team notes
    public function teamNotes(Request $r)
    {
        return Paginate::page(NoteService::listTeamNotes(ctx(), Input::get($r, ['addressedTo' => 'nullable|in:me'])), $r);
    }

    public function createTeamNote(Request $r)
    {
        return NoteService::createTeamNote(ctx(), Input::get($r, [
            'content' => 'required|string|min:1|max:5000', 'recipientIds' => 'required|array|min:1', 'recipientIds.*' => 'uuid',
            'attachmentType' => 'nullable|in:lead,campaign', 'attachmentId' => 'nullable|uuid', 'deadline' => 'nullable|date',
        ]));
    }

    public function editTeamNote(Request $r, string $id)
    {
        return NoteService::editTeamNote(ctx(), $id, Input::get($r, ['content' => 'required|string|min:1|max:5000'])['content']);
    }

    public function readTeamNote(string $id)
    {
        return NoteService::markRead(ctx(), $id);
    }

    public function archiveTeamNote(string $id)
    {
        return NoteService::archive(ctx(), $id);
    }

    public function acknowledgeTeamNote(string $id)
    {
        return NoteService::acknowledge(ctx(), $id);
    }
}
