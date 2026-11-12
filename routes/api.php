<?php

/*
 * The Camplo JSON API under /api — the same paths, inputs and response shapes as the Node version, so the
 * web client in public/ works unchanged.
 */

use App\Http\Controllers\AuthController as A;
use App\Http\Controllers\WorkspaceController as W;
use Illuminate\Support\Facades\Route;

Route::pattern('id', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

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
});

Route::any('/{any}', fn () => response()->json(['code' => 'NOT_FOUND', 'message' => 'Not found.'], 404))->where('any', '.*');
