<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Insights feed, recommendations and chat history (model-backed generation arrives in stage 4). */
final class IntelService
{
    public static function serializeInsight(object $i): array
    {
        return [
            'id' => $i->id, 'type' => $i->type, 'severity' => $i->severity, 'observation' => $i->observation, 'evidence' => $i->evidence, 'campaign_id' => $i->campaign_id, 'campaign_tag' => $i->campaign_tag,
            'category' => $i->category, 'reassess_condition' => $i->reassess_condition, 'reassess_at' => Fmt::iso($i->reassess_at), 'generated_at' => Fmt::iso($i->generated_at), 'dismissed_at' => Fmt::iso($i->dismissed_at),
        ];
    }

    public static function listInsights(Ctx $ctx, ?string $campaignId = null): array
    {
        if ($campaignId) {
            CampaignService::load($ctx, $campaignId);
        }
        $q = DB::table('insights')->where('tenant_id', $ctx->tenantId)->whereNull('dismissed_at');
        if ($campaignId) {
            $q->where('campaign_id', $campaignId);
        }
        $rows = $q->orderByDesc('generated_at')->limit(100)->get()->all();
        // Win cards are a Growth feature; alerts and Priority Flags are on every plan.
        $visible = array_values(array_filter($rows, fn ($r) => $r->type !== 'win' || Rules::hasFeature($ctx->plan, 'win_signals')));

        return array_map(fn ($i) => self::serializeInsight($i), Rules::orderInsights($visible));
    }

    private static function insight(Ctx $ctx, string $id): object
    {
        $i = Str::isUuid($id) ? DB::table('insights')->where('tenant_id', $ctx->tenantId)->where('id', $id)->first() : null;
        if (! $i) {
            throw Fail::notFound('Insight not found.');
        }

        return $i;
    }

    public static function getInsight(Ctx $ctx, string $id): array
    {
        return self::serializeInsight(self::insight($ctx, $id));
    }

    public static function dismissInsight(Ctx $ctx, string $id): array
    {
        $i = self::insight($ctx, $id);
        if ($i->type === 'insufficient_evidence') {
            throw Fail::bad('Monitoring cards resolve automatically.');
        }
        DB::table('insights')->where('id', $id)->update(['dismissed_at' => now()]);

        return ['ok' => true];
    }

    private static function serializeRecs(Ctx $ctx, array $rows): array
    {
        foreach ($rows as $r) {
            $r->refs = Fmt::json($r->memory_references) ?? [];
        }
        $refIds = array_values(array_unique(array_filter(array_merge(...array_map(fn ($r) => $r->refs, $rows ?: [(object) ['refs' => []]])), fn ($x) => Str::isUuid($x))));
        $changes = $refIds ? DB::table('campaign_changes')->where('tenant_id', $ctx->tenantId)->whereIn('id', $refIds)->get() : collect();
        $prior = $refIds ? DB::table('campaign_recommendations')->where('tenant_id', $ctx->tenantId)->whereIn('id', $refIds)->get() : collect();
        $names = DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->pluck('name', 'id');

        return array_map(fn ($r) => [
            'recommendation_id' => $r->id, 'campaign_id' => $r->campaign_id, 'campaign_name' => $r->campaign_id ? ($names[$r->campaign_id] ?? null) : null, 'level' => (int) $r->level,
            'action_text' => $r->action_text, 'why' => $r->why, 'evidence' => Fmt::json($r->evidence), 'diagnosis' => $r->diagnosis, 'expected_outcome' => $r->expected_outcome, 'risk' => $r->risk,
            'confidence' => $r->confidence, 'next_step' => $r->next_step, 'why_now' => $r->why_now, 'memory_references' => $r->refs,
            'memory_timeline' => [
                ...$changes->filter(fn ($c) => in_array($c->id, $r->refs, true))->values()->map(fn ($c) => ['id' => $c->id, 'at' => Fmt::iso($c->changed_at), 'text' => "{$c->change_type} change: {$c->change_description}", 'kind' => 'experiment'])->all(),
                ...$prior->filter(fn ($p) => in_array($p->id, $r->refs, true))->values()->map(fn ($p) => ['id' => $p->id, 'at' => Fmt::iso($p->actioned_at ?? $p->surfaced_at), 'text' => "Camplo recommended \"{$p->action_text}\"", 'kind' => $p->status])->all(),
            ],
            'apply_action' => Fmt::json($r->apply_action), 'status' => $r->status, 'surfaced_at' => Fmt::iso($r->surfaced_at), 'outcome_measured' => (bool) $r->outcome_measured,
        ], $rows);
    }

    public static function listRecommendations(Ctx $ctx, ?string $campaignId = null): array
    {
        if ($campaignId) {
            CampaignService::load($ctx, $campaignId);
        }
        $q = DB::table('campaign_recommendations')->where('tenant_id', $ctx->tenantId)->where('status', 'surfaced');
        if ($campaignId) {
            $q->where('campaign_id', $campaignId);
        }
        $rows = $q->orderBy('level')->orderByDesc('surfaced_at')->get()->all();

        return self::serializeRecs($ctx, array_values(array_filter($rows, fn ($r) => Rules::recommendationLevelAllowed($ctx->plan, (int) $r->level))));
    }

    public static function getRecommendation(Ctx $ctx, string $id): array
    {
        $r = Str::isUuid($id) ? DB::table('campaign_recommendations')->where('tenant_id', $ctx->tenantId)->where('id', $id)->first() : null;
        if (! $r || ! Rules::recommendationLevelAllowed($ctx->plan, (int) $r->level)) {
            throw Fail::notFound('Recommendation not found.');
        }
        $out = self::serializeRecs($ctx, [$r])[0];

        return [...$out, 'actioned_at' => Fmt::iso($r->actioned_at), 'outcome_data' => Fmt::json($r->outcome_data)];
    }

    public static function dismissRecommendation(Ctx $ctx, string $id): array
    {
        $r = Str::isUuid($id) ? DB::selectOne("update campaign_recommendations set status = 'dismissed', actioned_at = now() where tenant_id = ? and id = ? and status = 'surfaced' returning *", [$ctx->tenantId, $id]) : null;
        if (! $r) {
            throw Fail::notFound('Recommendation not found.');
        }
        Effects::remember($ctx->tenantId, ['type' => 'recommendation', 'campaignId' => $r->campaign_id, 'entityId' => $r->id, 'content' => "Operator dismissed recommendation: {$r->action_text}"]);

        return ['ok' => true];
    }

    public static function applyRecommendation(): never
    {
        throw Fail::conflict('Applying recommendations arrives with Autopilot (v2). Camplo recommends; you execute.', 'coming_soon');
    }

    public static function chatHistory(Ctx $ctx): array
    {
        $rows = DB::table('chat_messages')->leftJoin('users', 'users.id', '=', 'chat_messages.user_id')->where('chat_messages.tenant_id', $ctx->tenantId)
            ->orderBy('chat_messages.created_at')->limit(500)->get(['chat_messages.*', 'users.name']);
        $usage = Metrics::advancedUsage($ctx->tenantId, $ctx->plan);

        return [
            'messages' => $rows->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'author' => $m->name, 'workload' => $m->workload_level, 'sources' => Fmt::json($m->sources) ?? [], 'createdAt' => Fmt::iso($m->created_at)])->all(),
            'capacity' => ['advancedUsage' => min(1, $usage), 'warning' => $usage >= 0.8 && $usage < 1, 'limitReached' => $usage >= 1],
            'depthSelector' => Rules::hasFeature($ctx->plan, 'depth_selector'),
        ];
    }

    public static function chatSuggestions(Ctx $ctx): array
    {
        $od = LeadService::overdueCount($ctx->tenant);
        $crit = DB::table('campaigns')->where('tenant_id', $ctx->tenantId)->where('status', '<>', 'complete')->value('name');

        return [
            'What needs my attention right now?',
            $od ? 'Which lead is most overdue?' : 'How is my team performing on SLA?',
            $crit ? "What should I change about {$crit} this week?" : 'What should I change about my campaigns this week?',
        ];
    }

    public static function clearChat(Ctx $ctx): array
    {
        DB::table('chat_messages')->where('tenant_id', $ctx->tenantId)->delete();

        return ['ok' => true];
    }
}
