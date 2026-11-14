<?php

namespace Modules\Servers\CPanelPro;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Modules\Servers\CPanel\CPanelModule;

/**
 * cPanel/WHM Pro: the built-in cPanel/WHM module (create, suspend, unsuspend,
 * terminate, change password / package, usage sync, packages, test) with:
 *
 *   ssoLogin()        the client's "Log in to control panel" button, and the
 *                     staff "Log in to cPanel as this client" link
 *                     (WHM create_user_session, service cpaneld: a one-time,
 *                     short-lived link, no password)
 *   liveUsage()       disk, bandwidth, email / database / FTP counts and the
 *                     account's domains, live from WHM, on the client's page
 *   operatorLogin()   one-click WHM login for staff (service whostmgrd)
 *   adminAreaPanel()  those staff links on the admin service page
 *
 * Everything is read and signed in with the server's own WHM credentials and
 * always for the service's own cPanel username, never another account.
 */
class CPanelProModule extends CPanelModule
{
    use CPanelTools;

    public function getModuleName(): string
    {
        return 'cpanelpro';
    }

    // ------------------------------------------------------------------
    // WHM API (the parent keeps its helpers private, so a copy lives here)
    // ------------------------------------------------------------------

    protected function whm(Server $server, string $function, array $params = [], int $timeout = 20): array
    {
        $params['api.version'] = 1;
        $port = $server->port ?: 2087;
        $url = "https://{$this->serverHost($server)}:{$port}/json-api/{$function}";

        $user = (string) ($server->username ?: 'root');
        $token = trim((string) $server->access_hash);
        $auth = $token !== '' ? "whm {$user}:{$token}" : 'Basic '.base64_encode($user.':'.(string) $server->password);

        try {
            $resp = Http::withHeaders(['Authorization' => $auth])
                ->withoutVerifying()   // same as the built-in module: WHM often runs a self-signed certificate
                ->timeout($timeout)->connectTimeout(min(10, $timeout))
                ->asForm()
                ->post($url, $params);
        } catch (\Throwable $e) {
            Log::warning("CPanelPro::{$function} could not reach {$server->hostname}: ".$e->getMessage());

            return ['success' => false, 'message' => 'Could not reach the WHM server.', 'raw' => []];
        }

        if (! $resp->successful()) {
            Log::warning("CPanelPro::{$function} HTTP {$resp->status()}", ['body' => mb_substr($resp->body(), 0, 500)]);

            return ['success' => false, 'message' => "WHM answered HTTP {$resp->status()}", 'raw' => []];
        }

        $json = $resp->json() ?? [];
        $ok = (int) ($json['metadata']['result'] ?? $json['result'] ?? 0) === 1;

        return [
            'success' => $ok,
            'message' => (string) ($json['metadata']['reason'] ?? $json['reason'] ?? ($ok ? 'OK' : 'WHM API call failed')),
            'raw' => $json['data'] ?? $json,
        ];
    }

    /** A WHM call without the API1 wrapper (cPanel API2 passthrough answers {"cpanelresult": …}). */
    protected function whmRaw(Server $server, string $function, array $params = [], int $timeout = 30): array
    {
        $port = $server->port ?: 2087;
        $url = "https://{$this->serverHost($server)}:{$port}/json-api/{$function}";
        $user = (string) ($server->username ?: 'root');
        $token = trim((string) $server->access_hash);
        $auth = $token !== '' ? "whm {$user}:{$token}" : 'Basic '.base64_encode($user.':'.(string) $server->password);

        try {
            $resp = Http::withHeaders(['Authorization' => $auth])->withoutVerifying()
                ->timeout($timeout)->connectTimeout(min(10, $timeout))->asForm()->post($url, $params);
        } catch (\Throwable $e) {
            Log::warning("CPanelPro::{$function} could not reach {$server->hostname}: ".$e->getMessage());

            return ['success' => false, 'json' => [], 'message' => 'Could not reach the WHM server.'];
        }
        if (! $resp->successful()) {
            return ['success' => false, 'json' => [], 'message' => "WHM answered HTTP {$resp->status()}"];
        }

        return ['success' => true, 'json' => (array) ($resp->json() ?? []), 'message' => ''];
    }

    /** A cPanel UAPI call for one account, through WHM (uapi_cpanel). */
    protected function uapi(Server $server, string $user, string $module, string $function, array $args = []): array
    {
        $res = $this->whm($server, 'uapi_cpanel', $args + [
            'cpanel.module' => $module, 'cpanel.function' => $function, 'cpanel.user' => $user,
        ]);
        $uapi = $res['raw']['uapi'] ?? null;
        if (! $res['success'] || ! is_array($uapi)) {
            return ['success' => false, 'data' => null, 'message' => $res['message']];
        }

        return [
            'success' => (int) ($uapi['status'] ?? 0) === 1,
            'data' => $uapi['data'] ?? null,
            'message' => implode(' ', (array) ($uapi['errors'] ?? [])) ?: 'OK',
        ];
    }

    /** The service's server and cPanel username, or null when there is no account to act on. */
    private function account(Service $service): ?array
    {
        $server = $service->server; // never pick one: reading must not bind a server
        $user = trim((string) $service->username);

        return $server && $user !== '' ? [$server, $user] : null;
    }

    // ------------------------------------------------------------------
    // Client: "Log in to control panel"
    // ------------------------------------------------------------------

    public function ssoLogin(Service $service): array
    {
        if (! $acc = $this->account($service)) {
            return $this->buildResult(false, 'This service has no cPanel account yet.');
        }
        [$server, $user] = $acc;

        $res = $this->whm($server, 'create_user_session', ['user' => $user, 'service' => 'cpaneld']);
        $url = (string) ($res['raw']['url'] ?? '');

        if (! $res['success'] || $url === '') {
            Log::warning("CPanelPro: cPanel login link for {$user} on {$server->hostname} failed: {$res['message']}");

            return $this->buildResult(false, 'Could not open cPanel right now. Please try again in a minute.');
        }

        return $this->buildResult(true, 'cPanel login link issued.', ['url' => $url]);
    }

    // ------------------------------------------------------------------
    // Client: live usage strip
    // ------------------------------------------------------------------

    /**
     * Shape read by the client service page:
     * ['available', 'disk' => [used_mb, quota_mb], 'bandwidth' => [used_mb, limit_mb],
     *  'counts' => [...], 'domains' => [['name' => ...]], 'cpu' => null, 'ram' => null]
     */
    public function liveUsage(Service $service): array
    {
        if (! $acc = $this->account($service)) {
            return ['available' => false];
        }
        [$server, $user] = $acc;

        $out = ['available' => false, 'disk' => null, 'bandwidth' => null, 'counts' => null, 'cpu' => null, 'ram' => null, 'domains' => []];

        // Disk: accountsummary gives "1234M" / "unlimited".
        $sum = $this->whm($server, 'accountsummary', ['user' => $user]);
        $acct = $sum['raw']['acct'][0] ?? null;
        if ($sum['success'] && is_array($acct)) {
            $out['available'] = true;
            $out['disk'] = [
                'used_mb' => self::megabytes($acct['diskused'] ?? 0),
                'quota_mb' => self::megabytes($acct['disklimit'] ?? 0),
            ];
        }

        // Bandwidth this month.
        $bw = $this->whm($server, 'showbw', ['searchtype' => 'user', 'search' => $user]);
        $row = $bw['raw']['acct'][0] ?? null;
        if ($bw['success'] && is_array($row)) {
            $out['available'] = true;
            $limit = $row['limit'] ?? 0;
            $out['bandwidth'] = [
                'used_mb' => (int) round(((float) ($row['totalbytes'] ?? 0)) / 1048576),
                'limit_mb' => is_numeric($limit) ? (int) round(((float) $limit) / 1048576) : 0,
            ];
        }

        // Counts, as cPanel's own stats bar shows them.
        $stats = $this->uapi($server, $user, 'StatsBar', 'get_stats', [
            'display' => 'emailaccounts|sqldatabases|ftpaccounts|addondomains|subdomains|parkeddomains',
        ]);
        if ($stats['success'] && is_array($stats['data'])) {
            $c = [];
            foreach ($stats['data'] as $s) {
                $c[(string) ($s['name'] ?? $s['id'] ?? '')] = (int) ($s['_count'] ?? $s['count'] ?? 0);
            }
            $out['counts'] = [
                'emails' => $c['emailaccounts'] ?? 0,
                'databases' => $c['sqldatabases'] ?? ($c['mysqldatabases'] ?? 0),
                'ftp' => $c['ftpaccounts'] ?? 0,
                'domains' => 1 + ($c['addondomains'] ?? 0) + ($c['parkeddomains'] ?? 0),
                'subdomains' => $c['subdomains'] ?? 0,
            ];
        }

        // The account's domains.
        $dom = $this->uapi($server, $user, 'DomainInfo', 'list_domains');
        if ($dom['success'] && is_array($dom['data'])) {
            $d = $dom['data'];
            $names = array_merge([(string) ($d['main_domain'] ?? '')], (array) ($d['addon_domains'] ?? []), (array) ($d['parked_domains'] ?? []));
            $out['domains'] = array_values(array_map(fn ($n) => ['name' => (string) $n], array_filter(array_unique($names))));
        } elseif ($service->domain) {
            $out['domains'] = [['name' => (string) $service->domain]];
        }

        return $out;
    }

    /** "1234M", "1.5G", "512K", "unlimited", 1234 → megabytes (0 = unlimited / unknown). */
    public static function megabytes(mixed $v): int
    {
        if (is_numeric($v)) {
            return (int) round((float) $v);
        }
        if (! is_string($v) || ! preg_match('/^\s*([\d.]+)\s*([KMGT]?)/i', $v, $m)) {
            return 0;
        }
        $n = (float) $m[1];

        return (int) round(match (strtoupper($m[2])) {
            'K' => $n / 1024, 'G' => $n * 1024, 'T' => $n * 1048576, default => $n,
        });
    }

    // ------------------------------------------------------------------
    // Staff
    // ------------------------------------------------------------------

    /** One-click WHM login as the server's own WHM user (from the server record). */
    public function operatorLogin(Server $server): array
    {
        $user = (string) ($server->username ?: 'root');
        $res = $this->whm($server, 'create_user_session', ['user' => $user, 'service' => 'whostmgrd']);
        $url = (string) ($res['raw']['url'] ?? '');

        if (! $res['success'] || $url === '') {
            return ['success' => false, 'message' => 'WHM refused the login link: '.$res['message']];
        }

        return ['success' => true, 'message' => 'OK', 'url' => $url];
    }

    /** The links shown on the staff service page. */
    public function adminAreaPanel(Service $service): ?Htmlable
    {
        if (! $service->server_id || trim((string) $service->username) === '') {
            return null;
        }

        return new HtmlString(view('cpanelpro::admin-panel', ['service' => $service])->render());
    }
}
