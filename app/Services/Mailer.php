<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Throwable;

/** Transactional email: SMTP when an SMTP host is set, else the Resend HTTP API, else the log. */
final class Mailer
{
    /** Last messages sent — inspected by tests and useful in local dev. @var array<int,array{to:string,subject:string,text:string}> */
    public static array $outbox = [];

    public static function send(array $m): void
    {
        $p = Platform::get();
        $brand = $p['branding']['productName'];
        if ($brand && $brand !== 'Camplo') {
            foreach (['subject', 'text'] as $k) {
                $m[$k] = preg_replace('/\bCamplo\b/', $brand, $m[$k]);
            }
        }
        self::$outbox[] = $m;
        if (count(self::$outbox) > 200) {
            array_shift(self::$outbox);
        }
        $e = $p['email'];
        $from = str_contains($e['fromAddress'], '<') ? $e['fromAddress'] : (($p['branding']['emailFromName'] ?: $brand).' <'.$e['fromAddress'].'>');
        try {
            $via = self::deliver($e, $from, $m);
            if ($via === 'console' && ! app()->environment('testing')) {
                Log::info("[mail] to={$m['to']} subject=\"{$m['subject']}\"");
            }
        } catch (Throwable $err) {
            Log::error('[mail] send failed: '.$err->getMessage());
        }
    }

    /** Sends one message and reports failure to the caller (Super Admin "send test email"). */
    public static function sendTest(string $to): array
    {
        $p = Platform::get();
        $e = $p['email'];
        $via = $e['smtpHost'] ? 'smtp' : ($e['resendApiKey'] ? 'resend' : 'console');
        $from = str_contains($e['fromAddress'], '<') ? $e['fromAddress'] : (($p['branding']['emailFromName'] ?: $p['branding']['productName']).' <'.$e['fromAddress'].'>');
        try {
            self::deliver($e, $from, ['to' => $to, 'subject' => $p['branding']['productName'].' test email', 'text' => 'Email delivery is configured correctly.']);

            return ['ok' => true, 'via' => $via];
        } catch (Throwable $err) {
            return ['ok' => false, 'via' => $via, 'error' => $err->getMessage()];
        }
    }

    private static function deliver(array $e, string $from, array $m): string
    {
        if ($e['smtpHost']) {
            $port = (int) $e['smtpPort'];
            $t = new EsmtpTransport($e['smtpHost'], $port, $port === 465);
            if ($e['smtpUser']) {
                $t->setUsername($e['smtpUser']);
                $t->setPassword((string) $e['smtpPass']);
            }
            (new SymfonyMailer($t))->send((new Email)->from($from)->to($m['to'])->subject($m['subject'])->text($m['text']));

            return 'smtp';
        }
        if (! $e['resendApiKey']) {
            return 'console';
        }
        $r = Http::withToken($e['resendApiKey'])->timeout(10)->post('https://api.resend.com/emails', ['from' => $from, 'to' => $m['to'], 'subject' => $m['subject'], 'text' => $m['text']]);
        if (! $r->successful()) {
            throw new \RuntimeException("Resend answered {$r->status()}");
        }

        return 'resend';
    }

    // ------------------------------------------------------------- templates (same wording as the Node app)
    private static function url(): string
    {
        return config('camplo.app_url');
    }

    public static function resetPassword(string $to, string $link): array
    {
        return ['to' => $to, 'subject' => 'Reset your Camplo password', 'text' => 'Click the link below to choose a new password. This link expires in '.round(config('camplo.password_reset_expiry') / 60)." minutes.\n\n{$link}"];
    }

    public static function invite(string $to, string $ownerName, string $business, string $link): array
    {
        return ['to' => $to, 'subject' => "You've been invited to {$business} on Camplo", 'text' => "{$ownerName} invited you to {$business} on Camplo — the watchtower for your campaigns and leads.\n\nAccept invite: {$link}"];
    }

    public static function slaAlert(string $to, string $leadName, string $deployment, int $minutes, string $ackLink): array
    {
        return ['to' => $to, 'subject' => "Urgent: {$leadName} has not been acknowledged — {$minutes} minutes waiting", 'text' => "{$leadName} (from {$deployment}) has been waiting {$minutes} minutes.\n\nAcknowledge now: {$ackLink}\nOpen dashboard: ".self::url().'/#/leads'];
    }

    public static function dailySummary(string $to, array $lines, string $avg): array
    {
        return ['to' => $to, 'subject' => 'Camplo Daily Summary — '.count($lines).' leads unacknowledged', 'text' => "Unacknowledged leads:\n".implode("\n", $lines)."\n\nAverage response time today: {$avg}\n\nGo to Inbox: ".self::url().'/#/leads'];
    }

    public static function newLead(string $to, string $leadName, string $deployment, string $link): array
    {
        return ['to' => $to, 'subject' => "New lead: {$leadName}", 'text' => "{$leadName} just arrived from {$deployment}. The SLA clock is running.\n\nOpen lead: {$link}"];
    }

    public static function magicLink(string $to, string $link): array
    {
        return ['to' => $to, 'subject' => 'Your Camplo sign-in link', 'text' => 'Click the link below to sign in to Camplo. It works once and expires in '.round(config('camplo.magic_link_expiry') / 60)." minutes.\n\n{$link}\n\nIf you didn't ask for this, you can ignore this email."];
    }

    public static function activated(string $to): array
    {
        return ['to' => $to, 'subject' => 'Your Camplo account is active', 'text' => 'Your account is active. Set up your account: '.self::url().'/#/login'];
    }

    public static function paymentReceived(string $to, string $amount): array
    {
        return ['to' => $to, 'subject' => 'Payment received — your Camplo account is under review', 'text' => "We received your payment of {$amount}. Your account is under review and is usually activated within one business day."];
    }
}
