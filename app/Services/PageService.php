<?php

namespace App\Services;

use App\Domain\Rules;
use App\Support\Crypto;
use App\Support\Ctx;
use App\Support\Fail;
use App\Support\Fmt;
use App\Support\Sql;
use App\Support\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Page hosting: ZIP upload → static files in storage, served at /sites/{subdomain}/ on the app host,
 * at {subdomain}.{PAGES_BASE_DOMAIN}, or on a verified custom domain. Nothing on a hosted page runs server-side.
 */
final class PageService
{
    public static function webhookUrlFor(object $d): string
    {
        return config('camplo.app_url')."/api/v1/ingest/{$d->tenant_id}/{$d->id}";
    }

    public static function pageUrlFor(object $d): string
    {
        return config('camplo.app_url')."/sites/{$d->subdomain}/";
    }

    private static function analytics(string $tenantId, array $ids): array
    {
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = ['visits7d' => 0, 'leads7d' => 0];
        }
        if (! $ids) {
            return $map;
        }
        foreach (DB::table('page_visits')->where('tenant_id', $tenantId)->whereIn('deployment_id', $ids)->where('day', '>=', gmdate('Y-m-d', time() - 7 * 86400))
            ->groupBy('deployment_id')->selectRaw('deployment_id as id, sum(visits) as n')->get() as $r) {
            $map[$r->id]['visits7d'] = (int) $r->n;
        }
        foreach (DB::table('leads')->where('tenant_id', $tenantId)->whereIn('deployment_id', $ids)->where('received_at', '>=', Fmt::ts(-7 * 86400))
            ->groupBy('deployment_id')->selectRaw('deployment_id as id, count(*) as n')->get() as $r) {
            $map[$r->id]['leads7d'] = (int) $r->n;
        }

        return $map;
    }

    public static function serialize(object $d, ?string $campaignName, ?object $hook, ?array $a, ?float $avgConv, ?object $domain, bool $canManage): array
    {
        $state = $hook ? Rules::webhookState($hook->last_received_at, (int) $hook->stale_threshold_minutes) : 'never_connected';
        $a ??= ['visits7d' => 0, 'leads7d' => 0];
        $conv = $a['visits7d'] ? $a['leads7d'] / $a['visits7d'] : null;
        $webhook = ['state' => $state, 'lastReceivedAt' => Fmt::iso($hook->last_received_at ?? null), 'url' => self::webhookUrlFor($d)];
        if ($canManage) {
            $webhook['secret'] = Crypto::decrypt($d->webhook_secret_encrypted);
        }

        return [
            'id' => $d->id, 'name' => $d->name, 'campaignId' => $d->campaign_id, 'campaignName' => $campaignName, 'subdomain' => $d->subdomain,
            'url' => self::pageUrlFor($d), 'host' => "{$d->subdomain}.".config('camplo.pages_base_domain'), 'status' => $d->status, 'servingState' => $d->serving_state, 'vip' => (bool) $d->vip,
            'entryFile' => $d->entry_file, 'storageSizeBytes' => (int) $d->storage_size_bytes, 'deployedAt' => Fmt::iso($d->deployed_at), 'createdAt' => Fmt::iso($d->created_at), 'failureReason' => $d->failure_reason,
            'webhook' => $webhook,
            'analytics' => [...$a, 'conversionRate' => $conv, 'aboveAverage' => $conv !== null && $avgConv !== null ? $conv > $avgConv : null],
            'hasRollbackAvailable' => Rules::hasRollbackAvailable($d->previous_storage_path, $d->previous_deployed_at, (int) config('camplo.rollback_retention_days')), 'previousDeployedAt' => Fmt::iso($d->previous_deployed_at),
            'earlyWarningActive' => Rules::earlyWarningActive($d->deployed_at, (int) config('camplo.early_warning_hours')), 'earlyWarningTriggered' => (bool) $d->early_warning_triggered,
            'domain' => $domain ? ['id' => $domain->id, 'name' => $domain->domain_name, 'status' => $domain->status] : null,
        ];
    }

    private static function hooksFor(array $ids): Collection
    {
        return $ids ? DB::table('webhook_sources')->whereIn('deployment_id', $ids)->get()->keyBy('deployment_id') : collect();
    }

    public static function list(Ctx $ctx, ?string $campaignId = null): array
    {
        if ($campaignId) {
            CampaignService::load($ctx, $campaignId);
        }
        $q = DB::table('deployments')->leftJoin('campaigns', 'campaigns.id', '=', 'deployments.campaign_id')->where('deployments.tenant_id', $ctx->tenantId)->where('deployments.status', '<>', 'deleted');
        if ($campaignId) {
            $q->where('deployments.campaign_id', $campaignId);
        }
        $rows = $q->orderByDesc('deployments.created_at')->get(['deployments.*', 'campaigns.name as cname']);
        $ids = $rows->pluck('id')->all();
        $a = self::analytics($ctx->tenantId, $ids);
        $avg = Metrics::workspaceConversion($ctx->tenantId);
        $hooks = self::hooksFor($ids);
        $doms = $ids ? DB::table('domains')->whereIn('deployment_id', $ids)->get()->keyBy('deployment_id') : collect();
        $canManage = $ctx->role !== 'member';

        return [
            'pages' => $rows->map(fn ($d) => self::serialize($d, $d->cname, $hooks[$d->id] ?? null, $a[$d->id] ?? null, $avg, $doms[$d->id] ?? null, $canManage))->all(),
            'avgConversionRate' => $avg,
        ];
    }

    public static function load(Ctx $ctx, string $id): object
    {
        $d = Str::isUuid($id) ? DB::table('deployments')->where('tenant_id', $ctx->tenantId)->where('id', $id)->where('status', '<>', 'deleted')->first() : null;
        if (! $d) {
            throw Fail::notFound('Page not found.');
        }

        return $d;
    }

    public static function get(Ctx $ctx, string $id): array
    {
        $d = self::load($ctx, $id);
        $cname = $d->campaign_id ? DB::table('campaigns')->where('id', $d->campaign_id)->value('name') : null;
        $lc = DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('deployment_id', $id)
            ->selectRaw(Sql::q("count(*) as n, count(*) filter (where status = 'not_responded') as un, avg(extract(epoch from (responded_at - received_at))) filter (where responded_at is not null) as avg"))->first();

        return [
            ...self::serialize($d, $cname, self::hooksFor([$id])[$id] ?? null, self::analytics($ctx->tenantId, [$id])[$id], Metrics::workspaceConversion($ctx->tenantId),
                DB::table('domains')->where('deployment_id', $id)->first(), $ctx->role !== 'member'),
            'leadSummary' => ['lead_count' => (int) $lc->n, 'unacknowledged_lead_count' => (int) $lc->un, 'avg_response_time_seconds' => $lc->avg === null ? null : (int) round((float) $lc->avg)],
        ];
    }

    public static function analyticsFor(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[gmdate('Y-m-d', time() - $i * 86400)] = ['date' => gmdate('Y-m-d', time() - $i * 86400), 'visits' => 0, 'leads' => 0];
        }
        $first = array_key_first($days);
        foreach (DB::table('page_visits')->where('deployment_id', $id)->where('day', '>=', $first)->get() as $r) {
            if (isset($days[$r->day])) {
                $days[$r->day]['visits'] = (int) $r->visits;
            }
        }
        foreach (DB::table('leads')->where('deployment_id', $id)->where('received_at', '>=', Sql::day0($first))->groupByRaw('1')
            ->selectRaw(Sql::q("to_char(received_at at time zone 'UTC', 'YYYY-MM-DD') as day, count(*) as n"))->get() as $r) {
            if (isset($days[$r->day])) {
                $days[$r->day]['leads'] = (int) $r->n;
            }
        }

        return ['daily' => array_values($days)];
    }

    public static function webhookHealth(Ctx $ctx, string $id): array
    {
        self::load($ctx, $id);
        $h = DB::table('webhook_sources')->where('deployment_id', $id)->first();

        return ['state' => $h ? Rules::webhookState($h->last_received_at, (int) $h->stale_threshold_minutes) : 'never_connected', 'lastReceivedAt' => Fmt::iso($h->last_received_at ?? null)];
    }

    /** Self-test: signs a sample payload with the page secret and checks it verifies. Creates no lead. */
    public static function testWebhook(Ctx $ctx, string $id): array
    {
        $d = self::load($ctx, $id);
        $t0 = microtime(true);
        $body = json_encode(['test' => true, 'full_name' => 'Camplo test']);
        $secret = Crypto::decrypt($d->webhook_secret_encrypted);
        $ok = LeadService::verifySignature($secret, $body, 'sha256='.Crypto::hmacHex($secret, $body));

        return ['ok' => $ok, 'latencyMs' => (int) round((microtime(true) - $t0) * 1000), 'url' => self::webhookUrlFor($d)];
    }

    // ------------------------------------------------------------- upload + processing

    private static function slugify(string $s): string
    {
        return substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'), 0, 40) ?: 'page';
    }

    private static function shortId(): string
    {
        return substr(str_replace('-', '', (string) Str::uuid()), 0, 6);
    }

    /**
     * Validate + extract a ZIP. Returns ['files' => [path => bytes], 'servingRoot', 'entryFile', 'size']
     * or ['needsInput' => [candidate entry files]] when several top-level HTML files could be the entry.
     */
    public static function extractZip(string $path, ?string $entryFile = null): array
    {
        $bad = fn () => Fail::unprocessable('Invalid ZIP file. Please check and try again.');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw $bad();
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (str_ends_with($n, '/') || preg_match('#(^|/)(__MACOSX|\.DS_Store)#', $n)) {
                continue;
            }
            if (str_contains($n, '..') || str_starts_with($n, '/') || str_contains($n, '\\')) {
                $zip->close();
                throw $bad();
            }
            $names[] = $n;
        }
        if (! $names) {
            $zip->close();
            throw $bad();
        }
        $depth = fn ($n) => count(explode('/', $n));
        $htmls = array_values(array_filter($names, fn ($n) => preg_match('/\.html?$/i', $n)));
        $noIndex = fn () => Fail::unprocessable('No index.html found in ZIP. Ensure your build output is included.');
        $entry = $entryFile;
        if ($entry === null) {
            $idx = array_values(array_filter($htmls, fn ($n) => preg_match('#(^|/)index\.html?$#i', $n)));
            usort($idx, fn ($a, $b) => $depth($a) <=> $depth($b));
            $entry = $idx[0] ?? null;
        }
        if ($entry === null) {
            if (! $htmls) {
                $zip->close();
                throw $noIndex();
            }
            $min = min(array_map($depth, $htmls));
            $shallow = array_values(array_filter($htmls, fn ($h) => $depth($h) === $min));
            if (count($shallow) > 1) {
                $zip->close();

                return ['needsInput' => $shallow];
            }
            $entry = $shallow[0];
        }
        if (! in_array($entry, $htmls, true)) {
            $zip->close();
            throw $noIndex();
        }
        $root = str_contains($entry, '/') ? substr($entry, 0, strrpos($entry, '/') + 1) : '';
        $files = [];
        $size = 0;
        foreach ($names as $n) {
            if (! str_starts_with($n, $root)) {
                continue;
            }
            $data = $zip->getFromName($n);
            if ($data === false) {
                $zip->close();
                throw $bad();
            }
            $files[substr($n, strlen($root))] = $data;
            $size += strlen($data);
        }
        $zip->close();

        return ['files' => $files, 'servingRoot' => $root, 'entryFile' => substr($entry, strlen($root)), 'size' => $size];
    }

    private static function storeVersion(string $tenantId, string $deploymentId, array $x): string
    {
        $prefix = "sites/{$tenantId}/{$deploymentId}/".base_convert((string) Rules::nowMs(), 10, 36).'/';
        foreach ($x['files'] as $path => $data) {
            Storage::put($prefix.$path, $data, Storage::contentTypeFor($path));
        }

        return $prefix;
    }

    private static function ensureQuota(Ctx $ctx, int $bytes): void
    {
        $t = DB::table('tenants')->where('id', $ctx->tenantId)->first();
        if ($t->storage_used_bytes + $bytes > $t->storage_quota_bytes) {
            throw Fail::tooLarge();
        }
    }

    private static function zipFile(?UploadedFile $f): UploadedFile
    {
        if (! $f || ! $f->isValid()) {
            throw Fail::bad('Choose a .zip file to upload.');
        }
        if (! preg_match('/\.zip$/i', $f->getClientOriginalName())) {
            throw Fail::unprocessable('Only .zip files can be uploaded.');
        }
        $max = (int) config('camplo.max_zip_size_mb');
        if ($f->getSize() > $max * 1024 * 1024) {
            throw Fail::tooLarge("File exceeds {$max}MB limit");
        }

        return $f;
    }

    public static function upload(Ctx $ctx, ?UploadedFile $file, array $in): array
    {
        $ctx->assertPerm('pages.manage');
        $file = self::zipFile($file);
        $n = DB::table('deployments')->where('tenant_id', $ctx->tenantId)->where('status', '<>', 'deleted')->count();
        $lim = Platform::planLimits($ctx->plan)['deployments'];
        if ($lim >= 0 && $n >= $lim) {
            $pf = Platform::get()['pricing'];
            throw Fail::planLimit("Your plan includes {$lim} deployments. Additional deployments are ".($pf['currency'] === 'USD' ? '$' : $pf['currency'].' ')."{$pf['addOns']['extraDeploymentMonthly']}/month each.", 'growth');
        }
        $campaignId = $in['campaignId'] ?? null;
        if ($campaignId) {
            CampaignService::load($ctx, $campaignId);
        }
        $x = self::extractZip($file->getRealPath(), $in['entryFile'] ?? null);
        if (isset($x['needsInput'])) {
            return ['status' => 'needs_input', 'entry_points' => $x['needsInput']];
        }
        self::ensureQuota($ctx, $x['size']);
        $name = trim((string) ($in['name'] ?? '')) ?: preg_replace('/\.zip$/i', '', $file->getClientOriginalName());
        $id = Sql::insertId('deployments', [
            'tenant_id' => $ctx->tenantId, 'campaign_id' => $campaignId, 'name' => $name, 'subdomain' => self::slugify(($in['slug'] ?? '') ?: $name).'-'.self::shortId(),
            'storage_path' => 'pending', 'entry_file' => $x['entryFile'], 'serving_root' => $x['servingRoot'], 'status' => 'processing',
            'webhook_secret_encrypted' => Crypto::encrypt(Crypto::randomToken(24), 'webhook'),
        ]);
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Deployment uploaded: {$name}");
        if ($campaignId) {
            Effects::logCampaign($ctx->tenantId, $campaignId, $ctx->user->id, "Page {$name} uploaded by {$ctx->user->name}");
        }
        $d = DB::table('deployments')->where('id', $id)->first();
        if (self::finish($ctx->tenantId, $d, $x) === 'failed') {
            throw Fail::unprocessable('Deployment failed. Try again.');
        }

        return ['uploadId' => $id, 'id' => $id, 'status' => 'ready'];
    }

    private static function finish(string $tenantId, object $d, array $x): string
    {
        try {
            $prefix = self::storeVersion($tenantId, $d->id, $x);
            DB::table('deployments')->where('id', $d->id)->update(['storage_path' => $prefix, 'status' => 'ready', 'storage_size_bytes' => $x['size'], 'deployed_at' => now(), 'early_warning_active' => true, 'updated_at' => now()]);
            DB::table('tenants')->where('id', $tenantId)->increment('storage_used_bytes', $x['size']);
            DB::table('webhook_sources')->insertOrIgnore(['tenant_id' => $tenantId, 'deployment_id' => $d->id, 'campaign_id' => $d->campaign_id, 'label' => $d->name, 'source_system' => 'webhook']);
        } catch (\Throwable $e) {
            DB::table('deployments')->where('id', $d->id)->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 500)]);

            return 'failed';
        }
        if ($d->campaign_id) {
            Effects::logCampaign($tenantId, $d->campaign_id, null, "Page {$d->name} is live");
        }
        Effects::emit($tenantId, 'workspace:leads', ['type' => 'deployment_ready', 'deploymentId' => $d->id]);
        Effects::fireOutbound($tenantId, 'deployment.ready', ['deployment_id' => $d->id, 'name' => $d->name, 'url' => self::pageUrlFor($d)]);

        return 'ready';
    }

    public static function uploadStatus(Ctx $ctx, string $id): array
    {
        $d = self::load($ctx, $id);

        return ['status' => $d->status, 'id' => $d->id, 'url' => self::pageUrlFor($d), 'failureReason' => $d->failure_reason];
    }

    public static function redeploy(Ctx $ctx, string $id, ?UploadedFile $file): array
    {
        $ctx->assertPerm('pages.manage');
        $d = self::load($ctx, $id);
        $x = self::extractZip(self::zipFile($file)->getRealPath());
        if (isset($x['needsInput'])) {
            return ['status' => 'needs_input', 'entry_points' => $x['needsInput']];
        }
        self::ensureQuota($ctx, $x['size']);
        $prefix = self::storeVersion($ctx->tenantId, $id, $x);
        // Keep exactly one previous version for rollback; delete the one before it.
        if ($d->previous_storage_path) {
            Storage::removePrefix($d->previous_storage_path);
        }
        DB::table('deployments')->where('id', $id)->update([
            'previous_storage_path' => $d->storage_path, 'previous_deployed_at' => $d->deployed_at, 'storage_path' => $prefix, 'entry_file' => $x['entryFile'], 'serving_root' => $x['servingRoot'],
            'storage_size_bytes' => $x['size'], 'deployed_at' => now(), 'early_warning_active' => true, 'early_warning_triggered' => false, 'updated_at' => now(),
        ]);
        // Storage counts the live version (the Node app never adjusted this on redeploy).
        DB::update('update tenants set storage_used_bytes = greatest(0, storage_used_bytes + ?) where id = ?', [$x['size'] - (int) $d->storage_size_bytes, $ctx->tenantId]);
        if ($d->campaign_id) {
            Effects::logCampaign($ctx->tenantId, $d->campaign_id, $ctx->user->id, "Page {$d->name} redeployed by {$ctx->user->name}");
        }

        return ['status' => 'ready', 'id' => $id];
    }

    public static function rollback(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('pages.manage');
        $d = self::load($ctx, $id);
        if (! Rules::hasRollbackAvailable($d->previous_storage_path, $d->previous_deployed_at, (int) config('camplo.rollback_retention_days'))) {
            throw Fail::bad('No previous version is available.');
        }
        DB::table('deployments')->where('id', $id)->update([
            'storage_path' => $d->previous_storage_path, 'previous_storage_path' => $d->storage_path, 'deployed_at' => $d->previous_deployed_at, 'previous_deployed_at' => now(), 'updated_at' => now(),
        ]);
        if ($d->campaign_id) {
            Effects::logCampaign($ctx->tenantId, $d->campaign_id, $ctx->user->id, "Page {$d->name} rolled back by {$ctx->user->name}");
        }

        return ['ok' => true];
    }

    public static function patch(Ctx $ctx, string $id, array $p): array
    {
        $ctx->assertPerm('pages.manage');
        $d = self::load($ctx, $id);
        $set = ['updated_at' => now()];
        if (! empty($p['name'])) {
            $set['name'] = trim($p['name']);
        }
        if (array_key_exists('vip', $p) && $p['vip'] !== null) {
            $set['vip'] = (bool) $p['vip'];
        }
        if (! empty($p['slug'])) {
            $parts = explode('-', $d->subdomain);
            $sub = self::slugify($p['slug']).'-'.end($parts);
            $clash = DB::table('deployments')->where('subdomain', $sub)->value('id');
            if ($clash && $clash !== $id) {
                throw Fail::conflict('That address is already taken.');
            }
            $set['subdomain'] = $sub;
        }
        if (array_key_exists('campaignId', $p)) {
            if ($p['campaignId']) {
                CampaignService::load($ctx, $p['campaignId']);
            }
            $set['campaign_id'] = $p['campaignId'];
            DB::table('webhook_sources')->where('deployment_id', $id)->update(['campaign_id' => $p['campaignId']]);
        }
        DB::table('deployments')->where('id', $id)->update($set);

        return self::get($ctx, $id);
    }

    public static function setServingState(Ctx $ctx, string $id, string $state): array
    {
        $ctx->assertPerm('pages.manage');
        $d = self::load($ctx, $id);
        DB::table('deployments')->where('id', $id)->update(['serving_state' => $state, 'updated_at' => now()]);
        if ($d->campaign_id) {
            Effects::logCampaign($ctx->tenantId, $d->campaign_id, $ctx->user->id, "Page {$d->name} ".($state === 'active' ? 'unpaused' : $state)." by {$ctx->user->name}");
        }

        return ['ok' => true, 'servingState' => $state];
    }

    /** Leads are retained permanently; they are flagged deployment_deleted. */
    public static function delete(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('pages.delete');
        $d = self::load($ctx, $id);
        DB::table('leads')->where('tenant_id', $ctx->tenantId)->where('deployment_id', $id)->update(['deployment_deleted' => true]);
        DB::table('deployments')->where('id', $id)->update(['status' => 'deleted', 'updated_at' => now()]);
        Storage::removePrefix("sites/{$ctx->tenantId}/{$id}/");
        DB::update('update tenants set storage_used_bytes = greatest(0, storage_used_bytes - ?) where id = ?', [(int) $d->storage_size_bytes, $ctx->tenantId]);
        Effects::logWorkspace($ctx->tenantId, $ctx->user->id, "Deployment deleted: {$d->name}");

        return ['ok' => true];
    }

    // ------------------------------------------------------------- custom domains

    public static function listDomains(Ctx $ctx): array
    {
        $base = config('camplo.pages_base_domain');

        return DB::table('domains')->join('deployments', 'deployments.id', '=', 'domains.deployment_id')->where('domains.tenant_id', $ctx->tenantId)
            ->get(['domains.*', 'deployments.name as page', 'deployments.subdomain as sub'])
            ->map(fn ($r) => ['id' => $r->id, 'domainName' => $r->domain_name, 'status' => $r->status, 'deploymentId' => $r->deployment_id, 'pageName' => $r->page, 'cnameTarget' => "{$r->sub}.{$base}"])->all();
    }

    public static function addDomain(Ctx $ctx, string $deploymentId, string $domainName): array
    {
        $ctx->assertPerm('pages.manage');
        $d = self::load($ctx, $deploymentId);
        $name = preg_replace(['#^https?://#', '#/.*$#'], '', strtolower(trim($domainName)));
        if (! preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $name)) {
            throw Fail::bad('Enter a valid domain like offers.yourbrand.com');
        }
        $taken = DB::table('domains')->where('domain_name', $name)->first();
        if ($taken) {
            throw Fail::conflict($taken->tenant_id !== $ctx->tenantId ? 'This domain is already connected to another account.' : 'This domain is already connected.');
        }
        $id = Sql::insertId('domains', ['tenant_id' => $ctx->tenantId, 'deployment_id' => $deploymentId, 'domain_name' => $name, 'verification_token' => Crypto::randomToken(12)]);

        return ['id' => $id, 'domainName' => $name, 'status' => 'pending', 'record' => ['name' => $name, 'type' => 'CNAME', 'value' => "{$d->subdomain}.".config('camplo.pages_base_domain')]];
    }

    /** @var (callable(string): array<int,string>)|null test hook for DNS lookups */
    public static $resolveCname = null;

    public static function verifyDomain(Ctx $ctx, string $id): array
    {
        $row = DB::table('domains')->join('deployments', 'deployments.id', '=', 'domains.deployment_id')->where('domains.tenant_id', $ctx->tenantId)->where('domains.id', $id)
            ->first(['domains.*', 'deployments.subdomain as sub']);
        if (! $row) {
            throw Fail::notFound('Domain not found.');
        }
        $target = "{$row->sub}.".config('camplo.pages_base_domain');
        $lookup = self::$resolveCname ?? fn (string $host) => array_map(fn ($r) => $r['target'] ?? '', @dns_get_record($host, DNS_CNAME) ?: []);
        $ok = false;
        foreach ($lookup($row->domain_name) as $c) {
            if (strtolower(rtrim($c, '.')) === $target) {
                $ok = true;
            }
        }
        if ($ok) {
            DB::table('domains')->where('id', $id)->update(['status' => 'verified']);
        }

        return ['verified' => $ok, 'message' => $ok ? 'Verified' : 'Not confirmed yet. Wait a few minutes and try again.'];
    }

    public static function removeDomain(Ctx $ctx, string $id): array
    {
        $ctx->assertPerm('pages.manage');
        DB::table('domains')->where('tenant_id', $ctx->tenantId)->where('id', $id)->delete();

        return ['ok' => true];
    }

    // ------------------------------------------------------------- serving

    /** Resolve a hosted page by subdomain or by host (verified custom domain or {sub}.{PAGES_BASE_DOMAIN}). */
    public static function resolveSite(?string $subdomain = null, ?string $host = null): ?array
    {
        if ($host) {
            $d = DB::table('domains')->join('deployments', 'deployments.id', '=', 'domains.deployment_id')->where('domains.domain_name', strtolower($host))
                ->where('domains.status', 'verified')->first(['deployments.*']);
            if ($d) {
                return ['d' => $d, 't' => DB::table('tenants')->where('id', $d->tenant_id)->first()];
            }
            $base = '.'.config('camplo.pages_base_domain');
            if (! str_ends_with($host, $base)) {
                return null;
            }
            $subdomain = substr($host, 0, -strlen($base));
        }
        if (! $subdomain) {
            return null;
        }
        $d = DB::table('deployments')->where('subdomain', $subdomain)->first();

        return $d ? ['d' => $d, 't' => DB::table('tenants')->where('id', $d->tenant_id)->first()] : null;
    }

    private static function graceOver(object $t): bool
    {
        return $t->status === 'suspended' && $t->suspended_at && Rules::nowMs() - Rules::ms($t->suspended_at) > 7 * Rules::DAY;
    }

    /** Why a hosted page is not being served (shown to visitors instead of a bare 404). */
    public static function siteUnavailable(?array $site): ?string
    {
        if (! $site || in_array($site['d']->status, ['deleted', 'failed'], true)) {
            return 'missing';
        }
        if ($site['d']->status !== 'ready') {
            return 'offline';
        }
        if ($site['d']->serving_state !== 'active') {
            return 'paused';
        }

        return self::graceOver($site['t']) ? 'offline' : null;
    }

    /** @return array{data:string,contentType:string}|null */
    public static function serveFile(array $site, string $path): ?array
    {
        ['d' => $d, 't' => $t] = $site;
        if ($d->status !== 'ready' || $d->serving_state !== 'active' || self::graceOver($t)) {
            return null;
        }
        $clean = explode('?', ltrim($path, '/'))[0];
        if (str_contains($clean, '..')) {
            return null;
        }
        $candidates = $clean === '' || str_ends_with($clean, '/') ? [$clean.$d->entry_file] : [$clean, "{$clean}/index.html", "{$clean}.html"];
        foreach ($candidates as $c) {
            $f = Storage::get($d->storage_path.$c);
            if ($f) {
                if (preg_match('/\.html?$/i', $c)) {
                    DB::statement('insert into page_visits (tenant_id, deployment_id, day, visits) values (?, ?, ?, 1) '.(Sql::mysql()
                        ? 'on duplicate key update visits = visits + 1' : 'on conflict (deployment_id, day) do update set visits = page_visits.visits + 1'), [$d->tenant_id, $d->id, gmdate('Y-m-d')]);
                }

                return $f;
            }
        }

        return null;
    }
}
