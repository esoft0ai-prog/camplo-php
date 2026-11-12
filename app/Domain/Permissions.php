<?php

namespace App\Domain;

/**
 * Per-teammate permissions. The workspace owner can do everything. Admins and members start from their role's
 * defaults; the owner can switch any permission on or off for an individual teammate.
 */
final class Permissions
{
    public const ALL = [
        'campaigns.manage' => ['label' => 'Create and edit campaigns', 'hint' => 'New campaigns, brief, budget and CPL threshold, pause, mark complete', 'admin' => true, 'member' => false],
        'campaigns.share' => ['label' => 'Share campaigns with clients', 'hint' => 'Create and revoke read-only client links', 'admin' => true, 'member' => false],
        'pages.manage' => ['label' => 'Manage pages', 'hint' => 'Upload, redeploy, roll back, pause, rename, VIP rule, custom domains', 'admin' => true, 'member' => false],
        'pages.delete' => ['label' => 'Delete pages', 'hint' => 'Permanently remove a hosted page (its leads are kept)', 'admin' => false, 'member' => false],
        'team.invite' => ['label' => 'Invite teammates', 'hint' => 'Send, resend and cancel invitations', 'admin' => true, 'member' => false],
        'team_notes.post' => ['label' => 'Post team notes', 'hint' => 'Address notes and deadlines to teammates', 'admin' => true, 'member' => false],
        'integrations.manage' => ['label' => 'Manage integrations and webhooks', 'hint' => 'Connect tools, API keys, inbound and outbound webhooks', 'admin' => true, 'member' => false],
        'sla.manage' => ['label' => 'Change SLA settings', 'hint' => 'Response thresholds, VIP rules, cross-tool SLA rules, alert routing', 'admin' => true, 'member' => false],
        'ai.manage' => ['label' => 'Manage AI provider', 'hint' => 'AI keys, models and refresh schedule', 'admin' => false, 'member' => false],
        'telegram.manage' => ['label' => 'Manage Telegram', 'hint' => 'Connect or disconnect the Telegram bot', 'admin' => false, 'member' => false],
        'notifications.manage' => ['label' => 'Change workspace notifications', 'hint' => 'Digest time and alert channels for the workspace', 'admin' => false, 'member' => false],
        'workspace.manage' => ['label' => 'Edit workspace details', 'hint' => 'Workspace name and logo', 'admin' => false, 'member' => false],
        'extensions.manage' => ['label' => 'Manage extensions', 'hint' => 'MCP servers and tokens, agent skills and plugins (plans and availability are set by Camplo)', 'admin' => false, 'member' => false],
        'logs.view' => ['label' => 'View the workspace log', 'hint' => 'Audit history of workspace changes', 'admin' => true, 'member' => false],
    ];

    /** @return array<string,bool> */
    public static function effective(string $role, ?array $overrides): array
    {
        $out = [];
        foreach (self::ALL as $k => $p) {
            if ($role === 'owner') {
                $out[$k] = true;

                continue;
            }
            $base = $role === 'admin' ? $p['admin'] : $p['member'];
            $out[$k] = is_array($overrides) && is_bool($overrides[$k] ?? null) ? $overrides[$k] : $base;
        }

        return $out;
    }

    public static function has(string $role, ?array $overrides, string $p): bool
    {
        return self::effective($role, $overrides)[$p] ?? false;
    }
}
