<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tool SLA (Watchtower). After a lead is acknowledged, each enabled rule checks that the lead progressed in the
 * connected tool within its window, using the lead's lifecycle events from that tool. A breach is logged once per
 * lead+rule, its hours_exceeded keeps updating, and it resolves itself when the evidence arrives.
 */
final class CrossToolService
{
    /** Lifecycle evidence that satisfies each per-lead rule; null = any event from the tool counts. */
    private const EVIDENCE = [
        'not_contacted' => '/contact|call|reached|replied|reply|email(ed)? sent|meeting|qualified|proposal|won/i',
        'not_proposal' => '/proposal|quote|offer|won/i',
        'no_activity' => null,
        'not_enrolled' => '/enrol|enroll|sequence|subscribed|automation|journey|campaign added/i',
        'no_click' => '/click/i',
    ];

    private const LABEL = [
        'not_contacted' => 'was not moved to Contacted', 'not_proposal' => 'did not reach Proposal Sent', 'no_activity' => 'has no activity logged',
        'not_enrolled' => 'was not enrolled in a follow-up sequence', 'no_click' => 'has no click recorded',
    ];

    public static function check(?string $tenantId = null): array
    {
        $q = DB::table('tenants')->where('status', 'active');
        if ($tenantId) {
            $q->where('id', $tenantId);
        }
        $created = $resolved = 0;
        foreach ($q->get() as $t) {
            if (! Rules::hasFeature($t->plan, 'cross_tool_sla')) {
                continue;
            }
            $rules = DB::table('cross_tool_sla_rules as r')->join('integrations as i', 'i.id', '=', 'r.integration_id')
                ->where('r.tenant_id', $t->id)->where('r.enabled', true)->where('i.status', 'connected')->whereIn('r.rule_type', array_keys(self::EVIDENCE))
                ->get(['r.*', 'i.provider', 'i.created_at as integration_created_at']);
            foreach ($rules as $r) {
                [$c, $x] = self::checkRule($t, $r);
                $created += $c;
                $resolved += $x;
            }
        }

        return ['created' => $created, 'resolved' => $resolved];
    }

    private static function checkRule(object $t, object $r): array
    {
        $win = (int) $r->threshold_value * ($r->threshold_unit === 'days' ? Rules::DAY : Rules::HOUR);
        $now = Rules::nowMs();
        $pattern = self::EVIDENCE[$r->rule_type];
        $candidates = DB::table('leads')->where('tenant_id', $t->id)->where('status', 'responded')->where('received_at', '>', $r->integration_created_at)
            ->where('responded_at', '>', Fmt::ts(-30 * 86400))->where('responded_at', '<', Fmt::ts(-intdiv($win, 1000)))->limit(500)->get();
        if ($candidates->isEmpty()) {
            return [0, 0];
        }
        $ids = $candidates->pluck('id')->all();
        $events = DB::table('lead_lifecycle_events')->where('tenant_id', $t->id)->where('source', $r->provider)->whereIn('lead_id', $ids)->get(['lead_id', 'event', 'event_timestamp'])->groupBy('lead_id');
        $mapped = array_flip(DB::table('lead_external_mappings')->where('integration_id', $r->integration_id)->whereIn('lead_id', $ids)->pluck('lead_id')->all());
        $open = DB::table('cross_tool_sla_breaches')->where('rule_id', $r->id)->whereIn('lead_id', $ids)->get()->keyBy('lead_id');
        $created = $resolved = 0;
        foreach ($candidates as $l) {
            $deadline = Rules::ms($l->responded_at) + $win;
            $satisfied = collect($events[$l->id] ?? [])->contains(fn ($e) => (! $pattern || preg_match($pattern, $e->event)) && Rules::ms($e->event_timestamp) <= max($deadline, $now));
            $existing = $open[$l->id] ?? null;
            if ($satisfied) {
                if ($existing && ! $existing->resolved) {
                    DB::table('cross_tool_sla_breaches')->where('id', $existing->id)->update(['resolved' => true, 'resolved_at' => now()]);
                    $resolved++;
                }

                continue;
            }
            $hours = max(1, intdiv($now - $deadline, Rules::HOUR));
            if ($existing) {
                if (! $existing->resolved && (int) $existing->hours_exceeded !== $hours) {
                    DB::table('cross_tool_sla_breaches')->where('id', $existing->id)->update(['hours_exceeded' => $hours]);
                }

                continue;
            }
            DB::table('cross_tool_sla_breaches')->insert(['tenant_id' => $t->id, 'lead_id' => $l->id, 'integration_id' => $r->integration_id, 'rule_id' => $r->id, 'breach_type' => $r->rule_type, 'hours_exceeded' => $hours]);
            $created++;
            self::surface($t, $r, $l, isset($mapped[$l->id]));
        }
        if ($created || $resolved) {
            Rt::emit($t->id, 'workspace:sla', ['crossTool' => true]);
        }

        return [$created, $resolved];
    }

    private static function surface(object $t, object $r, object $l, bool $mapped): void
    {
        $tool = str_replace('_', ' ', $r->provider);
        $text = "{$l->full_name} ".self::LABEL[$r->rule_type]." in {$tool} within {$r->threshold_value} {$r->threshold_unit} of being acknowledged.";
        Effects::notify($t->id, ['userId' => $l->assignee_id, 'kind' => 'sla_breach', 'description' => "Cross-tool SLA: {$text}", 'link' => "/leads/{$l->id}"]);
        $channels = Fmt::json($r->notification_channels) ?? [];
        if (in_array('ai_panel', $channels, true)) {
            DB::table('insights')->insert(['tenant_id' => $t->id, 'campaign_id' => $l->campaign_id, 'type' => 'alert', 'severity' => 'amber', 'category' => 'SLA', 'dedupe_key' => "xtool:{$r->id}:{$l->id}",
                'observation' => $text, 'evidence' => $mapped ? "Camplo has {$tool}'s contact for this lead but no matching activity arrived." : "No record of this lead has arrived from {$tool} — it may never have reached the tool."]);
            Rt::emit($t->id, 'workspace:insights');
        }
        if (in_array('email', $channels, true)) {
            $to = $l->assignee_id ? DB::table('users')->where('id', $l->assignee_id)->whereNull('removed_at')->value('email') : null;
            Mailer::send(['to' => $to ?? $t->notification_email, 'subject' => "Cross-tool SLA breach — {$l->full_name}", 'text' => "{$text}\n\nOpen lead: ".config('camplo.app_url')."/#/leads/{$l->id}"]);
        }
    }
}
