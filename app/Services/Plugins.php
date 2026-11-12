<?php

namespace App\Services;

/** Workspace plugins (stage 4). Lead plugins may tidy, de-duplicate or route a new lead before anyone is notified. */
final class Plugins
{
    public static function runLeadPlugins(object $tenant, object $lead): object
    {
        return $lead;
    }
}
