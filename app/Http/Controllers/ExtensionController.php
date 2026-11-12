<?php

namespace App\Http\Controllers;

use App\Extensions\McpServer;
use App\Services\BriefingService;
use App\Services\ExtensionsService as E;
use App\Services\IntelService;
use App\Support\Input;
use Illuminate\Http\Request;

/** Settings → Extensions, the Camplo MCP endpoint, AI chat, manual refresh and the dashboard briefing. */
class ExtensionController
{
    public function index()
    {
        return E::list(ctx());
    }

    public function save(Request $r, string $key)
    {
        $in = Input::get($r, ['enabled' => 'nullable|boolean', 'settings' => 'nullable|array', 'token' => 'nullable|string|max:2000']);
        if (! $r->exists('token')) {
            unset($in['token']);
        }

        return E::save(ctx(), $key, $in);
    }

    public function remove(string $key)
    {
        return E::remove(ctx(), $key);
    }

    public function createToken(Request $r)
    {
        return E::createToken(ctx(), (string) (Input::get($r, ['name' => 'nullable|string|max:100'])['name'] ?? 'MCP client'));
    }

    public function revokeToken(string $id)
    {
        return E::revokeToken(ctx(), $id);
    }

    public function mcp(Request $r)
    {
        return McpServer::respond($r);
    }

    public function chat(Request $r)
    {
        $in = Input::get($r, ['content' => 'required|string|min:1|max:4000', 'depth' => 'nullable|in:Economy,Standard,Deep,Frontier']);

        return IntelService::chatMessage(ctx(), $in['content'], $in['depth'] ?? 'Standard');
    }

    public function refresh()
    {
        return IntelService::manualRefresh(ctx());
    }

    public function briefing(Request $r)
    {
        return BriefingService::get(ctx(), filter_var($r->query('refresh'), FILTER_VALIDATE_BOOLEAN));
    }
}
