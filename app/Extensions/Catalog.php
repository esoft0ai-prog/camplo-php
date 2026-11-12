<?php

namespace App\Extensions;

use App\Domain\Rules;
use App\Support\Fmt;
use Illuminate\Support\Facades\DB;

/**
 * Extensions catalog — four kinds, controlled from Super Admin → Extensions:
 *   camplo_mcp (Camplo as an MCP server), mcp_server (other platforms' MCP servers the assistant may call read-only),
 *   skill (instruction packs), plugin (built-in modules acting on Camplo events).
 * Built-ins live in app/Domain/extensions.json; `extension_catalog` holds overrides and custom entries.
 */
final class Catalog
{
    public const KINDS = ['camplo_mcp', 'mcp_server', 'skill', 'plugin'];

    public static function builtIn(): array
    {
        static $items = null;

        return $items ??= json_decode(file_get_contents(app_path('Domain/extensions.json')), true);
    }

    private static function rowToItem(object $r): array
    {
        return ['key' => $r->key, 'kind' => $r->kind, 'name' => $r->name, 'description' => $r->description, 'available' => (bool) $r->available,
            'minPlan' => $r->min_plan, 'custom' => (bool) $r->custom, 'config' => (array) (Fmt::json($r->config) ?? [])];
    }

    public static function all(): array
    {
        $rows = DB::table('extension_catalog')->get()->keyBy('key');
        $out = array_map(function ($b) use ($rows) {
            $o = $rows[$b['key']] ?? null;

            return $o ? [...$b, 'available' => (bool) $o->available, 'minPlan' => $o->min_plan, 'name' => $o->name ?: $b['name'], 'description' => $o->description ?: $b['description'],
                'config' => [...$b['config'], ...(array) (Fmt::json($o->config) ?? [])]] : $b;
        }, self::builtIn());
        foreach ($rows as $r) {
            if ($r->custom && in_array($r->kind, self::KINDS, true)) {
                $out[] = self::rowToItem($r);
            }
        }

        return $out;
    }

    public static function item(string $key): ?array
    {
        foreach (self::all() as $i) {
            if ($i['key'] === $key) {
                return $i;
            }
        }

        return null;
    }

    public static function planAllows(string $plan, array $item): bool
    {
        return $item['available'] && Rules::planAtLeast($plan, $item['minPlan']);
    }

    public static function save(array $item): array
    {
        $builtIn = collect(self::builtIn())->firstWhere('key', $item['key']);
        $values = ['kind' => $builtIn['kind'] ?? $item['kind'], 'name' => $item['name'], 'description' => $item['description'], 'config' => json_encode((object) ($item['config'] ?? [])),
            'available' => (bool) $item['available'], 'min_plan' => $item['minPlan'], 'custom' => ! $builtIn, 'updated_at' => now()];
        DB::table('extension_catalog')->updateOrInsert(['key' => $item['key']], $values);

        return self::item($item['key']);
    }

    public static function deleteCustom(string $key): bool
    {
        if (collect(self::builtIn())->contains('key', $key)) {
            return false;
        }

        return DB::table('extension_catalog')->where('key', $key)->delete() > 0;
    }
}
