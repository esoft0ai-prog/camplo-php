<?php

namespace App\Services;

use App\Domain\Rules;
use App\Extensions\Catalog;
use App\Extensions\McpClient;
use App\Support\Crypto;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings → Extensions (workspace) and Super Admin → Extensions (master backend). The master backend decides what
 * exists and on which plan; a workspace activates and configures it.
 */
final class ExtensionsService
{
    private static function secretsOf(?object $row): array
    {
        if (! $row?->secrets_encrypted) {
            return [];
        }
        try {
            return (array) json_decode(Crypto::decrypt($row->secrets_encrypted), true);
        } catch (Throwable) {
            return [];
        }
    }

    private static function view(array $item, ?object $row, string $plan): array
    {
        $settings = $row ? (array) (Fmt::json($row->settings) ?? []) : [];
        $c = $item['config'];
        $safe = match ($item['kind']) {
            'skill' => ['triggers' => $c['triggers'] ?? null, 'instructions' => $c['instructions'] ?? null],
            'mcp_server' => ['url' => $c['url'] ?? null, 'auth' => $c['auth'] ?? null, 'tokenLabel' => $c['tokenLabel'] ?? null, 'docsUrl' => $c['docsUrl'] ?? null, 'urlEditable' => $c['urlEditable'] ?? null],
            default => ['ownerOnly' => $c['ownerOnly'] ?? null],
        };
        $tools = $item['kind'] === 'mcp_server' && $row ? array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'readOnly' => $t['readOnly']], (array) ($settings['tools'] ?? [])) : null;
        unset($settings['cursor'], $settings['lastWeek']);
        $out = [
            'key' => $item['key'], 'kind' => $item['kind'], 'name' => $item['name'], 'description' => $item['description'], 'minPlan' => $item['minPlan'],
            'locked' => ! Rules::planAtLeast($plan, $item['minPlan']), 'custom' => $item['custom'], 'fields' => $c['fields'] ?? [], 'config' => array_filter($safe, fn ($v) => $v !== null),
            'active' => (bool) ($row->enabled ?? false), 'status' => $row->status ?? null, 'lastError' => $row->last_error ?? null, 'usageCount' => (int) ($row->usage_count ?? 0),
            'lastUsedAt' => Fmt::iso($row->last_used_at ?? null), 'activatedAt' => Fmt::iso($row->created_at ?? null),
            'settings' => (object) $settings, 'secrets' => (object) array_map([Crypto::class, 'mask'], self::secretsOf($row)),
        ];
        if ($tools !== null) {
            $out['tools'] = $tools;
        }

        return $out;
    }

    private static function endpoint(): string
    {
        return rtrim((string) config('camplo.app_url'), '/').'/api/mcp';
    }

    public static function list(Ctx $ctx): array
    {
        $ctx->assertPerm('extensions.manage');
        $rows = DB::table('workspace_extensions')->where('tenant_id', $ctx->tenantId)->get()->keyBy('key');
        $views = array_map(fn ($i) => self::view($i, $rows[$i['key']] ?? null, $ctx->plan), array_values(array_filter(Catalog::all(), fn ($i) => $i['available'])));
        $active = array_filter($views, fn ($v) => $v['active']);
        $hasMcp = (bool) array_filter($views, fn ($v) => $v['kind'] === 'camplo_mcp');

        return [
            'items' => $views,
            'summary' => ['active' => count($active), 'calls' => array_sum(array_column($active, 'usageCount')), 'errors' => count(array_filter($active, fn ($v) => $v['status'] === 'error'))],
            'camploMcp' => $hasMcp ? ['endpoint' => self::endpoint(), 'tokens' => self::listTokens($ctx)] : null,
        ];
    }

    private static function row(Ctx $ctx, string $key): ?object
    {
        return DB::table('workspace_extensions')->where('tenant_id', $ctx->tenantId)->where('key', $key)->first();
    }

    private static function usable(Ctx $ctx, string $key): array
    {
        $item = Catalog::item($key);
        if (! $item || ! $item['available']) {
            throw Fail::notFound('That extension is not available.');
        }
        if (! Catalog::planAllows($ctx->plan, $item)) {
            throw Fail::forbidden("{$item['name']} is available on the ".ucfirst($item['minPlan']).' plan and above.', 'plan_required');
        }
        if (! empty($item['config']['ownerOnly']) && $ctx->role !== 'owner') {
            throw Fail::forbidden("Only the workspace owner can switch on {$item['name']}.", 'owner_only');
        }

        return $item;
    }

    private static function cleanSettings(array $item, array $input, array $prev, array $members): array
    {
        $out = $prev;
        foreach ($item['config']['fields'] ?? [] as $f) {
            $k = $f['key'];
            if ($f['type'] === 'secret') {
                continue;
            }
            if (! array_key_exists($k, $input) || $input[$k] === null) {
                if (! array_key_exists($k, $out) && array_key_exists('default', $f)) {
                    $out[$k] = $f['default'];
                }

                continue;
            }
            $v = $input[$k];
            if ($f['type'] === 'number') {
                if (! is_numeric($v)) {
                    throw Fail::bad("{$f['label']} must be a number.");
                }
                $out[$k] = $v + 0;
            } elseif ($f['type'] === 'members') {
                $out[$k] = array_values(array_filter(is_array($v) ? $v : [], fn ($x) => is_string($x) && in_array($x, $members, true)));
            } elseif ($f['type'] === 'select') {
                if (! empty($f['options']) && ! in_array((string) $v, $f['options'], true)) {
                    throw Fail::bad("Choose a valid {$f['label']}.");
                }
                $out[$k] = (string) $v;
            } else {
                $out[$k] = mb_substr(trim((string) $v), 0, 500);
            }
            if (! empty($f['required']) && ($out[$k] === '' || $out[$k] === [])) {
                throw Fail::bad("{$f['label']} is required.");
            }
        }

        return $out;
    }

    /** Activate or update an extension. MCP servers are tested on save; their read-only tools are cached. */
    public static function save(Ctx $ctx, string $key, array $input): array
    {
        $ctx->assertPerm('extensions.manage');
        $item = self::usable($ctx, $key);
        $prev = self::row($ctx, $key);
        $prevSettings = $prev ? (array) (Fmt::json($prev->settings) ?? []) : [];
        $members = DB::table('users')->where('tenant_id', $ctx->tenantId)->whereNull('removed_at')->pluck('id')->all();
        $settings = self::cleanSettings($item, (array) ($input['settings'] ?? []), $prevSettings, $members);
        $secrets = self::secretsOf($prev);
        if (array_key_exists('token', $input)) {
            if ($input['token']) {
                $secrets['token'] = trim((string) $input['token']);
            } else {
                unset($secrets['token']);
            }
        }
        $status = 'ok';
        $lastError = null;
        if ($item['kind'] === 'mcp_server') {
            $url = (string) (! empty($item['config']['urlEditable']) ? ($input['settings']['url'] ?? $prevSettings['url'] ?? '') : ($item['config']['url'] ?? ''));
            try {
                McpClient::checkUrl($url);
            } catch (Throwable $e) {
                throw Fail::bad($e->getMessage());
            }
            if (($item['config']['auth'] ?? '') === 'bearer' && empty($secrets['token'])) {
                throw Fail::bad(($item['config']['tokenLabel'] ?? 'A token').' is required.');
            }
            $settings['url'] = $url;
            try {
                $tools = McpClient::listTools($url, $secrets['token'] ?? null);
                $settings['tools'] = $tools;
                if (! array_filter($tools, fn ($t) => $t['readOnly'])) {
                    $status = 'error';
                    $lastError = 'Connected, but this server offers no read-only tools Camplo can use.';
                }
            } catch (Throwable $e) {
                $status = 'error';
                $lastError = mb_substr($e->getMessage(), 0, 300);
            }
        }
        DB::table('workspace_extensions')->updateOrInsert(['tenant_id' => $ctx->tenantId, 'key' => $key], [
            'kind' => $item['kind'], 'enabled' => (bool) ($input['enabled'] ?? $prev->enabled ?? true), 'settings' => json_encode((object) $settings),
            'secrets_encrypted' => $secrets ? Crypto::encrypt(json_encode($secrets), 'integration') : null, 'status' => $status, 'last_error' => $lastError,
            'activated_by' => $prev->activated_by ?? $ctx->user->id,
        ]);
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, ($prev ? 'Updated' : 'Switched on')." extension: {$item['name']}");

        return self::view($item, self::row($ctx, $key), $ctx->plan);
    }

    public static function remove(Ctx $ctx, string $key): array
    {
        $ctx->assertPerm('extensions.manage');
        $item = Catalog::item($key);
        if (! empty($item['config']['ownerOnly']) && $ctx->role !== 'owner') {
            throw Fail::forbidden("Only the workspace owner can switch off {$item['name']}.", 'owner_only');
        }
        if (! DB::table('workspace_extensions')->where('tenant_id', $ctx->tenantId)->where('key', $key)->delete()) {
            throw Fail::notFound('That extension is not active.');
        }
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, 'Switched off extension: '.($item['name'] ?? $key));

        return ['ok' => true];
    }

    public static function listTokens(Ctx $ctx): array
    {
        return DB::table('mcp_tokens as t')->leftJoin('users as u', 'u.id', '=', 't.created_by')->where('t.tenant_id', $ctx->tenantId)->whereNull('t.revoked_at')
            ->orderByDesc('t.created_at')->get(['t.*', 'u.name as by'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'prefix' => $r->prefix, 'createdBy' => $r->by, 'createdAt' => Fmt::iso($r->created_at), 'lastUsedAt' => Fmt::iso($r->last_used_at)])->all();
    }

    public static function createToken(Ctx $ctx, string $name): array
    {
        $ctx->assertPerm('extensions.manage');
        self::usable($ctx, 'camplo_mcp');
        if (DB::table('mcp_tokens')->where('tenant_id', $ctx->tenantId)->whereNull('revoked_at')->count() >= 20) {
            throw Fail::conflict('Revoke an old token first (20 at most).');
        }
        $token = 'cmcp_'.Crypto::randomToken();
        $name = mb_substr(trim($name), 0, 100) ?: 'MCP client';
        $id = Sql::insertId('mcp_tokens', ['tenant_id' => $ctx->tenantId, 'created_by' => $ctx->user->id, 'name' => $name, 'token_hash' => Crypto::sha256($token), 'prefix' => substr($token, 0, 12)]);
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Created MCP token \"{$name}\"");

        return ['id' => $id, 'name' => $name, 'prefix' => substr($token, 0, 12), 'token' => $token, 'endpoint' => self::endpoint()];
    }

    public static function revokeToken(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('extensions.manage');
        $row = DB::table('mcp_tokens')->where('tenant_id', $ctx->tenantId)->where('id', $id)->whereNull('revoked_at')->first();
        if (! $row) {
            throw Fail::notFound('Token not found.');
        }
        DB::table('mcp_tokens')->where('id', $id)->update(['revoked_at' => now()]);
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Revoked MCP token \"{$row->name}\"");

        return ['ok' => true];
    }

    // ---------------------------------------------------------------- master backend

    public static function adminCatalog(): array
    {
        $counts = DB::table('workspace_extensions')->where('enabled', true)->groupBy('key')->select('key')->selectRaw('count(*) as n, coalesce(sum(usage_count), 0) as calls')->get()->keyBy('key');

        return array_map(fn ($i) => [...$i, 'workspaces' => (int) ($counts[$i['key']]->n ?? 0), 'calls' => (int) ($counts[$i['key']]->calls ?? 0)], Catalog::all());
    }

    public static function adminSave(array $in): array
    {
        if (! preg_match('/^[a-z][a-z0-9_]{2,60}$/', (string) ($in['key'] ?? ''))) {
            throw Fail::bad('Key must be lower-case letters, numbers and underscores.');
        }
        $existing = Catalog::item($in['key']);
        if (! $existing && in_array($in['kind'], ['camplo_mcp', 'plugin'], true)) {
            throw Fail::bad('Plugins and the Camplo MCP server are built in; you can only change their availability and plan.');
        }
        $kind = $existing['kind'] ?? $in['kind'];
        $cfg = [...($existing['config'] ?? []), ...(array) ($in['config'] ?? [])];
        if ($kind === 'skill') {
            if (trim((string) ($cfg['instructions'] ?? '')) === '') {
                throw Fail::bad('A skill needs instructions.');
            }
            $tr = is_array($cfg['triggers'] ?? null) ? $cfg['triggers'] : explode(',', (string) ($cfg['triggers'] ?? ''));
            $cfg['triggers'] = array_slice(array_values(array_filter(array_map(fn ($x) => mb_strtolower(trim((string) $x)), $tr), fn ($x) => $x !== '')), 0, 20);
            if (! $cfg['triggers']) {
                throw Fail::bad('Add at least one trigger phrase.');
            }
        }
        if ($kind === 'mcp_server' && empty($cfg['urlEditable'])) {
            try {
                McpClient::checkUrl((string) ($cfg['url'] ?? ''));
            } catch (Throwable $e) {
                throw Fail::bad($e->getMessage());
            }
        }

        return Catalog::save([...$in, 'kind' => $kind, 'config' => $cfg]);
    }

    public static function adminDelete(string $key): array
    {
        if (! Catalog::deleteCustom($key)) {
            throw Fail::bad('Built-in extensions cannot be deleted — make them unavailable instead.');
        }
        DB::table('workspace_extensions')->where('key', $key)->delete();

        return ['ok' => true];
    }
}
