<?php

namespace Modules\Servers\CPanelPro;

use App\Models\Server;
use App\Models\Service;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The client portal's hosting tools for cPanel accounts: the same functions
 * (and return shapes) the PNLCS client screens call on the Panelica module.
 *
 * Every call runs through WHM *as the service's own cPanel user* (uapi_cpanel
 * / the API2 passthrough), so cPanel itself confines it to that account. On
 * top of that, every id, domain and path that arrives from the browser is
 * checked against this account's own lists before anything is sent.
 *
 * Ids used on the screens: a domain's id is its name; an email account's is
 * its address; an FTP account's is its login; a DNS record's is
 * "domain|line"; a cron job's is its crontab line; a database user's is its
 * name. Database "groups" are "account" (for the create form) and
 * "db:<name>" (one per database, so "Add user" knows which database).
 */
trait CPanelTools
{
    /** @var array<string, mixed> per-request cache */
    private array $cpCache = [];

    public function hostingFeatures(Service $service): array
    {
        return $this->cpAccount($service) ? ['emails', 'files', 'databases', 'ftp', 'subdomains', 'cron', 'dns', 'backups'] : [];
    }

    // ==================================================================
    // Plumbing
    // ==================================================================

    /** @return array{0: Server, 1: string}|null */
    private function cpAccount(Service $service): ?array
    {
        $server = $service->server;
        $user = trim((string) $service->username);

        return $server && $user !== '' && preg_match('/^[a-z0-9_-]{1,32}$/i', $user) ? [$server, $user] : null;
    }

    /** cPanel API2 through WHM. Returns ['success', 'data' => list, 'message']. */
    protected function api2(Server $server, string $user, string $module, string $func, array $args = []): array
    {
        $res = $this->whmRaw($server, 'cpanel', $args + [
            'cpanel_jsonapi_user' => $user, 'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => $module, 'cpanel_jsonapi_func' => $func,
        ]);
        $cr = $res['json']['cpanelresult'] ?? $res['json']['data']['cpanelresult'] ?? null;
        if (! is_array($cr)) {
            return ['success' => false, 'data' => [], 'message' => $res['message'] ?: 'cPanel did not answer.'];
        }
        $ok = (int) ($cr['event']['result'] ?? 0) === 1 && empty($cr['error']);
        $data = $cr['data'] ?? [];
        // API2 reports per-item failures inside data (result: 0, reason: …).
        if ($ok && is_array($data) && isset($data[0]) && is_array($data[0]) && array_key_exists('result', $data[0]) && (int) $data[0]['result'] === 0) {
            $ok = false;
        }

        return [
            'success' => $ok,
            'data' => is_array($data) ? $data : [],
            'message' => $ok ? 'OK' : (string) ($cr['error'] ?? ($data[0]['reason'] ?? 'cPanel refused the request.')),
        ];
    }

    /** One UAPI call, remembered for the rest of this request (reads only). */
    private function uapiCached(Server $server, string $user, string $module, string $function, array $args = []): array
    {
        $key = $user.'|'.$module.'|'.$function.'|'.json_encode($args);

        return $this->cpCache[$key] ??= $this->uapi($server, $user, $module, $function, $args);
    }

    private function forget(): void
    {
        $this->cpCache = [];
    }

    private function ok(string $message, array $data = []): array
    {
        $this->forget();

        return $this->buildResult(true, $message, $data);
    }

    private function fail(string $message): array
    {
        return $this->buildResult(false, $message);
    }

    /** UAPI errors arrive as a list; the first one is what to show. */
    private function uapiMessage(array $res, string $fallback): string
    {
        $m = trim((string) ($res['message'] ?? ''));

        return $m !== '' && $m !== 'OK' ? $m : $fallback;
    }

    /** cPanel's stats bar: [name => ['used' => int, 'max' => int (-1 = unlimited)]]. */
    private function stats(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'StatsBar', 'get_stats', ['display' => 'emailaccounts|sqldatabases|ftpaccounts|subdomains|addondomains|parkeddomains']);
        $out = [];
        foreach ((array) ($res['data'] ?? []) as $s) {
            $max = $s['_max'] ?? $s['max'] ?? 'unlimited';
            $out[(string) ($s['name'] ?? $s['id'] ?? '')] = [
                'used' => (int) ($s['_count'] ?? $s['count'] ?? 0),
                'max' => is_numeric($max) ? (int) $max : -1,
            ];
        }

        return $out;
    }

    private function policyFor(Service $service, string $stat): array
    {
        $s = $this->stats($service)[$stat] ?? ['used' => 0, 'max' => -1];

        return ['enabled' => $s['max'] !== 0, 'max' => $s['max'], 'used' => $s['used'], 'can_create' => $s['max'] < 0 || $s['used'] < $s['max']];
    }

    // ==================================================================
    // Domains
    // ==================================================================

    /** @return array<string, string> domain => domain (main, addon and parked domains) */
    public function accountDomains(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'DomainInfo', 'list_domains');
        if (! $res['success'] || ! is_array($res['data'])) {
            return [];
        }
        $d = $res['data'];
        $names = array_merge([(string) ($d['main_domain'] ?? '')], (array) ($d['addon_domains'] ?? []), (array) ($d['parked_domains'] ?? []));
        $out = [];
        foreach ($names as $n) {
            $n = strtolower(trim((string) $n));
            if ($n !== '') {
                $out[$n] = $n;
            }
        }

        return $out;
    }

    private function mainDomain(Service $service): string
    {
        return (string) (array_key_first($this->accountDomains($service)) ?? $service->domain ?? '');
    }

    private function ownsDomain(Service $service, ?string $domain): bool
    {
        return $domain !== null && isset($this->accountDomains($service)[strtolower($domain)]);
    }

    // ==================================================================
    // Email
    // ==================================================================

    public function listEmails(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'Email', 'list_pops_with_disk');
        $out = [];
        foreach ((array) ($res['data'] ?? []) as $e) {
            $email = strtolower((string) ($e['email'] ?? ''));
            $domain = strtolower((string) ($e['domain'] ?? substr(strrchr($email, '@') ?: '', 1)));
            if ($email === '' || ! str_contains($email, '@')) {
                continue; // the account's own system mailbox
            }
            $quota = $e['diskquota'] ?? 0;
            $out[] = [
                'id' => $email,
                'email' => $email,
                'domain_id' => $domain,
                'domain' => $domain,
                'quota_mb' => is_numeric($quota) ? (int) $quota : 0,
                'used_mb' => (int) round((float) ($e['diskused'] ?? 0)),
                'status' => ! empty($e['suspended_login']) || ! empty($e['suspended_incoming']) ? 'suspended' : 'active',
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['email'], $b['email']));

        return $out;
    }

    private function ownEmail(Service $service, string $id): ?array
    {
        foreach ($this->listEmails($service) as $e) {
            if ($e['id'] === strtolower($id)) {
                return $e;
            }
        }

        return null;
    }

    public function createEmail(Service $service, string $domainId, string $localPart, string $password, int $quotaMb = 0): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! $this->ownsDomain($service, $domainId)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        $res = $this->uapi($acc[0], $acc[1], 'Email', 'add_pop', [
            'email' => strtolower($localPart), 'domain' => strtolower($domainId), 'password' => $password, 'quota' => max(0, $quotaMb),
        ]);

        return $res['success'] ? $this->ok('Email account created: '.strtolower($localPart).'@'.strtolower($domainId))
            : $this->fail($this->uapiMessage($res, 'Could not create the email account.'));
    }

    public function deleteEmail(Service $service, string $emailId): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! ($e = $this->ownEmail($service, $emailId))) {
            return $this->fail('That email account is not part of this hosting account.');
        }
        [$local, $domain] = explode('@', $e['email'], 2);
        $res = $this->uapi($acc[0], $acc[1], 'Email', 'delete_pop', ['email' => $local, 'domain' => $domain]);

        return $res['success'] ? $this->ok('Email account deleted.') : $this->fail($this->uapiMessage($res, 'Could not delete the email account.'));
    }

    public function changeEmailPassword(Service $service, string $emailId, string $password): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! ($e = $this->ownEmail($service, $emailId))) {
            return $this->fail('That email account is not part of this hosting account.');
        }
        [$local, $domain] = explode('@', $e['email'], 2);
        $res = $this->uapi($acc[0], $acc[1], 'Email', 'passwd_pop', ['email' => $local, 'domain' => $domain, 'password' => $password]);

        return $res['success'] ? $this->ok('Password updated.') : $this->fail($this->uapiMessage($res, 'Could not change the password.'));
    }

    public function webmailUrl(Service $service): ?string
    {
        $host = trim((string) $service->server?->hostname);

        return $host !== '' ? "https://{$host}:2096/" : null;
    }

    public function mailHostname(Service $service): ?string
    {
        $main = $this->mainDomain($service);

        return $main !== '' ? 'mail.'.$main : null;
    }

    public function mailDnsHelp(Service $service): ?array
    {
        return null; // cPanel manages mail DNS and certificates itself (AutoSSL)
    }

    public function retryMailCertificate(Service $service): bool
    {
        return false;
    }

    // ==================================================================
    // Databases (MySQL)
    // ==================================================================

    private function dbPrefix(Service $service): string
    {
        if (! $acc = $this->cpAccount($service)) {
            return '';
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'Mysql', 'get_restrictions');

        return (string) ($res['data']['prefix'] ?? '');
    }

    /** "shop" → "asha_shop" when cPanel uses database prefixes. */
    private function withPrefix(Service $service, string $name): string
    {
        $prefix = $this->dbPrefix($service);

        return $prefix !== '' && ! str_starts_with($name, $prefix) ? $prefix.$name : $name;
    }

    /** @return array<string, list<string>> database => users */
    private function databaseMap(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'Mysql', 'list_databases');
        $out = [];
        foreach ((array) ($res['data'] ?? []) as $d) {
            $name = (string) ($d['database'] ?? '');
            if ($name !== '') {
                $out[$name] = array_values(array_map('strval', (array) ($d['users'] ?? [])));
            }
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    private function databaseUsers(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'Mysql', 'list_users');

        return array_values(array_filter(array_map(fn ($u) => (string) ($u['user'] ?? ''), (array) ($res['data'] ?? []))));
    }

    public function listDatabases(Service $service): array
    {
        if (! $this->cpAccount($service)) {
            return [];
        }
        $main = $this->mainDomain($service) ?: 'this account';

        // "account" is what the create form offers; each database then has
        // its own group so its "Add user" button knows which database it is.
        $groups = [['domain_id' => 'account', 'domain' => $main, 'users' => []]];
        foreach ($this->databaseMap($service) as $db => $users) {
            $rows = array_map(fn ($u) => ['id' => $u, 'username' => $u, 'role' => 'ALL PRIVILEGES', 'is_primary' => false, 'database_name' => $db], $users);
            if ($rows === []) {
                $rows[] = ['id' => '', 'username' => '—', 'role' => 'no users yet', 'is_primary' => true, 'database_name' => $db];
            }
            $groups[] = ['domain_id' => 'db:'.$db, 'domain' => "{$main} · {$db}", 'users' => $rows];
        }

        return $groups;
    }

    private const DB_PRIVILEGES = [
        'read' => 'SELECT,SHOW VIEW',
        'readWrite' => 'SELECT,INSERT,UPDATE,DELETE,CREATE TEMPORARY TABLES,LOCK TABLES,EXECUTE,SHOW VIEW',
        'dbAdmin' => 'ALL PRIVILEGES',
        'dbOwner' => 'ALL PRIVILEGES',
    ];

    public function createDatabase(Service $service, string $domainId, string $dbName, string $dbUser, string $password): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return $this->fail('No cPanel account on this service.');
        }
        [$server, $user] = $acc;
        $db = $this->withPrefix($service, $dbName);
        $dbu = $this->withPrefix($service, $dbUser);

        $r = $this->uapi($server, $user, 'Mysql', 'create_database', ['name' => $db]);
        if (! $r['success']) {
            return $this->fail($this->uapiMessage($r, 'Could not create the database.'));
        }
        if (! in_array($dbu, $this->databaseUsers($service), true)) {
            $r = $this->uapi($server, $user, 'Mysql', 'create_user', ['name' => $dbu, 'password' => $password]);
            if (! $r['success']) {
                $this->forget();

                return $this->fail("Database {$db} was created, but its user could not be: ".$this->uapiMessage($r, 'unknown error').'. Add a user to it below.');
            }
        }
        $this->uapi($server, $user, 'Mysql', 'set_privileges_on_database', ['user' => $dbu, 'database' => $db, 'privileges' => 'ALL PRIVILEGES']);

        return $this->ok("Database {$db} created with user {$dbu}.");
    }

    public function deleteDatabase(Service $service, string $domainId, string $databaseName): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! array_key_exists($databaseName, $this->databaseMap($service))) {
            return $this->fail('That database is not part of this hosting account.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Mysql', 'delete_database', ['name' => $databaseName]);

        return $r['success'] ? $this->ok("Database {$databaseName} deleted.") : $this->fail($this->uapiMessage($r, 'Could not delete the database.'));
    }

    public function createDatabaseUser(Service $service, string $domainId, string $username, string $password, string $role): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return $this->fail('No cPanel account on this service.');
        }
        [$server, $user] = $acc;
        $db = str_starts_with($domainId, 'db:') ? substr($domainId, 3) : '';
        if ($db === '' || ! array_key_exists($db, $this->databaseMap($service))) {
            return $this->fail('Choose the database to add the user to.');
        }
        $dbu = $this->withPrefix($service, $username);

        if (! in_array($dbu, $this->databaseUsers($service), true)) {
            $r = $this->uapi($server, $user, 'Mysql', 'create_user', ['name' => $dbu, 'password' => $password]);
            if (! $r['success']) {
                return $this->fail($this->uapiMessage($r, 'Could not create the database user.'));
            }
        }
        $r = $this->uapi($server, $user, 'Mysql', 'set_privileges_on_database', [
            'user' => $dbu, 'database' => $db, 'privileges' => self::DB_PRIVILEGES[$role] ?? self::DB_PRIVILEGES['readWrite'],
        ]);

        return $r['success'] ? $this->ok("User {$dbu} can now use {$db}.") : $this->fail($this->uapiMessage($r, 'The user was created but could not be given access.'));
    }

    public function deleteDatabaseUser(Service $service, string $userId): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! in_array($userId, $this->databaseUsers($service), true)) {
            return $this->fail('That database user is not part of this hosting account.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Mysql', 'delete_user', ['name' => $userId]);

        return $r['success'] ? $this->ok("Database user {$userId} deleted.") : $this->fail($this->uapiMessage($r, 'Could not delete the database user.'));
    }

    public function changeDatabaseUserPassword(Service $service, string $userId, string $password): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! in_array($userId, $this->databaseUsers($service), true)) {
            return $this->fail('That database user is not part of this hosting account.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Mysql', 'set_password', ['user' => $userId, 'password' => $password]);

        return $r['success'] ? $this->ok('Password updated.') : $this->fail($this->uapiMessage($r, 'Could not change the password.'));
    }

    public function phpMyAdminUrl(Service $service): ?string
    {
        return null; // phpMyAdmin opens from cPanel ("Log in to control panel")
    }

    // ==================================================================
    // FTP
    // ==================================================================

    public function ftpAccounts(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'Ftp', 'list_ftp_with_disk');
        $out = [];
        foreach ((array) ($res['data'] ?? []) as $f) {
            if (($f['type'] ?? 'sub') !== 'sub') {
                continue; // the account's main and anonymous logins are not managed here
            }
            $login = (string) ($f['login'] ?? $f['serverlogin'] ?? '');
            $quota = $f['diskquota'] ?? 0;
            $out[] = [
                'id' => $login,
                'username' => $login,
                'home' => (string) ($f['dir'] ?? $f['homedir'] ?? ''),
                'quota_mb' => is_numeric($quota) ? (int) $quota : 0,
                'used_mb' => (int) round((float) ($f['diskused'] ?? 0)),
                'status' => 'active',
            ];
        }

        return $out;
    }

    public function ftpPolicy(Service $service): array
    {
        $p = $this->policyFor($service, 'ftpaccounts');
        $p['used'] = count($this->ftpAccounts($service));
        $p['can_create'] = $p['max'] < 0 || $p['used'] < $p['max'];

        return $p;
    }

    public function ftpHost(Service $service): ?string
    {
        $h = trim((string) $service->server?->hostname);

        return $h !== '' ? $h : null;
    }

    public function createFtpAccount(Service $service, string $username, string $password, ?string $domain = null, int $quotaMb = 0): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return $this->fail('No cPanel account on this service.');
        }
        $domain = $domain ?: $this->mainDomain($service);
        if (! $this->ownsDomain($service, $domain)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        $name = strtolower($username);
        $r = $this->uapi($acc[0], $acc[1], 'Ftp', 'add_ftp', [
            'user' => $name, 'pass' => $password, 'domain' => strtolower($domain), 'quota' => max(0, $quotaMb), 'homedir' => 'public_html/'.$name,
        ]);

        return $r['success'] ? $this->ok("FTP account {$name}@{$domain} created.") : $this->fail($this->uapiMessage($r, 'Could not create the FTP account.'));
    }

    private function ownFtp(Service $service, string $id): ?array
    {
        foreach ($this->ftpAccounts($service) as $f) {
            if (strcasecmp($f['id'], $id) === 0 && str_contains($f['id'], '@')) {
                return $f;
            }
        }

        return null;
    }

    public function deleteFtpAccount(Service $service, string $id): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! ($f = $this->ownFtp($service, $id))) {
            return $this->fail('That FTP account is not part of this hosting account.');
        }
        [$u, $d] = explode('@', $f['id'], 2);
        $r = $this->uapi($acc[0], $acc[1], 'Ftp', 'delete_ftp', ['user' => $u, 'domain' => $d, 'destroy' => 0]);

        return $r['success'] ? $this->ok('FTP account deleted (its files were kept).') : $this->fail($this->uapiMessage($r, 'Could not delete the FTP account.'));
    }

    public function changeFtpPassword(Service $service, string $id, string $password): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! ($f = $this->ownFtp($service, $id))) {
            return $this->fail('That FTP account is not part of this hosting account.');
        }
        [$u, $d] = explode('@', $f['id'], 2);
        $r = $this->uapi($acc[0], $acc[1], 'Ftp', 'passwd', ['user' => $u, 'domain' => $d, 'pass' => $password]);

        return $r['success'] ? $this->ok('Password updated.') : $this->fail($this->uapiMessage($r, 'Could not change the password.'));
    }

    // ==================================================================
    // Subdomains
    // ==================================================================

    public function subdomains(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'DomainInfo', 'domains_data', ['format' => 'list']);
        $out = [];
        foreach ((array) ($res['data']['sub_domains'] ?? []) as $s) {
            $full = strtolower((string) ($s['domain'] ?? ''));
            if ($full === '') {
                continue;
            }
            $root = strtolower((string) ($s['rootdomain'] ?? substr($full, strpos($full, '.') + 1)));
            $out[] = [
                'id' => $full,
                'name' => (string) ($s['subdomain'] ?? strstr($full, '.', true)),
                'full_name' => $full,
                'domain' => $root,
                'document_root' => (string) ($s['documentroot'] ?? ''),
                'php_version' => (string) ($s['phpversion'] ?? ''),
                'ssl' => true, // cPanel's AutoSSL covers subdomains
                'status' => 'active',
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['full_name'], $b['full_name']));

        return $out;
    }

    public function subdomainPolicy(Service $service): array
    {
        $p = $this->policyFor($service, 'subdomains');
        $p['used'] = count($this->subdomains($service));

        return ['max' => $p['max'], 'used' => $p['used'], 'can_create' => $p['max'] < 0 || $p['used'] < $p['max']];
    }

    public function createSubdomain(Service $service, string $domainId, string $name, ?string $documentRoot = null, ?string $phpVersion = null, bool $ssl = true): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! $this->ownsDomain($service, $domainId)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        $name = strtolower($name);
        $dir = trim((string) $documentRoot) !== '' ? $this->relativeHomePath($service, (string) $documentRoot) : 'public_html/'.$name;
        if ($dir === null) {
            return $this->fail('The document root must be inside your home folder.');
        }
        $r = $this->api2($acc[0], $acc[1], 'SubDomain', 'addsubdomain', [
            'domain' => $name, 'rootdomain' => strtolower($domainId), 'dir' => $dir, 'disallowdot' => 1,
        ]);

        return $r['success'] ? $this->ok("Subdomain {$name}.{$domainId} created. AutoSSL adds its certificate shortly.") : $this->fail($r['message']);
    }

    public function deleteSubdomain(Service $service, string $id): array
    {
        $own = null;
        foreach ($this->subdomains($service) as $s) {
            if ($s['id'] === strtolower($id)) {
                $own = $s;
            }
        }
        if (! ($acc = $this->cpAccount($service)) || ! $own) {
            return $this->fail('That subdomain is not part of this hosting account.');
        }
        $r = $this->api2($acc[0], $acc[1], 'SubDomain', 'delsubdomain', ['domain' => $own['full_name']]);

        return $r['success'] ? $this->ok("Subdomain {$own['full_name']} removed (its files were kept).") : $this->fail($r['message']);
    }

    // ==================================================================
    // DNS (API2 ZoneEdit, one zone at a time)
    // ==================================================================

    public function dnsRecordTypes(): array
    {
        return ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];
    }

    public function dnsRecords(Service $service, ?string $onlyDomainId = null): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $domains = $this->accountDomains($service);
        if ($onlyDomainId !== null) {
            if (! isset($domains[strtolower($onlyDomainId)])) {
                return [];
            }
            $domains = [strtolower($onlyDomainId) => strtolower($onlyDomainId)];
        }

        $order = ['SOA' => 0, 'NS' => 1, 'A' => 2, 'AAAA' => 3, 'CNAME' => 4, 'MX' => 5, 'TXT' => 6, 'SRV' => 7, 'CAA' => 8];
        $out = [];
        foreach ($domains as $domain) {
            $key = 'zone|'.$domain;
            $res = $this->cpCache[$key] ??= $this->api2($acc[0], $acc[1], 'ZoneEdit', 'fetchzone_records', ['domain' => $domain]);
            foreach ((array) $res['data'] as $r) {
                $type = strtoupper((string) ($r['type'] ?? ''));
                if (! isset($r['line']) || $type === '' || in_array($type, [':RAW', '$TTL', 'COMMENT'], true)) {
                    continue;
                }
                $name = self::relativeName((string) ($r['name'] ?? ''), $domain);
                $out[] = [
                    'id' => $domain.'|'.(int) $r['line'],
                    'domain' => $domain,
                    'domain_id' => $domain,
                    'type' => $type,
                    'name' => $name,
                    'content' => self::recordContent($type, $r),
                    'ttl' => isset($r['ttl']) ? (int) $r['ttl'] : null,
                    'priority' => $type === 'MX' ? (int) ($r['preference'] ?? 0) : null,
                    'protected' => self::protectedRecord($type, $name),
                ];
            }
        }
        usort($out, fn ($a, $b) => [$order[$a['type']] ?? 9, $a['name']] <=> [$order[$b['type']] ?? 9, $b['name']]);

        return $out;
    }

    private static function relativeName(string $fqdn, string $domain): string
    {
        $n = strtolower(rtrim($fqdn, '.'));
        if ($n === $domain || $n === '') {
            return '@';
        }

        return str_ends_with($n, '.'.$domain) ? substr($n, 0, -strlen('.'.$domain)) : $n;
    }

    private static function absoluteName(string $name, string $domain): string
    {
        $n = strtolower(trim($name));
        if ($n === '' || $n === '@') {
            return $domain.'.';
        }
        $n = rtrim($n, '.');

        return ($n === $domain || str_ends_with($n, '.'.$domain) ? $n : $n.'.'.$domain).'.';
    }

    private static function recordContent(string $type, array $r): string
    {
        return (string) match ($type) {
            'A', 'AAAA' => $r['address'] ?? $r['record'] ?? '',
            'CNAME' => rtrim((string) ($r['cname'] ?? $r['record'] ?? ''), '.'),
            'MX' => rtrim((string) ($r['exchange'] ?? ''), '.'),
            'NS' => rtrim((string) ($r['nsdname'] ?? $r['record'] ?? ''), '.'),
            'TXT' => $r['txtdata'] ?? $r['record'] ?? '',
            'SOA' => trim(($r['mname'] ?? '').' '.($r['rname'] ?? '').' '.($r['serial'] ?? '')),
            default => $r['record'] ?? '',
        };
    }

    /** The records the site and the zone itself depend on stay read-only here. */
    private static function protectedRecord(string $type, string $name): bool
    {
        return in_array($type, ['SOA', 'NS'], true)
            || (in_array($type, ['A', 'AAAA'], true) && in_array($name, ['@', 'www', 'mail', 'ftp', 'cpanel', 'webmail', 'webdisk', 'whm', 'cpcalendars', 'cpcontacts', 'autodiscover', 'autoconfig'], true));
    }

    private function recordFields(string $type, string $content, ?int $priority): ?array
    {
        $content = trim($content);

        return match ($type) {
            'A' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? ['address' => $content] : null,
            'AAAA' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? ['address' => $content] : null,
            'CNAME' => preg_match('/^[a-z0-9.-]+\.?$/i', $content) ? ['cname' => rtrim($content, '.').'.'] : null,
            'MX' => preg_match('/^[a-z0-9.-]+\.?$/i', $content) ? ['exchange' => rtrim($content, '.').'.', 'preference' => $priority ?? 10] : null,
            'TXT' => $content !== '' ? ['txtdata' => $content] : null,
            default => null,
        };
    }

    public function createDnsRecord(Service $service, string $domainId, string $type, string $name, string $content, ?int $ttl = null, ?int $priority = null): array
    {
        $domain = strtolower($domainId);
        $type = strtoupper($type);
        if (! ($acc = $this->cpAccount($service)) || ! $this->ownsDomain($service, $domain)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        if (! in_array($type, $this->dnsRecordTypes(), true)) {
            return $this->fail("{$type} records can't be added here.");
        }
        $rel = self::relativeName(self::absoluteName($name, $domain), $domain);
        if (self::protectedRecord($type, $rel)) {
            return $this->fail('That record keeps the site and mail online and is managed by the server.');
        }
        if (! $fields = $this->recordFields($type, $content, $priority)) {
            return $this->fail("That value isn't valid for a {$type} record.");
        }
        $r = $this->api2($acc[0], $acc[1], 'ZoneEdit', 'add_zone_record', $fields + [
            'domain' => $domain, 'name' => self::absoluteName($name, $domain), 'type' => $type, 'class' => 'IN', 'ttl' => $ttl ?? 14400,
        ]);

        return $r['success'] ? $this->ok("{$type} record added.") : $this->fail($r['message']);
    }

    private function ownRecord(Service $service, string $id): ?array
    {
        [$domain] = explode('|', $id, 2) + [''];
        foreach ($this->dnsRecords($service, $domain) as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    }

    public function updateDnsRecord(Service $service, string $id, string $name, string $content, ?int $ttl = null, ?int $priority = null): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! ($rec = $this->ownRecord($service, $id))) {
            return $this->fail('That record is not part of this hosting account.');
        }
        $rel = self::relativeName(self::absoluteName($name, $rec['domain']), $rec['domain']);
        if ($rec['protected'] || self::protectedRecord($rec['type'], $rel)) {
            return $this->fail('That record keeps the site and mail online and is managed by the server.');
        }
        if (! $fields = $this->recordFields($rec['type'], $content, $priority)) {
            return $this->fail("That value isn't valid for a {$rec['type']} record.");
        }
        $line = (int) explode('|', $id, 2)[1];
        $r = $this->api2($acc[0], $acc[1], 'ZoneEdit', 'edit_zone_record', $fields + [
            'domain' => $rec['domain'], 'line' => $line, 'name' => self::absoluteName($name, $rec['domain']),
            'type' => $rec['type'], 'class' => 'IN', 'ttl' => $ttl ?? ($rec['ttl'] ?? 14400),
        ]);

        return $r['success'] ? $this->ok('Record updated.') : $this->fail($r['message']);
    }

    public function deleteDnsRecord(Service $service, string $id): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! ($rec = $this->ownRecord($service, $id))) {
            return $this->fail('That record is not part of this hosting account.');
        }
        if ($rec['protected']) {
            return $this->fail('That record keeps the site and mail online and is managed by the server.');
        }
        $r = $this->api2($acc[0], $acc[1], 'ZoneEdit', 'remove_zone_record', ['domain' => $rec['domain'], 'line' => (int) explode('|', $id, 2)[1]]);

        return $r['success'] ? $this->ok('Record deleted.') : $this->fail($r['message']);
    }

    // ==================================================================
    // Cron (API2)
    // ==================================================================

    public function cronJobs(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->cpCache['cron'] ??= $this->api2($acc[0], $acc[1], 'Cron', 'listcron');
        $main = $this->mainDomain($service);
        $out = [];
        foreach ((array) $res['data'] as $c) {
            if (! isset($c['command']) || ! isset($c['line'])) {
                continue; // variables (MAILTO=…) and comments
            }
            $parts = [(string) ($c['minute'] ?? '*'), (string) ($c['hour'] ?? '*'), (string) ($c['day'] ?? '*'), (string) ($c['month'] ?? '*'), (string) ($c['weekday'] ?? '*')];
            $out[] = [
                'id' => (string) $c['line'],
                'task_name' => mb_strimwidth((string) $c['command'], 0, 60, '…'),
                'command' => (string) $c['command'],
                'schedule' => implode(' ', $parts),
                'minute' => $parts[0], 'hour' => $parts[1], 'day_of_month' => $parts[2], 'month' => $parts[3], 'day_of_week' => $parts[4],
                'enabled' => true,
                'domain_id' => $main,
                'domain' => $main,
                'last_run' => null,
                'next_run' => null,
            ];
        }

        return $out;
    }

    public function cronPolicy(Service $service): array
    {
        $used = count($this->cronJobs($service));

        return ['enabled' => true, 'max' => -1, 'used' => $used, 'can_create' => true];
    }

    public function createCronJob(Service $service, string $domainId, string $taskName, string $command, array $schedule = [], int $timeoutSeconds = 0, bool $emailOnError = false, string $emailRecipient = ''): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return $this->fail('No cPanel account on this service.');
        }
        $f = [];
        foreach (['minute' => 'minute', 'hour' => 'hour', 'day_of_month' => 'day', 'month' => 'month', 'day_of_week' => 'weekday'] as $from => $to) {
            $v = trim((string) ($schedule[$from] ?? '*'));
            if (! preg_match('#^[0-9*,/\-]+$#', $v)) {
                return $this->fail('That schedule is not valid.');
            }
            $f[$to] = $v;
        }
        $command = trim(str_replace(["\r", "\n"], ' ', $command));
        $r = $this->api2($acc[0], $acc[1], 'Cron', 'add_line', $f + ['command' => $command]);

        return $r['success'] ? $this->ok('Cron job added.') : $this->fail($r['message']);
    }

    public function deleteCronJob(Service $service, string $id): array
    {
        $own = collect($this->cronJobs($service))->firstWhere('id', $id);
        if (! ($acc = $this->cpAccount($service)) || ! $own) {
            return $this->fail('That cron job is not part of this hosting account.');
        }
        $r = $this->api2($acc[0], $acc[1], 'Cron', 'remove_line', ['line' => (int) $id]);

        return $r['success'] ? $this->ok('Cron job deleted.') : $this->fail($r['message']);
    }

    public function toggleCronJob(Service $service, string $id): array
    {
        return $this->fail('cPanel can\'t pause a cron job. Delete it, and add it again later.');
    }

    public function runCronJob(Service $service, string $id): array
    {
        return $this->fail('cPanel can\'t run a cron job on demand. It runs on its schedule.');
    }

    // ==================================================================
    // Backups (full account backups in the home folder)
    // ==================================================================

    public function backups(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $res = $this->uapiCached($acc[0], $acc[1], 'Fileman', 'list_files', ['dir' => $this->home($service), 'types' => 'file', 'show_hidden' => 0]);
        $out = [];
        foreach ((array) ($res['data'] ?? []) as $f) {
            $name = (string) ($f['file'] ?? '');
            if (! preg_match('/^backup-[0-9.\-_]+.*\.tar\.gz$/', $name)) {
                continue;
            }
            $out[] = [
                'id' => $name, 'filename' => $name, 'name' => $name,
                'size_mb' => round(((float) ($f['size'] ?? 0)) / 1048576, 1),
                'domains' => array_values($this->accountDomains($service)),
                'created_at' => isset($f['mtime']) ? date('Y-m-d H:i', (int) $f['mtime']) : '',
                'status' => 'completed', 'type' => 'full', 'encrypted' => false,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    public function backupPolicy(Service $service): array
    {
        return ['enabled' => true, 'count' => count($this->backups($service)), 'can_create' => true];
    }

    public function createBackup(Service $service, ?string $domainId = null, string $name = ''): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return $this->fail('No cPanel account on this service.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Backup', 'fullbackup_to_homedir', ['email' => (string) $service->client?->email]);

        return $r['success'] ? $this->ok('Backup started. It appears here when finished (usually a few minutes; cPanel emails you too).')
            : $this->fail($this->uapiMessage($r, 'Could not start the backup.'));
    }

    public function deleteBackup(Service $service, string $filename): array
    {
        $own = collect($this->backups($service))->firstWhere('filename', $filename);
        if (! ($acc = $this->cpAccount($service)) || ! $own) {
            return $this->fail('That backup is not part of this hosting account.');
        }
        $r = $this->api2($acc[0], $acc[1], 'Fileman', 'fileop', ['op' => 'unlink', 'sourcefiles' => $this->home($service).'/'.$filename, 'doubledecode' => 0]);

        return $r['success'] ? $this->ok('Backup deleted.') : $this->fail($r['message']);
    }

    // ==================================================================
    // Files
    // ==================================================================

    private function home(Service $service): string
    {
        return '/home/'.trim((string) $service->username);
    }

    /** An absolute path inside the home folder, or null if it would leave it. */
    private function safePath(Service $service, ?string $path): ?string
    {
        $home = $this->home($service);
        $path = trim(str_replace('\\', '/', (string) $path));
        if ($path === '' || $path === '~') {
            return $home;
        }
        if (! str_starts_with($path, '/')) {
            $path = $home.'/'.$path;
        }
        if (str_contains($path, "\0")) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $seg;
        }
        $norm = '/'.implode('/', $parts);

        return $norm === $home || str_starts_with($norm, $home.'/') ? $norm : null;
    }

    private function relativeHomePath(Service $service, string $path): ?string
    {
        $abs = $this->safePath($service, $path);

        return $abs === null ? null : ltrim(substr($abs, strlen($this->home($service))), '/');
    }

    private static function validName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && ! preg_match('#[/\\\\\0]#', $name) && strlen($name) <= 255;
    }

    public function listFiles(Service $service, ?string $path = null): array
    {
        $home = $this->home($service);
        $dir = $this->safePath($service, $path);
        if (! ($acc = $this->cpAccount($service)) || $dir === null) {
            return ['ok' => false, 'path' => $home, 'home' => $home, 'entries' => [], 'message' => 'That folder is outside your home folder.'];
        }
        $res = $this->uapi($acc[0], $acc[1], 'Fileman', 'list_files', ['dir' => $dir, 'show_hidden' => 1, 'include_permissions' => 1, 'include_mime' => 0]);
        if (! $res['success']) {
            return ['ok' => false, 'path' => $dir, 'home' => $home, 'entries' => [], 'message' => $this->uapiMessage($res, 'Could not open that folder.')];
        }

        $entries = [];
        foreach ((array) $res['data'] as $f) {
            $name = (string) ($f['file'] ?? '');
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $isDir = in_array($f['type'] ?? '', ['dir', 'directory'], true);
            $size = (int) ($f['size'] ?? 0);
            $mode = (string) ($f['nicemode'] ?? $f['mode'] ?? '');
            $entries[] = [
                'name' => $name,
                'path' => rtrim($dir, '/').'/'.$name,
                'type' => $isDir ? 'folder' : 'file',
                'size' => $size,
                'size_formatted' => $isDir ? '' : (string) ($f['humansize'] ?? self::humanSize($size)),
                'modified_str' => isset($f['mtime']) ? date('Y-m-d H:i', (int) $f['mtime']) : '',
                'permissions_octal' => preg_match('/^\d{3,4}$/', $mode) ? substr($mode, -3) : $mode,
                'extension' => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            ];
        }
        usort($entries, fn ($a, $b) => [$a['type'] !== 'folder', strtolower($a['name'])] <=> [$b['type'] !== 'folder', strtolower($b['name'])]);

        return ['ok' => true, 'path' => $dir, 'home' => $home, 'entries' => $entries, 'message' => ''];
    }

    private static function humanSize(int $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $u) {
            if ($b < 1024) {
                return ($u === 'B' ? $b : round($b, 1)).' '.$u;
            }
            $b /= 1024;
        }

        return round($b, 1).' TB';
    }

    public function readFile(Service $service, string $path): array
    {
        $abs = $this->safePath($service, $path);
        if (! ($acc = $this->cpAccount($service)) || $abs === null || $abs === $this->home($service)) {
            return $this->fail('That file is outside your home folder.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Fileman', 'get_file_content', ['dir' => dirname($abs), 'file' => basename($abs)]);
        if (! $r['success']) {
            return $this->fail($this->uapiMessage($r, 'Could not read that file.'));
        }

        return $this->buildResult(true, 'ok', ['content' => (string) ($r['data']['content'] ?? ''), 'path' => $abs]);
    }

    public function writeFile(Service $service, string $path, string $content): array
    {
        $abs = $this->safePath($service, $path);
        if (! ($acc = $this->cpAccount($service)) || $abs === null || $abs === $this->home($service)) {
            return $this->fail('That file is outside your home folder.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Fileman', 'save_file_content', ['dir' => dirname($abs), 'file' => basename($abs), 'content' => $content]);

        return $r['success'] ? $this->ok('File saved.') : $this->fail($this->uapiMessage($r, 'Could not save the file.'));
    }

    public function createEntry(Service $service, string $path, string $name, string $type, string $content = ''): array
    {
        $dir = $this->safePath($service, $path);
        if (! ($acc = $this->cpAccount($service)) || $dir === null || ! self::validName($name)) {
            return $this->fail('That name or folder is not allowed.');
        }
        $r = $type === 'folder'
            ? $this->api2($acc[0], $acc[1], 'Fileman', 'mkdir', ['path' => $dir, 'name' => $name])
            : $this->uapi($acc[0], $acc[1], 'Fileman', 'save_file_content', ['dir' => $dir, 'file' => $name, 'content' => $content]);

        return $r['success'] ? $this->ok($type === 'folder' ? 'Folder created.' : 'File created.') : $this->fail($this->uapiMessage($r, 'Could not create it.'));
    }

    public function renameEntry(Service $service, string $path, string $newName): array
    {
        $abs = $this->safePath($service, $path);
        if (! ($acc = $this->cpAccount($service)) || $abs === null || $abs === $this->home($service) || ! self::validName($newName)) {
            return $this->fail('That name or file is not allowed.');
        }
        $r = $this->api2($acc[0], $acc[1], 'Fileman', 'fileop', ['op' => 'rename', 'sourcefiles' => $abs, 'destfiles' => dirname($abs).'/'.$newName, 'doubledecode' => 0]);

        return $r['success'] ? $this->ok('Renamed.') : $this->fail($r['message']);
    }

    public function deleteEntries(Service $service, array $paths, bool $permanent = false): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return $this->fail('No cPanel account on this service.');
        }
        $abs = [];
        foreach ($paths as $p) {
            $a = $this->safePath($service, (string) $p);
            if ($a === null || $a === $this->home($service) || str_contains($a, ',')) {
                return $this->fail('One of those items is outside your home folder.');
            }
            $abs[] = $a;
        }
        if (! $abs) {
            return $this->fail('Nothing selected.');
        }
        // "trash" moves to ~/.trash (recoverable from cPanel's File Manager).
        $r = $this->api2($acc[0], $acc[1], 'Fileman', 'fileop', ['op' => $permanent ? 'unlink' : 'trash', 'sourcefiles' => implode(',', $abs), 'doubledecode' => 0]);

        return $r['success'] ? $this->ok(count($abs) === 1 ? 'Deleted (moved to the trash).' : count($abs).' items deleted (moved to the trash).') : $this->fail($r['message']);
    }

    /**
     * A signed-in cPanel session for this account (WHM create_user_session),
     * for the two things the JSON APIs can't carry: file upload and download.
     *
     * @return array{0: string, 1: CookieJar}|null [https://host:2083/cpsessNNN, cookies]
     */
    private function cpanelSession(Server $server, string $user): ?array
    {
        $res = $this->whm($server, 'create_user_session', ['user' => $user, 'service' => 'cpaneld']);
        $url = (string) ($res['raw']['url'] ?? '');
        if (! $res['success'] || ! preg_match('#^(https://[^/]+)(/cpsess\d+)/#', $url, $m)) {
            return null;
        }
        $jar = new CookieJar;
        try {
            Http::withoutVerifying()->withOptions(['cookies' => $jar])->timeout(20)->get($url);
        } catch (\Throwable $e) {
            Log::warning('CPanelPro: cPanel session login failed: '.$e->getMessage());

            return null;
        }

        return [$m[1].$m[2], $jar];
    }

    public function uploadFile(Service $service, string $targetPath, string $contents, string $filename): array
    {
        $dir = $this->safePath($service, $targetPath);
        $filename = basename(str_replace('\\', '/', $filename));
        if (! ($acc = $this->cpAccount($service)) || $dir === null || ! self::validName($filename)) {
            return $this->fail('That file or folder is not allowed.');
        }
        if (! $session = $this->cpanelSession($acc[0], $acc[1])) {
            return $this->fail('Could not open a cPanel session for the upload.');
        }
        [$base, $jar] = $session;
        try {
            $resp = Http::withoutVerifying()->withOptions(['cookies' => $jar])->timeout(300)
                ->attach('file-1', $contents, $filename)
                ->post($base.'/execute/Fileman/upload_files', ['dir' => $dir, 'overwrite' => 1]);
        } catch (\Throwable $e) {
            return $this->fail('The upload did not reach the server.');
        }
        $ok = $resp->successful() && (int) ($resp->json('status') ?? 0) === 1 && (int) ($resp->json('data.succeeded') ?? 1) > 0;

        return $ok ? $this->ok("Uploaded {$filename}.") : $this->fail((string) ($resp->json('errors.0') ?? 'The upload was refused.'));
    }

    public function downloadFile(Service $service, string $path): ?Response
    {
        $abs = $this->safePath($service, $path);
        if (! ($acc = $this->cpAccount($service)) || $abs === null || $abs === $this->home($service)) {
            return null;
        }
        if (! $session = $this->cpanelSession($acc[0], $acc[1])) {
            return null;
        }
        [$base, $jar] = $session;
        try {
            $resp = Http::withoutVerifying()->withOptions(['cookies' => $jar])->timeout(300)
                ->get($base.'/download', ['skipencode' => 1, 'file' => $abs]);
        } catch (\Throwable) {
            return null;
        }

        return $resp->successful() ? $resp : null;
    }
}
