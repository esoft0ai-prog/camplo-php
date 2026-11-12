<?php

namespace App\Services;

/**
 * Model calls. Stage 4 brings the full router (workspace keys, OpenRouter, fallbacks, budgets); until then no model
 * is called and callers use their written-from-facts fallback.
 */
final class Ai
{
    /** One completion's text, or null when no model is available. */
    public static function text(string $tenantId, string $plan, array $request): ?string
    {
        return null;
    }
}
