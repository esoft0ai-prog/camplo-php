<?php

namespace App\Domain;

/** Plans, features and limits — the same values as the Node `domain/rules.ts`. */
final class Rules
{
    public const PLANS = ['starter', 'growth', 'watchtower', 'agency'];

    private const RANK = ['starter' => 0, 'growth' => 1, 'watchtower' => 2, 'agency' => 3];

    public const FEATURE_PLAN = [
        'ai_chat' => 'growth', 'utm' => 'growth', 'health_pulse' => 'growth', 'early_warning' => 'growth', 'benchmarking' => 'growth',
        'budget' => 'growth', 'team_notes' => 'growth', 'my_performance' => 'growth', 'client_view' => 'growth', 'morning_brief' => 'growth',
        'depth_selector' => 'growth', 'campaign_logs' => 'growth', 'win_signals' => 'growth',
        'cross_tool_sla' => 'watchtower', 'retrospective' => 'watchtower', 'team_patterns' => 'watchtower', 'level3' => 'watchtower',
        'level4' => 'watchtower', 'unlimited_tools' => 'watchtower',
    ];

    /** -1 = unlimited. */
    public const PLAN_LIMITS = [
        'starter' => ['campaigns' => 3, 'deployments' => 5, 'members' => 3, 'connectedTools' => 0],
        'growth' => ['campaigns' => 10, 'deployments' => -1, 'members' => -1, 'connectedTools' => 1],
        'watchtower' => ['campaigns' => -1, 'deployments' => -1, 'members' => -1, 'connectedTools' => -1],
        'agency' => ['campaigns' => -1, 'deployments' => -1, 'members' => -1, 'connectedTools' => -1],
    ];

    public const PLAN_PRICE = ['starter' => 97, 'growth' => 197, 'watchtower' => 347, 'agency' => 597];

    public const DEEP_BUDGET_USD = ['starter' => 0.5, 'growth' => 6, 'watchtower' => 20, 'agency' => 40];

    public static function planAtLeast(string $plan, string $min): bool
    {
        return (self::RANK[$plan] ?? 0) >= (self::RANK[$min] ?? 0);
    }

    public static function hasFeature(string $plan, string $feature): bool
    {
        return self::planAtLeast($plan, self::FEATURE_PLAN[$feature]);
    }

    public static function recommendationLevelAllowed(string $plan, int $level): bool
    {
        if ($level <= 1) {
            return true;
        }

        return self::planAtLeast($plan, $level === 2 ? 'growth' : 'watchtower');
    }

    public static function cpl(?float $dailySpend, int $leadCount): ?float
    {
        if ($dailySpend === null || $leadCount <= 0) {
            return null;
        }

        return round($dailySpend / $leadCount, 2);
    }

    // ------------------------------------------------------------- time
    public const MIN = 60_000;

    public const HOUR = 3_600_000;

    public const DAY = 86_400_000;

    /** Milliseconds since the epoch for a Postgres timestamp (or null). */
    public static function ms(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $d = $v instanceof \DateTimeInterface ? $v : new \DateTimeImmutable((string) $v);

        return (int) $d->format('Uv');
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    // ------------------------------------------------------------- SLA timers
    /** Timer 1 — Customer Waiting. Anchor received_at, never resets; freezes at responded_at. */
    public static function customerWaitingMs(object $l, ?int $now = null): int
    {
        return (self::ms($l->responded_at) ?? $now ?? self::nowMs()) - self::ms($l->received_at);
    }

    public static function responseAnchor(object $l): ?int
    {
        return match ($l->assignment_path) {
            'A' => self::ms($l->claimed_at), 'B' => self::ms($l->assigned_at), 'C' => self::ms($l->reassigned_at), default => null,
        };
    }

    /** Timer 2 — Response Time. Null when nobody owns the lead yet. */
    public static function responseTimeMs(object $l, ?int $now = null): ?int
    {
        $a = self::responseAnchor($l);

        return $a === null ? null : (self::ms($l->responded_at) ?? $now ?? self::nowMs()) - $a;
    }

    public static function thresholdMinutesFor(object $l, object $t): int
    {
        return ($l->vip ?? false) && $t->vip_lead_enabled ? (int) $t->vip_sla_threshold_minutes : (int) $t->sla_threshold_minutes;
    }

    public static function isOverdue(object $l, int $thresholdMinutes, ?int $now = null): bool
    {
        return $l->status === 'not_responded' && ($now ?? self::nowMs()) - self::ms($l->received_at) > $thresholdMinutes * self::MIN;
    }

    public static function speedColor(?int $ms): string
    {
        if ($ms === null) {
            return 'none';
        }

        return $ms < 5 * self::MIN ? 'green' : ($ms < 30 * self::MIN ? 'amber' : 'red');
    }

    /** "Xm Xs" below one hour, "Xh Xm" otherwise. */
    public static function formatDuration(int|float|null $ms): string
    {
        if ($ms === null) {
            return '—';
        }
        $s = max(0, (int) floor($ms / 1000));
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        return $h ? "{$h}h {$m}m" : "{$m}m ".($s % 60).'s';
    }

    /** Respond visibility: unassigned, assigned to me, or owner. */
    public static function canRespond(object $l, string $userId, string $role): bool
    {
        if ($l->status === 'responded') {
            return false;
        }

        return $l->assignee_id === null || $l->assignee_id === $userId || $role === 'owner';
    }

    // ------------------------------------------------------------- health
    public static function leadVolumeSignal(float $leads7d, float $avgWeekly): string
    {
        if ($avgWeekly <= 0) {
            return 'green';
        }
        if ($leads7d == 0) {
            return 'red';
        }

        return $leads7d >= $avgWeekly * 0.8 ? 'green' : ($leads7d >= $avgWeekly * 0.5 ? 'amber' : 'red');
    }

    public static function ackRateSignal(int $responded, int $total): string
    {
        if ($total === 0) {
            return 'green';
        }
        $r = $responded / $total;

        return $r >= 0.9 ? 'green' : ($r >= 0.7 ? 'amber' : 'red');
    }

    public static function webhookSignal(array $statuses): string
    {
        return in_array('offline', $statuses, true) ? 'red' : (in_array('stale', $statuses, true) ? 'amber' : 'green');
    }

    public static function healthPulse(array $signals): string
    {
        $notGreen = count(array_filter($signals, fn ($s) => $s !== 'green'));
        if ($notGreen === 0) {
            return 'healthy';
        }

        return $notGreen >= 2 || in_array('red', $signals, true) ? 'critical' : 'watch';
    }

    /** Offline at ≥120 min silence; stale from min(threshold, 60) minutes. */
    public static function webhookState(mixed $lastReceivedAt, int $staleThresholdMinutes, ?int $now = null): string
    {
        $last = self::ms($lastReceivedAt);
        if ($last === null) {
            return 'never_connected';
        }
        $silence = (($now ?? self::nowMs()) - $last) / self::MIN;
        if ($silence >= 120) {
            return 'offline';
        }

        return $silence >= min($staleThresholdMinutes, 60) ? 'stale' : 'operational';
    }

    public static function noteEditable(mixed $createdAt, string $authorId, string $userId, int $windowMs): bool
    {
        return $authorId === $userId && self::nowMs() - self::ms($createdAt) < $windowMs;
    }

    public static function hasRollbackAvailable(?string $prevPath, mixed $prevDeployedAt, int $retentionDays = 30): bool
    {
        return (bool) $prevPath && $prevDeployedAt !== null && self::nowMs() - self::ms($prevDeployedAt) < $retentionDays * self::DAY;
    }

    public static function earlyWarningActive(mixed $deployedAt, int $windowHours = 72): bool
    {
        return $deployedAt !== null && self::nowMs() - self::ms($deployedAt) < $windowHours * self::HOUR;
    }

    private const SEV = ['red' => 1, 'amber' => 2, 'blue' => 3, 'green' => 4];

    /** Priority Flag first, insufficient-evidence last, then red → amber → blue → green, newest first. */
    public static function orderInsights(array $list): array
    {
        usort($list, fn ($a, $b) => (($b->type === 'priority_flag') <=> ($a->type === 'priority_flag'))
            ?: (($a->type === 'insufficient_evidence') <=> ($b->type === 'insufficient_evidence'))
            ?: ((self::SEV[$a->severity] ?? 9) <=> (self::SEV[$b->severity] ?? 9))
            ?: (self::ms($b->generated_at) <=> self::ms($a->generated_at)));

        return $list;
    }

    public static function hasSufficientEvidence(int $leadCount, int $daysOfData): bool
    {
        return $leadCount >= 100 || $daysOfData >= 14;
    }
}
