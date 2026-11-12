<?php

/*
 * The Camplo JSON API under /api — the same paths, inputs and response shapes as the Node version, so the
 * web client in public/ works unchanged.
 */

use App\Http\Controllers\AuthController as A;
use App\Http\Controllers\CampaignController as C;
use App\Http\Controllers\IntelController as I;
use App\Http\Controllers\LeadController as L;
use App\Http\Controllers\PageController as P;
use App\Http\Controllers\RawController as R;
use App\Http\Controllers\SlaController as S;
use App\Http\Controllers\WorkspaceController as W;
use Illuminate\Support\Facades\Route;

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
foreach (['id', 'noteId', 'leadId'] as $p) {
    Route::pattern($p, $uuid);
}

Route::get('/health', [W::class, 'health']);
Route::get('/public/platform', [W::class, 'publicPlatform']);
Route::get('/files/{path}', [W::class, 'file'])->where('path', '(logos|avatars)/.+');

// ------------------------------------------------------------------ auth
Route::post('/auth/signup', [A::class, 'signup']);
Route::post('/auth/login', [A::class, 'login']);
Route::post('/auth/magic-link', [A::class, 'magicLink']);
Route::get('/auth/verify', [A::class, 'verify']);
Route::post('/auth/logout', [A::class, 'logout']);
Route::post('/auth/refresh', [A::class, 'refresh']);
Route::post('/auth/forgot-password', [A::class, 'forgot']);
Route::post('/auth/reset-password', [A::class, 'reset']);
Route::post('/team/invitations/accept', [A::class, 'acceptInvitation']);
Route::get('/public/campaigns/{shareToken}', [C::class, 'publicCampaign']);
Route::get('/leads/acknowledge/preview', [L::class, 'ackPreview']);
Route::post('/leads/acknowledge', [L::class, 'acknowledge']);

// ------------------------------------------------------------------ raw endpoints (signed webhooks, links, cron, live updates)
Route::post('/v1/ingest/{tenantId}/{deploymentId}', [R::class, 'ingest']);
Route::post('/v1/hooks/{hookId}', [R::class, 'hook']);
Route::post('/v1/lifecycle/{integrationId}/{token}', [R::class, 'lifecycle']);
Route::get('/acknowledge/{token}', [R::class, 'acknowledgeLink']);
Route::get('/cron', [R::class, 'cron']);
Route::post('/polar/webhook', [R::class, 'polar']);
Route::post('/telegram/webhook', [R::class, 'telegram']);
Route::post('/telegram/{tenantId}', [R::class, 'telegram'])->where('tenantId', $uuid);
Route::post('/monitor/client-error', [R::class, 'clientError']);
Route::get('/caddy/ask', [\App\Http\Controllers\SiteController::class, 'caddyAsk']);
Route::get('/rt/workspace/{name}', [R::class, 'rt'])->where('name', '[a-z-]+');

Route::middleware('authed')->group(function () {
    Route::get('/auth/me', [A::class, 'me']);
    Route::patch('/auth/me', [A::class, 'updateMe']);
    Route::post('/auth/me/avatar', [A::class, 'avatar']);
    Route::delete('/auth/me/avatar', [A::class, 'removeAvatar']);

    // -------------------------------------------------------------- workspace + team
    Route::get('/workspace', [W::class, 'show']);
    Route::patch('/workspace', [W::class, 'patch']);
    Route::get('/workspace/plan', [W::class, 'plan']);
    Route::get('/workspace/subscription', [W::class, 'subscription']);
    Route::post('/workspace/upgrade', [W::class, 'upgrade']);
    Route::post('/workspace/logo', [W::class, 'logo']);
    Route::delete('/workspace/logo', [W::class, 'removeLogo']);

    Route::get('/team/members', [W::class, 'members']);
    Route::get('/team/members/{id}', [W::class, 'member']);
    Route::patch('/team/members/{id}/role', [W::class, 'role']);
    Route::patch('/team/members/{id}/permissions', [W::class, 'permissions']);
    Route::delete('/team/members/{id}', [W::class, 'removeMember']);
    Route::get('/team/permissions', [W::class, 'permissionCatalog']);
    Route::get('/team/invitations', [W::class, 'invitations']);
    Route::post('/team/invitations', [W::class, 'invite']);
    Route::post('/team/invitations/{id}/resend', [W::class, 'resend']);
    Route::delete('/team/invitations/{id}', [W::class, 'cancel']);

    // -------------------------------------------------------------- notifications, logs, search, settings
    Route::get('/logs', [W::class, 'logs']);
    Route::get('/notifications', [W::class, 'notifications']);
    Route::post('/notifications/{nid}/read', [W::class, 'readNotification']);
    Route::get('/search', [W::class, 'search']);
    Route::get('/settings', [W::class, 'settings']);
    Route::patch('/settings', [W::class, 'patchSettings']);
    Route::get('/settings/notifications', [W::class, 'notificationSettings']);
    Route::patch('/settings/notifications', [W::class, 'patchNotificationSettings']);

    // -------------------------------------------------------------- integrations, webhooks, AI provider, Telegram
    Route::get('/integrations', [\App\Http\Controllers\SettingsController::class, 'integrations']);
    Route::post('/integrations/ai/verify', [\App\Http\Controllers\SettingsController::class, 'verifyAi']);
    Route::post('/integrations/{provider}/connect', [\App\Http\Controllers\SettingsController::class, 'connect'])->where('provider', '[a-z_]+');
    Route::post('/integrations/{provider}/verify', [\App\Http\Controllers\SettingsController::class, 'verify'])->where('provider', '[a-z_]+');
    Route::delete('/integrations/{provider}', [\App\Http\Controllers\SettingsController::class, 'disconnect'])->where('provider', '[a-z_]+');
    Route::get('/webhooks/inbound', [\App\Http\Controllers\SettingsController::class, 'inbound']);
    Route::post('/webhooks/inbound', [\App\Http\Controllers\SettingsController::class, 'createInbound']);
    Route::delete('/webhooks/inbound/{id}', [\App\Http\Controllers\SettingsController::class, 'deleteInbound']);
    Route::get('/webhooks/outbound', [\App\Http\Controllers\SettingsController::class, 'outbound']);
    Route::post('/webhooks/outbound', [\App\Http\Controllers\SettingsController::class, 'createOutbound']);
    Route::delete('/webhooks/outbound/{id}', [\App\Http\Controllers\SettingsController::class, 'deleteOutbound']);
    Route::get('/settings/ai-provider', [\App\Http\Controllers\SettingsController::class, 'aiProvider']);
    Route::patch('/settings/ai-provider', [\App\Http\Controllers\SettingsController::class, 'patchAiProvider']);
    Route::get('/settings/telegram', [\App\Http\Controllers\SettingsController::class, 'telegram']);
    Route::patch('/settings/telegram', [\App\Http\Controllers\SettingsController::class, 'patchTelegram']);
    Route::post('/settings/telegram/verify', [\App\Http\Controllers\SettingsController::class, 'verifyTelegram']);
    Route::delete('/settings/telegram', [\App\Http\Controllers\SettingsController::class, 'disconnectTelegram']);

    // -------------------------------------------------------------- campaigns
    Route::get('/campaigns', [C::class, 'index']);
    Route::post('/campaigns', [C::class, 'store']);
    Route::get('/campaigns/{id}', [C::class, 'show']);
    Route::patch('/campaigns/{id}', [C::class, 'update']);
    Route::delete('/campaigns/{id}', [C::class, 'destroy']);
    Route::post('/campaigns/{id}/complete', [C::class, 'complete']);
    Route::post('/campaigns/{id}/pin', [C::class, 'pin']);
    Route::delete('/campaigns/{id}/pin', [C::class, 'unpin']);
    Route::get('/campaigns/{id}/health', [C::class, 'health']);
    Route::get('/campaigns/{id}/speed-to-lead', [C::class, 'speed']);
    Route::get('/campaigns/{id}/cpl', [C::class, 'cpl']);
    Route::get('/campaigns/{id}/logs', [C::class, 'logs']);
    Route::get('/campaigns/{id}/memory', [C::class, 'memory']);
    Route::get('/campaigns/{id}/retrospective/status', [C::class, 'retroStatus']);
    Route::get('/campaigns/{id}/retrospective/pdf', [C::class, 'retroPdf']);
    Route::get('/campaigns/{id}/retrospective', [C::class, 'retro']);
    Route::get('/campaigns/{id}/share-link', [C::class, 'shareGet']);
    Route::post('/campaigns/{id}/share-link', [C::class, 'shareCreate']);
    Route::delete('/campaigns/{id}/share-link', [C::class, 'shareRevoke']);
    Route::get('/campaigns/{id}/pages', [C::class, 'pages']);
    Route::get('/campaigns/{id}/leads', [C::class, 'leads']);
    Route::get('/campaigns/{id}/insights', [C::class, 'insights']);
    Route::get('/campaigns/{id}/recommendations', [C::class, 'recommendations']);
    Route::get('/campaigns/{id}/notes', [C::class, 'notes']);
    Route::post('/campaigns/{id}/notes', [C::class, 'addNote']);
    Route::patch('/campaigns/{id}/notes/{noteId}', [C::class, 'editNote']);
    Route::get('/campaigns/{id}/meeting-notes', [C::class, 'meetingNotes']);
    Route::post('/campaigns/{id}/meeting-notes', [C::class, 'addMeetingNote']);
    Route::get('/campaigns/{id}/team-notes', [C::class, 'teamNotes']);
    Route::get('/campaigns/{id}/lifecycle/summary', [C::class, 'lifecycleSummary']);
    Route::get('/campaigns/{id}/sla', [C::class, 'sla']);
    Route::get('/campaigns/{id}/sla/trend', [C::class, 'slaTrend']);
    Route::get('/campaigns/{id}/sla/team', [C::class, 'slaTeam']);

    // -------------------------------------------------------------- leads + notes
    Route::get('/leads', [L::class, 'index']);
    Route::get('/leads/{id}', [L::class, 'show']);
    Route::post('/leads/{id}/respond', [L::class, 'respond']);
    Route::post('/leads/{id}/assign', [L::class, 'assign']);
    Route::get('/leads/{id}/lifecycle', [L::class, 'lifecycle']);
    Route::get('/leads/{id}/notes', [L::class, 'notes']);
    Route::post('/leads/{id}/notes', [L::class, 'addNote']);
    Route::patch('/leads/{id}/notes/{noteId}', [L::class, 'editNote']);
    Route::get('/leads/{id}/audit', [L::class, 'audit']);
    Route::get('/leads/{id}/team-notes', [L::class, 'teamNotes']);
    Route::patch('/notes/{id}', [L::class, 'editAnyNote']);

    // -------------------------------------------------------------- SLA
    Route::get('/sla/live', [S::class, 'live']);
    Route::get('/sla/overdue', [S::class, 'overdue']);
    Route::get('/sla/trend', [S::class, 'trend']);
    Route::get('/sla/team', [S::class, 'team']);
    Route::get('/sla/cross-tool', [S::class, 'crossTool']);
    Route::post('/sla/notify/{leadId}', [S::class, 'notify']);
    Route::get('/sla/config', [S::class, 'config']);
    Route::patch('/sla/config', [S::class, 'patchConfig']);
    Route::get('/sla/config/cross-tool', [S::class, 'crossToolConfig']);
    Route::patch('/sla/config/cross-tool', [S::class, 'patchCrossTool']);

    // -------------------------------------------------------------- team notes
    Route::get('/team-notes', [S::class, 'teamNotes']);
    Route::post('/team-notes', [S::class, 'createTeamNote']);
    Route::patch('/team-notes/{id}', [S::class, 'editTeamNote']);
    Route::post('/team-notes/{id}/read', [S::class, 'readTeamNote']);
    Route::post('/team-notes/{id}/archive', [S::class, 'archiveTeamNote']);
    Route::post('/team-notes/{id}/acknowledge', [S::class, 'acknowledgeTeamNote']);

    // -------------------------------------------------------------- insights, recommendations, chat history
    Route::get('/insights', [I::class, 'insights']);
    Route::get('/insights/{id}', [I::class, 'insight']);
    Route::post('/insights/{id}/dismiss', [I::class, 'dismissInsight']);
    Route::get('/recommendations', [I::class, 'recommendations']);
    Route::get('/recommendations/{id}', [I::class, 'recommendation']);
    Route::post('/recommendations/{id}/dismiss', [I::class, 'dismissRecommendation']);
    Route::post('/recommendations/{id}/apply', [I::class, 'applyRecommendation']);
    Route::get('/chat/history', [I::class, 'chatHistory']);
    Route::delete('/chat/history', [I::class, 'chatClear']);
    Route::get('/chat/suggestions', [I::class, 'chatSuggestions']);

    // -------------------------------------------------------------- pages (also under /deployments, the ADL name)
    Route::post('/pages/upload', [P::class, 'upload']);
    Route::post('/deployments', [P::class, 'upload']);
    Route::get('/pages/upload/{id}/status', [P::class, 'uploadStatus']);
    foreach (['pages', 'deployments'] as $res) {
        Route::get("/{$res}", [P::class, 'index']);
        Route::get("/{$res}/{id}", [P::class, 'show']);
        Route::patch("/{$res}/{id}", [P::class, 'update']);
        Route::delete("/{$res}/{id}", [P::class, 'destroy']);
        Route::post("/{$res}/{id}/redeploy", [P::class, 'redeploy']);
        Route::post("/{$res}/{id}/rollback", [P::class, 'rollback']);
        Route::get("/{$res}/{id}/analytics", [P::class, 'analytics']);
        Route::get("/{$res}/{id}/webhook-health", [P::class, 'webhookHealth']);
        Route::post("/{$res}/{id}/webhook/test", [P::class, 'webhookTest']);
    }
    Route::post('/pages/{id}/pause', [P::class, 'pause']);
    Route::post('/pages/{id}/unpause', [P::class, 'unpause']);
    Route::post('/pages/{id}/archive', [P::class, 'archive']);
    Route::post('/pages/{id}/domain', [P::class, 'addPageDomain']);
    Route::get('/domains', [P::class, 'domains']);
    Route::post('/domains', [P::class, 'addDomain']);
    Route::post('/domains/{id}/verify', [P::class, 'verifyDomain']);
    Route::delete('/domains/{id}', [P::class, 'removeDomain']);
});

Route::any('/{any}', fn () => response()->json(['code' => 'NOT_FOUND', 'message' => 'Not found.'], 404))->where('any', '.*');
