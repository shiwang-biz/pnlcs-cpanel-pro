<?php

namespace Modules\Servers\CPanelPro;

use App\Models\Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WordPress through cPanel's WP Toolkit (REST API v1, WP Toolkit 6.x).
 *
 * Every call runs in the client's OWN cPanel session (port 2083,
 * /3rdparty/wpt/index.php), so WP Toolkit itself only lets it see and change
 * that account's sites. On top of that, every site id, domain, slug and
 * backup file that comes from the browser is checked against the account's
 * own lists first.
 */
trait WpToolkit
{
    /** @var array<int, array{0:string,1:mixed}|false> service id => cPanel session, per request */
    private array $wptSessions = [];

    /** @var array<int, array> service id => site list, per request */
    private array $wptSitesMemo = [];

    public const WP_TOGGLES = [
        'maintenance' => 'features/maintenance/status',
        'debug' => 'features/debug/status',
        'indexing' => 'features/indexing/status',
        'hotlink' => 'features/hotlink-protection/status',
        'smart' => 'features/updates/smart/status',
    ];

    private static function slugOk(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/i', $slug);
    }

    /**
     * One call to WP Toolkit's REST API as the client.
     *
     * @return array{ok:bool, status:int, data:mixed, message:string}
     */
    protected function wpt(Service $service, string $method, string $path, array $query = [], mixed $body = null, ?array $file = null): array
    {
        $acc = $this->cpAccount($service);
        if (! $acc) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'No cPanel account on this service.'];
        }
        if (! array_key_exists($service->id, $this->wptSessions)) {
            $this->wptSessions[$service->id] = $this->cpanelSession($acc[0], $acc[1]) ?? false;
        }
        $session = $this->wptSessions[$service->id];
        if (! $session) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'Could not open a cPanel session right now.'];
        }
        [$base, $jar] = $session;

        $url = $base.'/3rdparty/wpt/index.php'.$path;
        if ($query) {
            // WP Toolkit wants list parameters as name[]=1&name[]=2.
            $url .= '?'.preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($query));
        }

        try {
            $req = Http::withoutVerifying()->withOptions(['cookies' => $jar, 'allow_redirects' => false])
                ->acceptJson()->timeout(90);
            if ($file) {
                $resp = $req->attach('file', $file['contents'], $file['name'])->post($url, ['status' => $file['status'] ? 'true' : 'false']);
            } else {
                $resp = match (strtolower($method)) {
                    'get' => $req->get($url),
                    'post' => $req->post($url, $body ?? []),
                    'put' => $req->put($url, $body ?? []),
                    'patch' => $req->patch($url, $body ?? []),
                    'delete' => $req->delete($url, $body ?? []),
                };
            }
        } catch (\Throwable $e) {
            Log::warning('CPanelPro WP Toolkit '.$method.' '.$path.': '.$e->getMessage());

            return ['ok' => false, 'status' => 0, 'data' => null, 'message' => 'WP Toolkit did not answer. Please try again.'];
        }

        $data = $resp->json();
        $status = $resp->status();
        if ($status >= 200 && $status < 400) {
            return ['ok' => true, 'status' => $status, 'data' => $data, 'message' => ''];
        }

        $msg = is_array($data) ? (string) ($data['meta']['message'] ?? '') : '';
        if (is_array($data['formErrors'] ?? null)) {
            $errs = [];
            array_walk_recursive($data['formErrors'], function ($v) use (&$errs) {
                if (is_string($v) && $v !== '') {
                    $errs[] = $v;
                }
            });
            if ($errs) {
                $msg = trim($msg.' '.implode(' ', array_unique($errs)));
            }
        }
        if ($msg === '') {
            $msg = match (true) {
                $status === 404 && ! is_array($data) => 'WP Toolkit is not available on this server.',
                $status === 401 || $status === 403 => 'WP Toolkit refused this request.',
                default => "WP Toolkit returned an error ({$status}).",
            };
        }

        return ['ok' => false, 'status' => $status, 'data' => $data, 'message' => $msg];
    }

    /** A started background task, as a result for the client. */
    private function wptTaskResult(array $r, string $started): array
    {
        if (! $r['ok']) {
            return $this->fail($r['message']);
        }
        $task = $r['data']['task'] ?? null;

        return $this->buildResult(true, $started, ['task' => $task]);
    }

    // ------------------------------------------------------------------
    // Sites
    // ------------------------------------------------------------------

    /** @return array{ok:bool, message:string, sites:list<array>} */
    public function wpSites(Service $service): array
    {
        if (isset($this->wptSitesMemo[$service->id])) {
            return $this->wptSitesMemo[$service->id];
        }
        $r = $this->wpt($service, 'get', '/v1/installations', ['sortBy' => 'displayTitle', 'sortOrder' => 'asc']);
        $user = strtolower((string) ($this->cpAccount($service)[1] ?? ''));
        $sites = [];
        foreach ((array) ($r['ok'] ? $r['data'] : []) as $s) {
            if (! is_array($s) || ! isset($s['id'])) {
                continue;
            }
            // Belt and braces: the session is already the client's, but keep only their own.
            $owner = strtolower((string) ($s['owner']['login'] ?? $user));
            $path = (string) ($s['path'] ?? '');
            if ($owner !== $user || ($path !== '' && ! str_starts_with($path, '/home/'.$user.'/') && $path !== '/home/'.$user)) {
                continue;
            }
            $sites[] = $s;
        }

        return $this->wptSitesMemo[$service->id] = ['ok' => $r['ok'], 'message' => $r['message'], 'sites' => $sites];
    }

    /** The site, if it belongs to this account. */
    public function wpOwnSite(Service $service, int $id): ?array
    {
        foreach ($this->wpSites($service)['sites'] as $s) {
            if ((int) $s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }

    /** Full details (features and their states) for one own site. */
    public function wpSite(Service $service, int $id): ?array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return null;
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}");

        return $r['ok'] && is_array($r['data']) ? $r['data'] : $this->wpOwnSite($service, $id);
    }

    public function wpScan(Service $service): array
    {
        $user = $this->cpAccount($service)[1] ?? '';

        return $this->wptTaskResult($this->wpt($service, 'post', '/v1/scanner', [], ['users' => [$user]]), 'Looking for WordPress sites… Refresh in a minute.');
    }

    /** Defaults for the install form (admin login, generated password, database name …). */
    public function wpInstallStub(Service $service, ?string $domain = null): array
    {
        $r = $this->wpt($service, 'get', '/v1/installer/installation-stub', array_filter(['domain' => $domain]));

        return $r['ok'] && is_array($r['data']) ? $r['data'] : [];
    }

    public function wpInstall(Service $service, array $in): array
    {
        $domain = strtolower(trim((string) ($in['domain'] ?? '')));
        if (! $this->ownsDomain($service, $domain)) {
            return $this->fail('Choose one of this account\'s domains.');
        }
        $path = trim((string) ($in['path'] ?? ''), " /");
        if ($path !== '' && (str_contains($path, '..') || ! preg_match('#^[a-zA-Z0-9._/-]{1,200}$#', $path))) {
            return $this->fail('The folder may contain letters, numbers, dots, dashes and slashes only.');
        }
        $body = array_filter([
            'domain' => $domain,
            'installationPath' => $path === '' ? null : $path,
            'title' => trim((string) ($in['title'] ?? '')) ?: null,
            'language' => $in['language'] ?? null,
            'protocol' => in_array($in['protocol'] ?? '', ['http', 'https'], true) ? $in['protocol'] : null,
            'set' => ! empty($in['set']) ? (int) $in['set'] : null,
            'admin' => array_filter([
                'login' => trim((string) ($in['admin_login'] ?? '')) ?: null,
                'email' => trim((string) ($in['admin_email'] ?? '')) ?: null,
                'password' => (string) ($in['admin_password'] ?? '') ?: null,
            ]) ?: null,
            'autoUpdate' => isset($in['core_updates']) && in_array($in['core_updates'], ['disabled', 'minor', 'major'], true) ? ['core' => $in['core_updates']] : null,
        ], fn ($v) => $v !== null);

        unset($this->wptSitesMemo[$service->id]);

        return $this->wptTaskResult($this->wpt($service, 'post', '/v1/installations', [], $body), "Installing WordPress on {$domain}".($path !== '' ? "/{$path}" : '').'. It takes a minute or two.');
    }

    /** A one-time link straight into wp-admin. */
    public function wpLogin(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        $r = $this->wpt($service, 'post', "/v1/installations/{$id}/login", [], new \stdClass);
        $link = (string) ($r['data']['link'] ?? '');
        if ($link === '' && in_array($r['status'], [301, 302, 303, 307, 308], true)) {
            $link = (string) ($r['data']['url'] ?? '');
        }

        return str_starts_with($link, 'http') ? $this->buildResult(true, 'OK', ['url' => $link]) : $this->fail($r['message'] ?: 'Could not open wp-admin right now.');
    }

    /** Running and recent background tasks of a site (install, update, backup …). */
    public function wpTasks(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return [];
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}/background-tasks");

        return array_values(array_filter((array) ($r['ok'] ? $r['data'] : []), fn ($t) => is_array($t) && empty($t['hidden'])));
    }

    // ------------------------------------------------------------------
    // Switches
    // ------------------------------------------------------------------

    public function wpToggle(Service $service, int $id, string $feature, bool $on): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        if ($feature === 'virtualpatch') {
            return $this->wptTaskResult(
                $this->wpt($service, 'post', '/v1/features/virtual-patches/protector', [], ['installationsIds' => [$id], 'status' => $on]),
                $on ? 'Turning on vulnerability protection…' : 'Turning off vulnerability protection…'
            );
        }
        if (! isset(self::WP_TOGGLES[$feature])) {
            return $this->fail('Unknown setting.');
        }
        $r = $this->wpt($service, 'put', "/v1/installations/{$id}/".self::WP_TOGGLES[$feature], [], ['status' => $on]);
        $label = ['maintenance' => 'Maintenance mode', 'debug' => 'Debugging', 'indexing' => 'Search engine indexing', 'hotlink' => 'Hotlink protection', 'smart' => 'Smart Updates'][$feature];

        return $r['ok'] ? $this->ok($label.($on ? ' is on.' : ' is off.')) : $this->fail($r['message']);
    }

    public function wpPasswordProtection(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return [];
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}/features/password-protection");

        return $r['ok'] && is_array($r['data']) ? $r['data'] : [];
    }

    public function wpSetPasswordProtection(Service $service, int $id, bool $on, ?string $user = null, ?string $pass = null): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        $body = ['status' => $on];
        if ($on) {
            if (! $user || ! $pass || ! preg_match('/^[a-zA-Z0-9._-]{1,32}$/', $user) || strlen($pass) < 8) {
                return $this->fail('Enter a username (letters, numbers, . _ -) and a password of at least 8 characters.');
            }
            $body['credentials'] = ['username' => $user, 'password' => $pass];
        }
        $r = $this->wpt($service, 'patch', "/v1/installations/{$id}/features/password-protection", [], $body);

        return $r['ok'] ? $this->ok($on ? 'The site now asks visitors for a password.' : 'Password protection is off.') : $this->fail($r['message']);
    }

    public function wpClearCache(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }

        return $this->wptTaskResult($this->wpt($service, 'post', '/v1/cache-cleaner', [], ['installationsIds' => [$id]]), 'Clearing the cache…');
    }

    // ------------------------------------------------------------------
    // Admin account (wp-admin login, email, language)
    // ------------------------------------------------------------------

    public function wpAccount(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return [];
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}/account");
        $a = $r['ok'] && is_array($r['data']) ? $r['data'] : [];
        unset($a['currentPassword']); // never sent to the browser

        return $a;
    }

    public function wpUpdateAccount(Service $service, int $id, array $in): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        $body = array_filter([
            'login' => $in['login'] ?? null,
            'password' => $in['password'] ?? null,
            'email' => $in['email'] ?? null,
            'language' => $in['language'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        if (! $body) {
            return $this->fail('Nothing to change.');
        }
        if (isset($body['email']) && ! filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Enter a valid email address.');
        }
        if (isset($body['password']) && strlen($body['password']) < 8) {
            return $this->fail('The password must have at least 8 characters.');
        }
        $r = $this->wpt($service, 'patch', "/v1/installations/{$id}/account", [], $body);

        return $r['ok'] ? $this->ok('WordPress admin settings saved.') : $this->fail($r['message']);
    }

    // ------------------------------------------------------------------
    // Plugins and themes
    // ------------------------------------------------------------------

    /** $kind: plugins | themes */
    public function wpAssets(Service $service, int $id, string $kind): array
    {
        if (! in_array($kind, ['plugins', 'themes'], true) || ! $this->wpOwnSite($service, $id)) {
            return [];
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}/{$kind}");

        return array_values(array_filter((array) ($r['ok'] ? $r['data'] : []), 'is_array'));
    }

    /** Search wordpress.org. @return array{total:int, items:list<array>} */
    public function wpCatalog(Service $service, int $id, string $kind, string $term, int $page = 1): array
    {
        if (! in_array($kind, ['plugins', 'themes'], true) || ! $this->wpOwnSite($service, $id)) {
            return ['total' => 0, 'items' => []];
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}/features/catalog/{$kind}", [
            'term' => mb_substr(trim($term), 0, 100), 'page' => max(1, $page), 'pageSize' => 12,
        ]);

        return ['total' => (int) ($r['data']['total'] ?? 0), 'items' => array_values((array) ($r['data']['items'] ?? []))];
    }

    public function wpInstallAsset(Service $service, int $id, string $kind, string $slug, bool $activate = true): array
    {
        if (! in_array($kind, ['plugins', 'themes'], true) || ! $this->wpOwnSite($service, $id) || ! self::slugOk($slug)) {
            return $this->fail('That is not a valid '.rtrim($kind, 's').'.');
        }
        $r = $this->wpt($service, 'post', "/v1/installations/{$id}/{$kind}", [], ['slug' => $slug, 'status' => $activate]);
        $title = (string) ($r['data']['title'] ?? $slug);

        return $r['ok'] ? $this->ok("{$title} installed".($activate ? ' and activated.' : '.')) : $this->fail($r['message']);
    }

    /** A .zip the client uploads (max 64 MB). */
    public function wpUploadAsset(Service $service, int $id, string $kind, string $tmpPath, string $name, bool $activate = true): array
    {
        if (! in_array($kind, ['plugins', 'themes'], true) || ! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        if (! preg_match('/\.zip$/i', $name) || ! is_file($tmpPath) || filesize($tmpPath) > 64 * 1024 * 1024) {
            return $this->fail('Upload a .zip file of up to 64 MB.');
        }
        $r = $this->wpt($service, 'post', "/v1/installations/{$id}/features/uploader/{$kind}", [], null, [
            'contents' => fopen($tmpPath, 'r'), 'name' => basename($name), 'status' => $activate,
        ]);

        return $this->wptTaskResult($r, 'Uploading and installing '.basename($name).'…');
    }

    /** Activate / deactivate a plugin; activate a theme (themes can't be deactivated, only replaced). */
    public function wpAssetStatus(Service $service, int $id, string $kind, string $slug, bool $on): array
    {
        $own = collect($this->wpAssets($service, $id, $kind))->firstWhere('slug', $slug);
        if (! $own) {
            return $this->fail('That '.rtrim($kind, 's').' is not installed on this site.');
        }
        if ($kind === 'themes' && ! $on) {
            return $this->fail('Activate another theme instead.');
        }
        $r = $this->wpt($service, 'put', "/v1/installations/{$id}/{$kind}/".rawurlencode($slug).'/status', [], ['status' => $on]);

        return $r['ok'] ? $this->ok(($own['title'] ?? $slug).($on ? ' activated.' : ' deactivated.')) : $this->fail($r['message']);
    }

    public function wpAssetAutoUpdate(Service $service, int $id, string $kind, string $slug, bool $on): array
    {
        $own = collect($this->wpAssets($service, $id, $kind))->firstWhere('slug', $slug);
        if (! $own) {
            return $this->fail('That '.rtrim($kind, 's').' is not installed on this site.');
        }
        $r = $this->wpt($service, 'patch', "/v1/installations/{$id}/{$kind}", [], [['slug' => $slug, 'autoUpdates' => $on]]);

        return $r['ok'] ? $this->ok('Automatic updates for '.($own['title'] ?? $slug).($on ? ' are on.' : ' are off.')) : $this->fail($r['message']);
    }

    public function wpDeleteAssets(Service $service, int $id, string $kind, array $slugs): array
    {
        $installed = collect($this->wpAssets($service, $id, $kind));
        $slugs = array_values(array_unique(array_map('strval', $slugs)));
        if (! $slugs || array_diff($slugs, $installed->pluck('slug')->all())) {
            return $this->fail('Choose '.$kind.' installed on this site.');
        }
        if ($kind === 'themes' && $installed->whereIn('slug', $slugs)->contains(fn ($t) => ! empty($t['status']))) {
            return $this->fail('The active theme can\'t be deleted. Activate another theme first.');
        }
        $r = $this->wpt($service, 'delete', "/v1/installations/{$id}/{$kind}", [], $slugs);

        return $r['ok'] ? $this->ok(count($slugs) === 1 ? 'Deleted.' : count($slugs).' '.$kind.' deleted.') : $this->fail($r['message']);
    }

    // ------------------------------------------------------------------
    // Updates
    // ------------------------------------------------------------------

    public function wpUpdates(Service $service, int $id): ?array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return null;
        }
        $r = $this->wpt($service, 'get', '/v1/updates-checker', ['installationsIds' => [$id]]);

        return $r['ok'] ? (collect((array) $r['data'])->firstWhere('id', $id) ?? null) : null;
    }

    public function wpCheckUpdates(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }

        return $this->wptTaskResult($this->wpt($service, 'post', '/v1/updates-checker', [], ['installationsIds' => [$id]]), 'Checking for updates…');
    }

    /** $core: update WordPress itself; $plugins / $themes: slugs to update. */
    public function wpApplyUpdates(Service $service, int $id, bool $core, array $plugins, array $themes): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        $u = $this->wpUpdates($service, $id) ?? [];
        $canP = collect($u['plugins'] ?? [])->filter(fn ($p) => ($p['availableVersion'] ?? '') !== '')->pluck('slug')->all();
        $canT = collect($u['themes'] ?? [])->filter(fn ($t) => ($t['availableVersion'] ?? '') !== '')->pluck('slug')->all();
        $plugins = array_values(array_intersect(array_map('strval', $plugins), $canP));
        $themes = array_values(array_intersect(array_map('strval', $themes), $canT));
        if (! $core && ! $plugins && ! $themes) {
            return $this->fail('Nothing to update.');
        }
        $r = $this->wpt($service, 'post', '/v1/updater', [], [[
            'installationId' => $id,
            'core' => ['update' => $core, 'restorePoint' => true],
            'plugins' => $plugins,
            'themes' => $themes,
        ]]);

        return $this->wptTaskResult($r, 'Updating… A restore point is made first.');
    }

    public function wpUpdateSettings(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return [];
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}/features/updates/settings");

        return $r['ok'] && is_array($r['data']) ? $r['data'] : [];
    }

    public function wpSaveUpdateSettings(Service $service, int $id, array $in): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        $b = fn ($k) => ! empty($in[$k]);
        $body = [
            'core' => in_array($in['core'] ?? '', ['disabled', 'minor', 'major'], true) ? $in['core'] : 'minor',
            'plugins' => ['forceUpdates' => $b('plugins_all'), 'newlyInstalledUpdates' => $b('plugins_new'), 'updateVulnerable' => $b('plugins_vulnerable'), 'deactivateVulnerable' => $b('plugins_deactivate')],
            'themes' => ['forceUpdates' => $b('themes_all'), 'newlyInstalledUpdates' => $b('themes_new'), 'updateVulnerable' => $b('themes_vulnerable')],
        ];
        if (array_key_exists('safe', $in)) {
            $body['safeUpdate'] = $b('safe');
        }
        $r = $this->wpt($service, 'put', "/v1/installations/{$id}/features/updates/settings", [], $body);

        return $r['ok'] ? $this->ok('Automatic update settings saved.') : $this->fail($r['message']);
    }

    // ------------------------------------------------------------------
    // Security
    // ------------------------------------------------------------------

    /** @return array{measures: list<array>, status: ?array, vulnerabilities: ?array} */
    public function wpSecurity(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return ['measures' => [], 'status' => null, 'vulnerabilities' => null];
        }
        $all = $this->wpt($service, 'get', '/v1/security-measures');
        $st = $this->wpt($service, 'get', '/v1/security-measures/checker', ['installationsIds' => [$id]]);
        $vu = $this->wpt($service, 'get', '/v1/vulnerabilities-checker', ['installationsIds' => [$id]]);
        $status = $st['ok'] ? collect((array) $st['data'])->firstWhere('id', $id) : null;
        $byId = collect($status['securityMeasures'] ?? [])->keyBy('id');

        $measures = [];
        foreach ((array) ($all['ok'] ? $all['data'] : []) as $m) {
            if (! is_array($m) || empty($m['id'])) {
                continue;
            }
            $s = $byId[$m['id']] ?? null;
            if ($s && empty($s['available'])) {
                continue;
            }
            $measures[] = $m + ['applied' => (bool) ($s['status'] ?? false)];
        }

        return ['measures' => $measures, 'status' => $status, 'vulnerabilities' => $vu['ok'] ? collect((array) $vu['data'])->firstWhere('id', $id) : null];
    }

    /** $action: check | apply | revert | vulnerabilities */
    public function wpSecurityAction(Service $service, int $id, string $action, array $measures = []): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        if (in_array($action, ['apply', 'revert'], true)) {
            $known = array_column((array) ($this->wpt($service, 'get', '/v1/security-measures')['data'] ?? []), 'id');
            $measures = array_values(array_intersect(array_map('strval', $measures), $known));
            if (! $measures) {
                return $this->fail('Select at least one security measure.');
            }
        }

        return match ($action) {
            'check' => $this->wptTaskResult($this->wpt($service, 'post', '/v1/security-measures/checker', [], ['installationsIds' => [$id]]), 'Checking security…'),
            'apply' => $this->wptTaskResult($this->wpt($service, 'post', '/v1/security-measures/resolver', [], ['installationsIds' => [$id], 'securityMeasures' => $measures]), 'Applying '.count($measures).' security measure(s)…'),
            'revert' => $this->wptTaskResult($this->wpt($service, 'post', '/v1/security-measures/reverter', [], ['installationsIds' => [$id], 'securityMeasures' => $measures]), 'Reverting '.count($measures).' security measure(s)…'),
            'vulnerabilities' => $this->wptTaskResult($this->wpt($service, 'post', '/v1/vulnerabilities-checker', [], ['installationsIds' => [$id]]), 'Checking for vulnerabilities…'),
            default => $this->fail('Unknown action.'),
        };
    }

    // ------------------------------------------------------------------
    // Backups
    // ------------------------------------------------------------------

    public function wpBackups(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return [];
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}/backups");
        $list = array_values(array_filter((array) ($r['ok'] ? $r['data'] : []), fn ($b) => is_array($b) && ! empty($b['fileName'])));
        usort($list, fn ($a, $b) => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));

        return $list;
    }

    public function wpCreateBackup(Service $service, int $id, string $note = ''): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }

        return $this->wptTaskResult(
            $this->wpt($service, 'post', '/v1/features/backups/creator', [], array_filter(['installationId' => $id, 'description' => mb_substr(trim($note), 0, 200) ?: null, 'backupSettings' => true], fn ($v) => $v !== null)),
            'Backing up the site (files and database)…'
        );
    }

    public function wpRestoreBackup(Service $service, int $id, string $file): array
    {
        if (! in_array($file, array_column($this->wpBackups($service, $id), 'fileName'), true)) {
            return $this->fail('That backup is not part of this site.');
        }

        return $this->wptTaskResult(
            $this->wpt($service, 'post', '/v1/features/backups/restorer', [], ['installationId' => $id, 'fileName' => $file, 'clobber' => true, 'restoreSettings' => true]),
            'Restoring '.$file.'…'
        );
    }

    public function wpDeleteBackups(Service $service, int $id, array $files): array
    {
        $own = array_column($this->wpBackups($service, $id), 'fileName');
        $files = array_values(array_unique(array_map('strval', $files)));
        if (! $files || array_diff($files, $own)) {
            return $this->fail('Choose backups of this site.');
        }
        $r = $this->wpt($service, 'delete', "/v1/installations/{$id}/backups", ['files' => $files], ['installationsIds' => [$id]]);

        return $r['ok'] ? $this->ok(count($files) === 1 ? 'Backup deleted.' : count($files).' backups deleted.') : $this->fail($r['message']);
    }

    // ------------------------------------------------------------------
    // Clone (e.g. a staging copy)
    // ------------------------------------------------------------------

    public function wpCloneStub(Service $service, int $id): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return [];
        }
        $r = $this->wpt($service, 'get', "/v1/installations/{$id}/features/clone/stub");

        return $r['ok'] && is_array($r['data']) ? $r['data'] : [];
    }

    /** $target: "sub" (new subdomain $name.$parent) or "existing" ($existing domain). */
    public function wpClone(Service $service, int $id, array $in): array
    {
        if (! $this->wpOwnSite($service, $id)) {
            return $this->fail('That site is not part of this hosting account.');
        }
        $own = array_merge(array_values($this->accountDomains($service)), array_column($this->subdomains($service), 'full_name'));
        if (($in['target'] ?? '') === 'existing') {
            $d = strtolower((string) ($in['existing'] ?? ''));
            if (! in_array($d, $own, true)) {
                return $this->fail('Choose one of this account\'s domains.');
            }
            $domain = ['existing' => $d];
            $where = $d;
        } else {
            $name = strtolower(trim((string) ($in['name'] ?? '')));
            $parent = strtolower((string) ($in['parent'] ?? ''));
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name) || ! $this->ownsDomain($service, $parent)) {
                return $this->fail('Enter a subdomain name (letters, numbers, dashes) under one of your domains.');
            }
            $domain = ['newSubdomain' => ['name' => $name, 'parentDomain' => $parent]];
            $where = "{$name}.{$parent}";
        }
        $path = trim((string) ($in['path'] ?? ''), ' /');
        if ($path !== '' && (str_contains($path, '..') || ! preg_match('#^[a-zA-Z0-9._/-]{1,200}$#', $path))) {
            return $this->fail('The folder may contain letters, numbers, dots, dashes and slashes only.');
        }
        $stub = $this->wpCloneStub($service, $id);
        $db = (string) ($in['database'] ?? '') ?: (string) ($stub['value']['database']['value']['name']['value'] ?? '');
        if ($db === '' || ! preg_match('/^[a-zA-Z0-9_]{1,64}$/', $db)) {
            return $this->fail('Enter a database name (letters, numbers, underscores).');
        }
        $body = array_filter([
            'installationId' => $id,
            'domain' => $domain,
            'installationPath' => $path === '' ? null : $path,
            'database' => ['name' => $db],
            'copySettings' => true,
        ], fn ($v) => $v !== null);

        unset($this->wptSitesMemo[$service->id]);

        return $this->wptTaskResult($this->wpt($service, 'post', '/v1/cloner', [], $body), "Copying the site to {$where}… Search engines won't index the copy.");
    }

    // ------------------------------------------------------------------
    // Sets (plugin/theme bundles the host prepared in WP Toolkit)
    // ------------------------------------------------------------------

    public function wpSets(Service $service): array
    {
        $r = $this->wpt($service, 'get', '/v1/sets');

        return array_values(array_filter((array) ($r['ok'] ? $r['data'] : []), fn ($s) => is_array($s) && isset($s['id'])));
    }

    public function wpInstallSet(Service $service, int $id, int $setId): array
    {
        if (! $this->wpOwnSite($service, $id) || ! in_array($setId, array_map('intval', array_column($this->wpSets($service), 'id')), true)) {
            return $this->fail('Choose one of the sets offered here.');
        }

        return $this->wptTaskResult($this->wpt($service, 'post', '/v1/sets-installer', [], ['setId' => $setId, 'installationId' => $id]), 'Installing the set…');
    }
}
