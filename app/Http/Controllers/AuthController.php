<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Services\WorkspaceService;
use App\Support\Input;
use Illuminate\Http\Request;

class AuthController
{
    public function signup(Request $r)
    {
        $in = Input::get($r, [
            'name' => 'required|string|min:1|max:255', 'email' => 'required|email', 'password' => 'required|string|min:8',
            'workspaceName' => 'required|string|min:1|max:255', 'plan' => 'nullable|in:starter,growth,watchtower',
        ]);
        $in['plan'] ??= 'growth';

        return AuthService::signup(ctx(), $in);
    }

    public function login(Request $r)
    {
        $in = Input::get($r, ['email' => 'required|email', 'password' => 'required|string|min:1']);

        return AuthService::login(ctx(), $in['email'], $in['password']);
    }

    public function magicLink(Request $r)
    {
        return AuthService::requestMagicLink(Input::get($r, ['email' => 'required|email'])['email']);
    }

    public function verify(Request $r)
    {
        return AuthService::verifyMagicLink(ctx(), Input::get($r, ['token' => 'required|string|min:10'])['token']);
    }

    public function logout()
    {
        return AuthService::logout(ctx());
    }

    public function refresh()
    {
        return AuthService::refresh(ctx());
    }

    public function forgot(Request $r)
    {
        return AuthService::forgotPassword(Input::get($r, ['email' => 'required|email'])['email']);
    }

    public function reset(Request $r)
    {
        $in = Input::get($r, ['token' => 'required|string|min:10', 'newPassword' => 'required|string|min:8']);

        return AuthService::resetPassword($in['token'], $in['newPassword']);
    }

    public function me()
    {
        return ['user' => AuthService::publicUser(ctx()->user), 'tenant' => AuthService::publicTenant(ctx()->tenant), 'next' => AuthService::nextRoute(ctx()->tenant)];
    }

    public function updateMe(Request $r)
    {
        return WorkspaceService::updateMe(ctx(), Input::get($r, ['name' => 'sometimes|string|min:1', 'theme' => 'sometimes|in:dark,light']));
    }

    public function avatar(Request $r)
    {
        return WorkspaceService::uploadAvatar(ctx(), $r->file('file'));
    }

    public function removeAvatar()
    {
        return WorkspaceService::removeAvatar(ctx());
    }

    public function acceptInvitation(Request $r)
    {
        $in = Input::get($r, ['token' => 'required|string|min:10', 'password' => 'required|string|min:8', 'name' => 'nullable|string']);

        return AuthService::acceptInvitation(ctx(), $in['token'], $in['password'], $in['name'] ?? null);
    }
}
