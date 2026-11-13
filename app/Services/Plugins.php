<?php

namespace App\Services;

use App\Extensions\Catalog;
use App\Support\Errors;
use App\Support\Fmt;
use App\Support\Sql;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Plugins — built-in modules a workspace switches on in Settings → Extensions. They run inside Camplo on its events:
 *   lead.received  phone tidy-up → duplicate guard → round-robin assignment (in that order)
 *   weekly         weekly digest email (Monday mornings, from the sweep)
 * A failing plugin never blocks lead intake: errors are recorded on the plugin and in error monitoring.
 */
final class Plugins
{
    private const ORDER = ['plugin_phone_normalizer', 'plugin_duplicate_guard', 'plugin_round_robin'];

    private static function active(object $tenant): array
    {
        $rows = DB::table('workspace_extensions')->where('tenant_id', $tenant->id)->where('kind', 'plugin')->where('enabled', true)->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $cat = collect(Catalog::all())->keyBy('key');

        return $rows->filter(fn ($r) => isset($cat[$r->key]) && Catalog::planAllows($tenant->plan, $cat[$r->key]))->keyBy('key')->all();
    }

    private static function used(object $ext, ?string $error = null): void
    {
        DB::table('workspace_extensions')->where('id', $ext->id)->update($error
            ? ['status' => 'error', 'last_error' => mb_substr($error, 0, 500)]
            : ['usage_count' => DB::raw('usage_count + 1'), 'last_used_at' => now(), 'status' => 'ok', 'last_error' => null]);
    }

    private static function settings(object $ext): array
    {
        return (array) (Fmt::json($ext->settings) ?? []);
    }

    /** +234 803 123 4567 · 0803… → +234803… · 803… → +234803… */
    public static function normalizePhone(string $raw, string $defaultCode): ?string
    {
        $cc = '+'.preg_replace('/\D/', '', $defaultCode);
        $d = preg_replace('/[^\d+]/', '', $raw);
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '00')) {
            $d = '+'.substr($d, 2);
        }
        if (str_starts_with($d, '+')) {
            return strlen($d) >= 8 ? $d : null;
        }
        if (str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($cc) > 1 && str_starts_with($d, substr($cc, 1)) && strlen($d) > 10) {
            return '+'.$d;
        }

        return strlen($d) >= 6 ? $cc.$d : null;
    }

    private static function phoneNormalizer(object $t, object $lead, object $ext): ?array
    {
        if (! $lead->phone) {
            return null;
        }
        $next = self::normalizePhone($lead->phone, (string) (self::settings($ext)['defaultCountryCode'] ?? '+234'));
        if (! $next || $next === $lead->phone) {
            return null;
        }
        DB::table('leads')->where('id', $lead->id)->update(['phone' => $next]);

        return ['phone' => $next];
    }

    private static function duplicateGuard(object $t, object $lead, object $ext): ?array
    {
        $days = max(1, min(365, (int) (self::settings($ext)['windowDays'] ?? 30)));
        if (! $lead->email && ! $lead->phone) {
            return null;
        }
        $dup = DB::table('leads')->where('tenant_id', $t->id)->where('id', '<>', $lead->id)->where('received_at', '>=', Fmt::ts(-$days * 86400))
            ->where(function ($q) use ($lead) {
                if ($lead->email) {
                    $q->orWhereRaw('lower(email) = ?', [mb_strtolower($lead->email)]);
                }
                if ($lead->phone) {
                    $q->orWhere('phone', $lead->phone);
                }
            })->orderByDesc('received_at')->first(['id', 'full_name', 'received_at']);
        if ($dup) {
            LeadService::addLifecycle($t->id, $lead->id, "Possible duplicate of {$dup->full_name} (received ".substr(Fmt::iso($dup->received_at), 0, 10).')', 'plugin:duplicate guard');
        }

        return null;
    }

    private static function roundRobin(object $t, object $lead, object $ext): ?array
    {
        if ($lead->assignee_id) {
            return null;
        }
        $s = self::settings($ext);
        $ids = array_values(array_filter((array) ($s['memberIds'] ?? []), 'is_string'));
        $members = DB::table('users')->where('tenant_id', $t->id)->whereNull('removed_at')->pluck('name', 'id');
        $pool = array_values(array_filter($ids, fn ($id) => isset($members[$id])));
        if (! $pool) {
            return null;
        }
        $cursor = ((int) ($s['cursor'] ?? 0)) % count($pool);
        $to = $pool[$cursor];
        $owner = DB::table('users')->where('tenant_id', $t->id)->where('role', 'owner')->value('id');
        $now = now();
        $n = DB::table('leads')->where('id', $lead->id)->whereNull('assignee_id')->update(['assignee_id' => $to, 'assignment_path' => 'B', 'assigned_at' => $now]);
        if (! $n) {
            return null;
        }
        DB::table('lead_assignment_history')->insert(['tenant_id' => $t->id, 'lead_id' => $lead->id, 'from_assignee_id' => null, 'to_assignee_id' => $to, 'path' => 'B', 'actioned_by' => $owner ?? $to, 'actioned_at' => $now]);
        LeadService::addLifecycle($t->id, $lead->id, "Assigned to {$members[$to]} (round-robin)", 'plugin:round-robin', $now);
        DB::table('workspace_extensions')->where('id', $ext->id)->update(['settings' => json_encode([...$s, 'cursor' => ($cursor + 1) % count($pool)])]);
        Effects::notify($t->id, ['userId' => $to, 'kind' => 'assignment', 'description' => "{$lead->full_name} was assigned to you (round-robin)", 'link' => "/leads/{$lead->id}"]);

        return ['assignee_id' => $to, 'assignment_path' => 'B', 'assigned_at' => $now];
    }

    /** Run lead.received plugins. Returns the lead as the plugins left it. */
    public static function runLeadPlugins(object $tenant, object $lead): object
    {
        try {
            $active = self::active($tenant);
        } catch (Throwable) {
            return $lead;
        }
        $fns = ['plugin_phone_normalizer' => 'phoneNormalizer', 'plugin_duplicate_guard' => 'duplicateGuard', 'plugin_round_robin' => 'roundRobin'];
        foreach (self::ORDER as $key) {
            if (! $ext = $active[$key] ?? null) {
                continue;
            }
            try {
                if ($patch = self::{$fns[$key]}($tenant, $lead, $ext)) {
                    $lead = (object) [...(array) $lead, ...$patch];
                }
                self::used($ext);
            } catch (Throwable $e) {
                self::used($ext, $e->getMessage());
                Errors::capture($e, ['source' => 'job', 'route' => "plugin:{$key}", 'tenantId' => $tenant->id]);
            }
        }

        return $lead;
    }

    /** Monday 07:00 UTC onward, once per ISO week per workspace. */
    public static function runWeeklyDigests(?\DateTimeInterface $now = null): int
    {
        $now = $now ? CarbonImmutable::instance($now)->utc() : now()->utc();
        if ((int) $now->format('N') !== 1 || (int) $now->format('G') < 7) {
            return 0;
        }
        $week = $now->format('o-W');
        $rows = DB::table('workspace_extensions as e')->join('tenants as t', 't.id', '=', 'e.tenant_id')->where('e.key', 'plugin_weekly_digest')->where('e.enabled', true)->where('t.status', 'active')
            ->get(['e.*', 't.id as t_id']);
        $sent = 0;
        foreach ($rows as $e) {
            $s = self::settings($e);
            if (($s['lastWeek'] ?? null) === $week) {
                continue;
            }
            try {
                $sent += self::sendDigest(DB::table('tenants')->where('id', $e->t_id)->first(), (string) ($s['recipients'] ?? 'owner'));
                DB::table('workspace_extensions')->where('id', $e->id)->update(['settings' => json_encode([...$s, 'lastWeek' => $week]), 'usage_count' => $e->usage_count + 1, 'last_used_at' => now(), 'status' => 'ok', 'last_error' => null]);
            } catch (Throwable $err) {
                self::used($e, $err->getMessage());
            }
        }

        return $sent;
    }

    public static function sendDigest(object $t, string $recipients): int
    {
        $facts = BriefingService::facts(AiTools::snapshot($t->id));
        $s = DB::table('leads')->where('tenant_id', $t->id)->where('received_at', '>=', Fmt::ts(-7 * 86400))
            ->selectRaw(Sql::q("count(*) as n, count(*) filter (where status = 'responded') as r"))->first();
        $lines = ["Last 7 days: {$s->n} leads, {$s->r} responded.", "Right now: {$facts['overdue']} overdue across {$facts['campaigns']} campaigns.",
            ...array_map(fn ($u) => "• {$u}", $facts['urgency']), ...array_map(fn ($a) => "• {$a}", $facts['anomalies'])];
        $q = DB::table('users')->where('tenant_id', $t->id)->whereNull('removed_at');
        if ($recipients !== 'team') {
            $q->where('role', 'owner');
        }
        $to = $q->pluck('email');
        foreach ($to as $email) {
            Mailer::send(['to' => $email, 'subject' => "Your Camplo week — {$t->business_name}", 'text' => implode("\n", $lines)."\n\nOpen Camplo to act on anything above."]);
        }

        return $to->count();
    }
}
