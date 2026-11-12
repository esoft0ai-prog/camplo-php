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
}
