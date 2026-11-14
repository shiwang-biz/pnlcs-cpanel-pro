<?php

namespace Modules\Servers\CPanelPro;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\ProvisioningService;
use Illuminate\Http\Request;

/**
 * The client's WordPress pages (cPanel WP Toolkit). Same checks as the other
 * extras: the client owns the service, it's a cPanel/WHM Pro account that is
 * not terminated, and nothing changes while it is suspended.
 */
class WordPressController extends Controller
{
    use ResolvesClient;

    public const TABS = ['overview', 'plugins', 'themes', 'updates', 'security', 'backups', 'clone', 'settings'];

    private function context(Service $service, bool $changing = false): CPanelProModule
    {
        abort_if($service->client_id !== $this->getClientId(), 403);
        abort_if(in_array(strtolower((string) $service->status), ['terminated', 'cancelled', 'fraud'], true), 404);
        $module = app(ProvisioningService::class)->resolveModule($service);
        abort_unless($module instanceof CPanelProModule && $service->server_id && $service->username, 404);
        if ($changing && strtolower((string) $service->status) === 'suspended') {
            abort(back()->with('error', __('client.services.suspended_for_action')));
        }

        return $module;
    }

    /** The module, after checking the site belongs to this account. */
    private function site(Service $service, int $site, bool $changing = false): CPanelProModule
    {
        $m = $this->context($service, $changing);
        abort_unless($m->wpOwnSite($service, $site), 404);

        return $m;
    }

    private function done(array $r)
    {
        return back()->with(($r['success'] ?? false) ? 'success' : 'error', $r['message'] ?? 'Something went wrong.');
    }

    // ---------- Sites ----------

    public function index(Service $service)
    {
        $m = $this->context($service);
        $list = $m->wpSites($service);

        return view('cpanelpro::client.wordpress.index', [
            'service' => $service,
            'list' => $list,
            'domains' => $m->accountDomains($service),
            'sets' => $list['ok'] ? $m->wpSets($service) : [],
        ]);
    }

    public function install(Request $request, Service $service)
    {
        $d = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'path' => ['nullable', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'admin_login' => ['nullable', 'string', 'max:60', 'regex:/^[a-zA-Z0-9._@-]+$/'],
            'admin_email' => ['nullable', 'email', 'max:254'],
            'admin_password' => ['nullable', 'string', 'min:8', 'max:100'],
            'language' => ['nullable', 'string', 'max:10', 'regex:/^[a-zA-Z_]+$/'],
            'set' => ['nullable', 'integer'],
            'core_updates' => ['nullable', 'in:disabled,minor,major'],
        ]);
        $m = $this->context($service, true);
        if (! empty($d['set']) && ! in_array((int) $d['set'], array_map('intval', array_column($m->wpSets($service), 'id')), true)) {
            return back()->with('error', 'Choose one of the sets offered here.');
        }

        return $this->done($m->wpInstall($service, $d));
    }

    public function scan(Service $service)
    {
        return $this->done($this->context($service, true)->wpScan($service));
    }

    public function show(Request $request, Service $service, int $site)
    {
        $m = $this->site($service, $site);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'overview';
        $data = ['service' => $service, 'site' => $m->wpSite($service, $site), 'tab' => $tab];

        $data += match ($tab) {
            'overview' => ['protection' => $m->wpPasswordProtection($service, $site), 'sets' => $m->wpSets($service)],
            'plugins', 'themes' => [
                'assets' => $m->wpAssets($service, $site, $tab),
                'q' => $q = trim((string) $request->query('q', '')),
                'catalog' => $q !== '' ? $m->wpCatalog($service, $site, $tab, $q, (int) $request->query('page', 1)) : null,
            ],
            'updates' => ['updates' => $m->wpUpdates($service, $site), 'settings' => $m->wpUpdateSettings($service, $site)],
            'security' => $m->wpSecurity($service, $site),
            'backups' => ['backups' => $m->wpBackups($service, $site)],
            'clone' => ['stub' => $m->wpCloneStub($service, $site), 'domains' => $m->accountDomains($service),
                'subdomains' => array_column($m->subdomains($service), 'full_name')],
            'settings' => ['account' => $m->wpAccount($service, $site)],
        };

        return view('cpanelpro::client.wordpress.site', $data);
    }

    /** Progress of the site's background tasks, for the page to poll. */
    public function tasks(Service $service, int $site)
    {
        $m = $this->site($service, $site);

        return response()->json(array_map(fn ($t) => [
            'id' => $t['id'] ?? null, 'title' => (string) ($t['title'] ?? ''), 'status' => (string) ($t['status'] ?? ''),
            'progress' => (float) ($t['progress'] ?? 0), 'errors' => array_values(array_map('strval', (array) ($t['errors'] ?? []))),
        ], array_slice($m->wpTasks($service, $site), 0, 10)));
    }

    public function login(Service $service, int $site)
    {
        $r = $this->site($service, $site, true)->wpLogin($service, $site);

        return $r['success'] ? redirect()->away($r['data']['url']) : $this->done($r);
    }

    // ---------- Switches and settings ----------

    public function toggle(Request $request, Service $service, int $site)
    {
        $d = $request->validate(['feature' => ['required', 'in:'.implode(',', array_merge(array_keys(CPanelProModule::WP_TOGGLES), ['virtualpatch']))], 'state' => ['required', 'in:0,1']]);

        return $this->done($this->site($service, $site, true)->wpToggle($service, $site, $d['feature'], $d['state'] === '1'));
    }

    public function passwordProtection(Request $request, Service $service, int $site)
    {
        $d = $request->validate(['state' => ['required', 'in:0,1'], 'username' => ['nullable', 'string', 'max:32'], 'password' => ['nullable', 'string', 'max:100']]);

        return $this->done($this->site($service, $site, true)->wpSetPasswordProtection($service, $site, $d['state'] === '1', $d['username'] ?? null, $d['password'] ?? null));
    }

    public function clearCache(Service $service, int $site)
    {
        return $this->done($this->site($service, $site, true)->wpClearCache($service, $site));
    }

    public function account(Request $request, Service $service, int $site)
    {
        $d = $request->validate([
            'login' => ['nullable', 'string', 'max:60'], 'password' => ['nullable', 'string', 'min:8', 'max:100'],
            'email' => ['nullable', 'email', 'max:254'], 'language' => ['nullable', 'string', 'max:10', 'regex:/^[a-zA-Z_]+$/'],
        ]);

        return $this->done($this->site($service, $site, true)->wpUpdateAccount($service, $site, $d));
    }

    public function installSet(Request $request, Service $service, int $site)
    {
        return $this->done($this->site($service, $site, true)->wpInstallSet($service, $site, (int) $request->input('set')));
    }

    // ---------- Plugins and themes ----------

    private function kind(string $kind): string
    {
        abort_unless(in_array($kind, ['plugins', 'themes'], true), 404);

        return $kind;
    }

    public function assetInstall(Request $request, Service $service, int $site, string $kind)
    {
        $d = $request->validate(['slug' => ['required', 'string', 'max:100'], 'activate' => ['nullable', 'in:0,1']]);

        return $this->done($this->site($service, $site, true)->wpInstallAsset($service, $site, $this->kind($kind), $d['slug'], ($d['activate'] ?? '1') === '1'));
    }

    public function assetUpload(Request $request, Service $service, int $site, string $kind)
    {
        $request->validate(['file' => ['required', 'file', 'max:65536', 'mimes:zip'], 'activate' => ['nullable', 'in:0,1']]);
        $f = $request->file('file');

        return $this->done($this->site($service, $site, true)->wpUploadAsset($service, $site, $this->kind($kind), $f->getRealPath(), $f->getClientOriginalName(), $request->input('activate', '1') === '1'));
    }

    public function assetStatus(Request $request, Service $service, int $site, string $kind)
    {
        $d = $request->validate(['slug' => ['required', 'string', 'max:100'], 'state' => ['required', 'in:0,1']]);

        return $this->done($this->site($service, $site, true)->wpAssetStatus($service, $site, $this->kind($kind), $d['slug'], $d['state'] === '1'));
    }

    public function assetAutoUpdate(Request $request, Service $service, int $site, string $kind)
    {
        $d = $request->validate(['slug' => ['required', 'string', 'max:100'], 'state' => ['required', 'in:0,1']]);

        return $this->done($this->site($service, $site, true)->wpAssetAutoUpdate($service, $site, $this->kind($kind), $d['slug'], $d['state'] === '1'));
    }

    public function assetDelete(Request $request, Service $service, int $site, string $kind)
    {
        $d = $request->validate(['slugs' => ['required', 'array', 'max:100'], 'slugs.*' => ['string', 'max:100']]);

        return $this->done($this->site($service, $site, true)->wpDeleteAssets($service, $site, $this->kind($kind), $d['slugs']));
    }

    // ---------- Updates ----------

    public function checkUpdates(Service $service, int $site)
    {
        return $this->done($this->site($service, $site, true)->wpCheckUpdates($service, $site));
    }

    public function applyUpdates(Request $request, Service $service, int $site)
    {
        $d = $request->validate(['core' => ['nullable', 'in:0,1'], 'plugins' => ['nullable', 'array'], 'plugins.*' => ['string', 'max:100'], 'themes' => ['nullable', 'array'], 'themes.*' => ['string', 'max:100']]);

        return $this->done($this->site($service, $site, true)->wpApplyUpdates($service, $site, ($d['core'] ?? '0') === '1', $d['plugins'] ?? [], $d['themes'] ?? []));
    }

    public function updateSettings(Request $request, Service $service, int $site)
    {
        $request->validate(['core' => ['required', 'in:disabled,minor,major']]);

        return $this->done($this->site($service, $site, true)->wpSaveUpdateSettings($service, $site, $request->only([
            'core', 'safe', 'plugins_all', 'plugins_new', 'plugins_vulnerable', 'plugins_deactivate', 'themes_all', 'themes_new', 'themes_vulnerable',
        ])));
    }

    // ---------- Security ----------

    public function security(Request $request, Service $service, int $site)
    {
        $d = $request->validate(['action' => ['required', 'in:check,apply,revert,vulnerabilities'], 'measures' => ['nullable', 'array'], 'measures.*' => ['string', 'max:100']]);

        return $this->done($this->site($service, $site, true)->wpSecurityAction($service, $site, $d['action'], $d['measures'] ?? []));
    }

    // ---------- Backups ----------

    public function backup(Request $request, Service $service, int $site)
    {
        return $this->done($this->site($service, $site, true)->wpCreateBackup($service, $site, (string) $request->input('note', '')));
    }

    public function restore(Request $request, Service $service, int $site)
    {
        return $this->done($this->site($service, $site, true)->wpRestoreBackup($service, $site, (string) $request->input('file')));
    }

    public function deleteBackups(Request $request, Service $service, int $site)
    {
        $d = $request->validate(['files' => ['required', 'array', 'max:50'], 'files.*' => ['string', 'max:255']]);

        return $this->done($this->site($service, $site, true)->wpDeleteBackups($service, $site, $d['files']));
    }

    // ---------- Clone ----------

    public function cloneSite(Request $request, Service $service, int $site)
    {
        $d = $request->validate([
            'target' => ['required', 'in:sub,existing'], 'name' => ['nullable', 'string', 'max:63'], 'parent' => ['nullable', 'string', 'max:253'],
            'existing' => ['nullable', 'string', 'max:253'], 'path' => ['nullable', 'string', 'max:200'], 'database' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->done($this->site($service, $site, true)->wpClone($service, $site, $d));
    }
}
