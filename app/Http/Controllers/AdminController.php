<?php

namespace App\Http\Controllers;

use App\Services\AdminService as A;
use App\Services\Ai;
use App\Services\ExtensionsService;
use App\Services\Mailer;
use App\Services\Platform;
use App\Services\Polar;
use App\Services\Telegram;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Input;
use App\Support\Paginate;
use Illuminate\Http\Request;

/** Super Admin API under /admin/api — a separate admin token, never a tenant session. */
class AdminController
{
    private const PLANS = 'starter,growth,watchtower,agency';

    private function admin(Request $r): string
    {
        return A::verifyToken(Ctx::bearer($r));
    }

    public function login(Request $r)
    {
        $in = Input::get($r, ['email' => 'required|email', 'password' => 'required|string', 'code' => 'required|string|size:6']);

        return A::login($in['email'], $in['password'], $in['code']);
    }

    public function accounts(Request $r)
    {
        $this->admin($r);

        return Paginate::page(A::listAccounts(), $r);
    }

    public function account(Request $r, string $id)
    {
        $this->admin($r);

        return A::accountDetail($id);
    }

    public function activate(Request $r, string $id)
    {
        return A::setStatus($this->admin($r), $id, 'active');
    }

    public function suspend(Request $r, string $id)
    {
        return A::setStatus($this->admin($r), $id, 'suspended');
    }

    public function flag(Request $r, string $id)
    {
        return A::setStatus($this->admin($r), $id, 'flagged');
    }

    public function pricing(Request $r, string $id)
    {
        $a = $this->admin($r);

        return A::setPricing($a, $id, (float) Input::get($r, ['monthlyFee' => 'required|numeric|min:0'])['monthlyFee']);
    }

    public function note(Request $r, string $id)
    {
        $a = $this->admin($r);

        return A::addNote($a, $id, Input::get($r, ['note' => 'required|string|min:1|max:5000'])['note']);
    }

    public function update(Request $r, string $id)
    {
        $a = $this->admin($r);
        $in = Input::get($r, [
            'businessName' => 'sometimes|string|min:1|max:255', 'ownerName' => 'sometimes|string|min:1|max:255', 'ownerEmail' => 'sometimes|email',
            'plan' => 'sometimes|in:'.self::PLANS, 'storageQuotaGb' => 'sometimes|numeric|min:0.1|max:10000', 'monthlyFee' => 'sometimes|nullable|numeric|min:0',
            'notificationEmail' => 'sometimes|email', 'slaThresholdMinutes' => 'sometimes|integer|min:1|max:10080', 'setupComplete' => 'sometimes|boolean',
        ]);
        $in = array_intersect_key($in, $r->json()->all());

        return A::updateAccount($a, $id, $in);
    }

    public function platform(Request $r)
    {
        $this->admin($r);

        return ['sections' => Platform::describe(), 'history' => A::platformHistory()];
    }

    public function savePlatform(Request $r, string $section)
    {
        $a = $this->admin($r);
        if (! in_array($section, Platform::SECTIONS, true)) {
            throw Fail::bad('Unknown section.');
        }
        $values = A::cleanPlatformValues($section, (array) (Input::get($r, ['values' => 'required|array'])['values'] ?? []));
        $saved = Platform::saveSection($section, $values, $a);
        $secrets = Platform::SECRETS[$section]['fields'] ?? [];
        $changed = implode(', ', array_map(fn ($k) => in_array($k, $secrets, true) ? "{$k} (secret ".($values[$k] ? 'updated' : 'cleared').')' : $k, array_keys($values)));
        A::logPlatformChange($a, "platform_{$section}", 'Updated '.($changed ?: 'nothing'));
        if ($section === 'telegram' && ! empty($values['botToken'])) {
            Telegram::registerWebhook($saved['telegram']['botToken'], 'global');
        }

        return ['sections' => Platform::describe()];
    }

    public function testPlatform(Request $r, string $target)
    {
        $this->admin($r);
        $p = Platform::get();

        return match ($target) {
            'email' => Mailer::sendTest((string) (Input::get($r, ['to' => 'nullable|email'])['to'] ?? $p['branding']['supportEmail'])),
            'billing' => Polar::test(),
            'ai' => $p['ai']['openRouterApiKey'] ? ['ok' => Ai::verifyProvider('openrouter', $p['ai']['models']['quick'] ?? null, $p['ai']['openRouterApiKey'])] : ['ok' => false, 'error' => 'No OpenRouter key set.'],
            'telegram' => ! $p['telegram']['botToken'] ? ['ok' => false, 'error' => 'No bot token set.']
                : (($v = Telegram::verifyBot($p['telegram']['botToken']))['ok'] ? ['ok' => true, 'botUsername' => $v['username'] ?? null] : ['ok' => false, 'error' => 'Telegram rejected the bot token.']),
            default => throw Fail::bad('Unknown test target.'),
        };
    }

    public function extensions(Request $r)
    {
        $this->admin($r);

        return ExtensionsService::adminCatalog();
    }

    public function saveExtension(Request $r, string $key)
    {
        $a = $this->admin($r);
        $in = Input::get($r, ['kind' => 'required|in:camplo_mcp,mcp_server,skill,plugin', 'name' => 'required|string|min:1|max:255', 'description' => 'nullable|string|max:2000',
            'available' => 'required|boolean', 'minPlan' => 'required|in:'.self::PLANS, 'config' => 'nullable|array']);
        $res = ExtensionsService::adminSave([...$in, 'key' => $key, 'name' => trim($in['name']), 'description' => $in['description'] ?? '']);
        A::logPlatformChange($a, 'extension_saved', "{$in['name']} ({$key}): ".($in['available'] ? 'available' : 'unavailable')." from {$in['minPlan']}");

        return $res;
    }

    public function deleteExtension(Request $r, string $key)
    {
        $a = $this->admin($r);
        $res = ExtensionsService::adminDelete($key);
        A::logPlatformChange($a, 'extension_deleted', $key);

        return $res;
    }

    public function errors(Request $r)
    {
        $this->admin($r);

        return A::listErrors(Input::get($r, ['status' => 'nullable|in:open,resolved,all'], $r->query())['status'] ?? 'open');
    }

    public function resolveError(Request $r, string $id)
    {
        $a = $this->admin($r);

        return A::resolveError($a, $id, (bool) (Input::get($r, ['resolved' => 'nullable|boolean'])['resolved'] ?? true));
    }

    public function health(Request $r)
    {
        $this->admin($r);

        return A::systemHealth();
    }

    public function regenerateRetro(Request $r, string $id, string $campaignId)
    {
        return A::regenerateRetrospective($this->admin($r), $id, $campaignId);
    }
}
