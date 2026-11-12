<?php

namespace App\Services;

/** Telegram bot (stage 3 connects it). Until then nothing is sent and email is the alert channel. */
final class Telegram
{
    public static function sendLeadMessage(string $tenantId, object $user, string $leadId, string $text): bool
    {
        return false;
    }

    public static function notifyNewLead(string $tenantId, string $leadId): void {}
}
