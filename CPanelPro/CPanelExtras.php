<?php

namespace Modules\Servers\CPanelPro;

use App\Models\Service;

/**
 * cPanel features PNLCS has no screen of its own for, used by the module's
 * own client pages (ExtrasController): SSL / HTTPS, PHP, email forwarders /
 * autoresponders / catch-all, addon domains and aliases, the error log, the
 * IP blocker, and one-click phpMyAdmin / webmail.
 *
 * Same rules as CPanelTools: everything runs as the service's own cPanel
 * user, and every domain / address / IP from the browser is checked first.
 */
trait CPanelExtras
{
    // ------------------------------------------------------------------
    // One-click logins
    // ------------------------------------------------------------------

    /** cPanel straight into phpMyAdmin. */
    public function phpMyAdminLogin(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return $this->fail('No cPanel account on this service.');
        }
        $r = $this->whm($acc[0], 'create_user_session', ['user' => $acc[1], 'service' => 'cpaneld', 'app' => 'Database_phpMyAdmin']);
        $url = (string) ($r['raw']['url'] ?? '');

        return $r['success'] && $url !== '' ? $this->buildResult(true, 'OK', ['url' => $url]) : $this->fail('Could not open phpMyAdmin right now.');
    }

    /** Webmail, signed in as one of the account's own mailboxes. */
    public function webmailLogin(Service $service, string $email): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! $this->ownEmail($service, $email)) {
            return $this->fail('That mailbox is not part of this hosting account.');
        }
        $r = $this->whm($acc[0], 'create_user_session', ['user' => strtolower($email), 'service' => 'webmaild']);
        $url = (string) ($r['raw']['url'] ?? '');

        return $r['success'] && $url !== '' ? $this->buildResult(true, 'OK', ['url' => $url]) : $this->fail('Could not open webmail right now.');
    }

    // ------------------------------------------------------------------
    // SSL / HTTPS
    // ------------------------------------------------------------------

    /** @return list<array{domain:string, has_cert:bool, issuer:string, expires:?string, days_left:?int, self_signed:bool, redirect:?bool}> */
    public function sslStatus(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $certs = [];
        $r = $this->uapi($acc[0], $acc[1], 'SSL', 'installed_hosts');
        foreach ((array) ($r['data'] ?? []) as $h) {
            $cert = (array) ($h['certificate'] ?? []);
            $names = array_map('strtolower', (array) ($cert['domains'] ?? []));
            $names[] = strtolower((string) ($h['servername'] ?? ''));
            foreach (array_filter(array_unique($names)) as $n) {
                $certs[$n] = $cert;
            }
        }

        $redirects = [];
        $dd = $this->uapiCached($acc[0], $acc[1], 'DomainInfo', 'domains_data', ['format' => 'list']);
        foreach (array_merge([(array) ($dd['data']['main_domain'] ?? [])], (array) ($dd['data']['addon_domains'] ?? []), (array) ($dd['data']['sub_domains'] ?? [])) as $d) {
            if (! empty($d['domain']) && array_key_exists('ssl_redirect', $d)) {
                $redirects[strtolower($d['domain'])] = (bool) $d['ssl_redirect'];
            }
        }

        $out = [];
        $domains = array_merge(array_values($this->accountDomains($service)), array_column($this->subdomains($service), 'full_name'));
        foreach (array_unique($domains) as $domain) {
            $c = $certs[$domain] ?? null;
            $notAfter = $c ? (int) ($c['not_after'] ?? 0) : 0;
            $issuer = $c ? (string) ($c['issuer.organizationName'] ?? $c['issuer']['organizationName'] ?? $c['issuer_text'] ?? '') : '';
            $out[] = [
                'domain' => $domain,
                'has_cert' => $c !== null,
                'issuer' => $issuer,
                'expires' => $notAfter ? date('Y-m-d', $notAfter) : null,
                'days_left' => $notAfter ? (int) floor(($notAfter - time()) / 86400) : null,
                'self_signed' => $c !== null && ! empty($c['is_self_signed']),
                'redirect' => $redirects[$domain] ?? null,
            ];
        }

        return $out;
    }

    public function runAutoSsl(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return $this->fail('No cPanel account on this service.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'SSL', 'start_autossl_check');

        return $r['success'] ? $this->ok('AutoSSL check started. New certificates usually appear within a few minutes.')
            : $this->fail($this->uapiMessage($r, 'AutoSSL could not be started (it may be off on this server).'));
    }

    public function setHttpsRedirect(Service $service, string $domain, bool $on): array
    {
        $domain = strtolower($domain);
        $own = array_merge(array_values($this->accountDomains($service)), array_column($this->subdomains($service), 'full_name'));
        if (! ($acc = $this->cpAccount($service)) || ! in_array($domain, $own, true)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'SSL', 'toggle_ssl_redirect_for_domains', ['domains' => $domain, 'state' => $on ? 1 : 0]);

        return $r['success'] ? $this->ok($on ? "{$domain} now always opens with HTTPS." : "HTTPS redirect for {$domain} switched off.")
            : $this->fail($this->uapiMessage($r, 'Could not change the HTTPS redirect (a valid certificate is needed first).'));
    }

    // ------------------------------------------------------------------
    // PHP
    // ------------------------------------------------------------------

    public const PHP_DIRECTIVES = ['memory_limit', 'upload_max_filesize', 'post_max_size', 'max_execution_time', 'max_input_time', 'max_input_vars'];

    /** @return array{versions: array<string,string>, domains: list<array{domain:string, version:string}>} */
    public function phpOverview(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return ['versions' => [], 'domains' => []];
        }
        $v = $this->uapi($acc[0], $acc[1], 'LangPHP', 'php_get_installed_versions');
        $versions = [];
        foreach ((array) ($v['data']['versions'] ?? []) as $id) {
            $versions[(string) $id] = self::phpLabel((string) $id);
        }

        $own = array_merge(array_values($this->accountDomains($service)), array_column($this->subdomains($service), 'full_name'));
        $h = $this->uapi($acc[0], $acc[1], 'LangPHP', 'php_get_vhost_versions');
        $domains = [];
        foreach ((array) ($h['data'] ?? []) as $row) {
            $vhost = strtolower((string) ($row['vhost'] ?? ''));
            if (in_array($vhost, $own, true)) {
                $domains[] = ['domain' => $vhost, 'version' => (string) ($row['version'] ?? '')];
            }
        }
        usort($domains, fn ($a, $b) => strcmp($a['domain'], $b['domain']));

        return ['versions' => $versions, 'domains' => $domains];
    }

    public static function phpLabel(string $id): string
    {
        return preg_match('/(\d)(\d+)$/', $id, $m) ? "PHP {$m[1]}.{$m[2]}" : $id;
    }

    public function setPhpVersion(Service $service, string $domain, string $version): array
    {
        $o = $this->phpOverview($service);
        if (! in_array(strtolower($domain), array_column($o['domains'], 'domain'), true)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        if (! isset($o['versions'][$version])) {
            return $this->fail('That PHP version is not installed on this server.');
        }
        $acc = $this->cpAccount($service);
        $r = $this->uapi($acc[0], $acc[1], 'LangPHP', 'php_set_vhost_versions', ['version' => $version, 'vhost' => strtolower($domain)]);

        return $r['success'] ? $this->ok(strtolower($domain).' now runs '.self::phpLabel($version).'.') : $this->fail($this->uapiMessage($r, 'Could not change the PHP version.'));
    }

    /** @return array<string,string> directive => value for one domain */
    public function phpSettings(Service $service, string $domain): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! in_array(strtolower($domain), array_column($this->phpOverview($service)['domains'], 'domain'), true)) {
            return [];
        }
        $r = $this->uapi($acc[0], $acc[1], 'LangPHP', 'php_ini_get_user_basic_directives', ['type' => 'vhost', 'vhost' => strtolower($domain)]);
        $out = [];
        foreach ((array) ($r['data']['directives'] ?? $r['data'] ?? []) as $d) {
            $k = (string) ($d['key'] ?? '');
            if (in_array($k, self::PHP_DIRECTIVES, true)) {
                $out[$k] = (string) ($d['value'] ?? $d['default_value'] ?? '');
            }
        }

        return $out;
    }

    public function savePhpSettings(Service $service, string $domain, array $values): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! in_array(strtolower($domain), array_column($this->phpOverview($service)['domains'], 'domain'), true)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        $args = ['type' => 'vhost', 'vhost' => strtolower($domain)];
        $i = 1;
        foreach (self::PHP_DIRECTIVES as $k) {
            $v = trim((string) ($values[$k] ?? ''));
            if ($v === '') {
                continue;
            }
            if (! preg_match('/^\d+[KMG]?$/i', $v) && ! ($k === 'max_execution_time' && $v === '-1')) {
                return $this->fail("{$k} must be a number like 256M or 300.");
            }
            $args['directive-'.$i++] = "{$k}:{$v}";
        }
        if ($i === 1) {
            return $this->fail('Nothing to save.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'LangPHP', 'php_ini_set_user_basic_directives', $args);

        return $r['success'] ? $this->ok('PHP settings saved for '.strtolower($domain).'.') : $this->fail($this->uapiMessage($r, 'Could not save the PHP settings.'));
    }

    // ------------------------------------------------------------------
    // Email extras
    // ------------------------------------------------------------------

    /** @return list<array{from:string, to:string}> */
    public function forwarders(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $out = [];
        foreach ($this->accountDomains($service) as $domain) {
            $r = $this->uapiCached($acc[0], $acc[1], 'Email', 'list_forwarders', ['domain' => $domain]);
            foreach ((array) ($r['data'] ?? []) as $f) {
                $out[] = ['from' => strtolower((string) ($f['dest'] ?? $f['html_dest'] ?? '')), 'to' => (string) ($f['forward'] ?? $f['html_forward'] ?? '')];
            }
        }

        return array_values(array_filter($out, fn ($f) => $f['from'] !== ''));
    }

    public function addForwarder(Service $service, string $localPart, string $domain, string $target): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! $this->ownsDomain($service, $domain)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        if (! filter_var($target, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Forward to must be an email address.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Email', 'add_forwarder', ['domain' => strtolower($domain), 'email' => strtolower($localPart).'@'.strtolower($domain), 'fwdopt' => 'fwd', 'fwdemail' => $target]);

        return $r['success'] ? $this->ok("Mail to {$localPart}@{$domain} now also goes to {$target}.") : $this->fail($this->uapiMessage($r, 'Could not add the forwarder.'));
    }

    public function deleteForwarder(Service $service, string $from, string $to): array
    {
        $own = collect($this->forwarders($service))->first(fn ($f) => $f['from'] === strtolower($from) && $f['to'] === $to);
        if (! ($acc = $this->cpAccount($service)) || ! $own) {
            return $this->fail('That forwarder is not part of this hosting account.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Email', 'delete_forwarder', ['address' => $own['from'], 'forwarder' => $own['to']]);

        return $r['success'] ? $this->ok('Forwarder removed.') : $this->fail($this->uapiMessage($r, 'Could not remove the forwarder.'));
    }

    /** @return list<array{email:string, subject:string}> */
    public function autoresponders(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $out = [];
        foreach ($this->accountDomains($service) as $domain) {
            $r = $this->uapiCached($acc[0], $acc[1], 'Email', 'list_auto_responders', ['domain' => $domain]);
            foreach ((array) ($r['data'] ?? []) as $a) {
                $out[] = ['email' => strtolower((string) ($a['email'] ?? '')), 'subject' => (string) ($a['subject'] ?? '')];
            }
        }

        return array_values(array_filter($out, fn ($a) => $a['email'] !== ''));
    }

    public function addAutoresponder(Service $service, string $email, string $from, string $subject, string $body, int $intervalHours = 8, ?string $start = null, ?string $stop = null): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! $this->ownEmail($service, $email)) {
            return $this->fail('Choose one of this account\'s mailboxes.');
        }
        [$local, $domain] = explode('@', strtolower($email), 2);
        $r = $this->uapi($acc[0], $acc[1], 'Email', 'add_auto_responder', [
            'email' => $local, 'domain' => $domain, 'from' => $from, 'subject' => $subject, 'body' => $body,
            'is_html' => 0, 'interval' => max(0, $intervalHours),
            'start' => $start ? strtotime($start) : time(), 'stop' => $stop ? strtotime($stop.' 23:59:59') : '',
        ]);

        return $r['success'] ? $this->ok("Autoresponder set for {$email}.") : $this->fail($this->uapiMessage($r, 'Could not set the autoresponder.'));
    }

    public function deleteAutoresponder(Service $service, string $email): array
    {
        $own = collect($this->autoresponders($service))->firstWhere('email', strtolower($email));
        if (! ($acc = $this->cpAccount($service)) || ! $own) {
            return $this->fail('That autoresponder is not part of this hosting account.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Email', 'delete_auto_responder', ['email' => $own['email']]);

        return $r['success'] ? $this->ok('Autoresponder removed.') : $this->fail($this->uapiMessage($r, 'Could not remove the autoresponder.'));
    }

    /** @return array<string,string> domain => what happens to mail for unknown addresses */
    public function catchAll(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $out = [];
        foreach ($this->accountDomains($service) as $domain) {
            $r = $this->uapiCached($acc[0], $acc[1], 'Email', 'list_default_address', ['domain' => $domain]);
            $row = (array) (($r['data'] ?? [])[0] ?? []);
            $out[$domain] = (string) ($row['defaultaddress'] ?? '');
        }

        return $out;
    }

    /** $mode: fail (bounce), blackhole (discard), fwd (forward to $target) */
    public function setCatchAll(Service $service, string $domain, string $mode, string $target = ''): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! $this->ownsDomain($service, $domain)) {
            return $this->fail('That domain is not part of this hosting account.');
        }
        if (! in_array($mode, ['fail', 'blackhole', 'fwd'], true) || ($mode === 'fwd' && ! filter_var($target, FILTER_VALIDATE_EMAIL))) {
            return $this->fail('Choose bounce, discard, or forward to an email address.');
        }
        $r = $this->uapi($acc[0], $acc[1], 'Email', 'set_default_address', array_filter(['domain' => strtolower($domain), 'fwdopt' => $mode, 'fwdemail' => $mode === 'fwd' ? $target : null]));

        return $r['success'] ? $this->ok('Catch-all updated for '.strtolower($domain).'.') : $this->fail($this->uapiMessage($r, 'Could not update the catch-all.'));
    }

    // ------------------------------------------------------------------
    // Domains: addon domains and aliases
    // ------------------------------------------------------------------

    /** @return array{main:string, addons: list<array{domain:string, dir:string, key:string}>, aliases: list<string>} */
    public function domainList(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return ['main' => '', 'addons' => [], 'aliases' => []];
        }
        $addons = [];
        $r = $this->api2($acc[0], $acc[1], 'AddonDomain', 'listaddondomains');
        foreach ($r['data'] as $a) {
            if (! empty($a['domain'])) {
                $addons[] = ['domain' => strtolower($a['domain']), 'dir' => (string) ($a['dir'] ?? ''), 'key' => (string) ($a['domainkey'] ?? $a['fullsubdomain'] ?? '')];
            }
        }
        $d = $this->uapiCached($acc[0], $acc[1], 'DomainInfo', 'list_domains');

        return [
            'main' => strtolower((string) ($d['data']['main_domain'] ?? '')),
            'addons' => $addons,
            'aliases' => array_values(array_map('strtolower', (array) ($d['data']['parked_domains'] ?? []))),
        ];
    }

    private static function validDomain(string $d): bool
    {
        return (bool) preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $d);
    }

    public function addAddonDomain(Service $service, string $domain, ?string $dir = null): array
    {
        $domain = strtolower(trim($domain));
        if (! ($acc = $this->cpAccount($service)) || ! self::validDomain($domain)) {
            return $this->fail('Enter a domain name like example.com.');
        }
        $label = substr(preg_replace('/[^a-z0-9]/', '', strtok($domain, '.')), 0, 30) ?: 'addon';
        $rel = trim((string) $dir) !== '' ? $this->relativeHomePath($service, (string) $dir) : 'public_html/'.$domain;
        if ($rel === null) {
            return $this->fail('The folder must be inside your home folder.');
        }
        $r = $this->api2($acc[0], $acc[1], 'AddonDomain', 'addaddondomain', ['newdomain' => $domain, 'subdomain' => $label, 'dir' => $rel]);

        return $r['success'] ? $this->ok("{$domain} added. Point its nameservers (or A record) to this server.") : $this->fail($r['message']);
    }

    public function deleteAddonDomain(Service $service, string $domain): array
    {
        $own = collect($this->domainList($service)['addons'])->firstWhere('domain', strtolower($domain));
        if (! ($acc = $this->cpAccount($service)) || ! $own) {
            return $this->fail('That addon domain is not part of this hosting account.');
        }
        $r = $this->api2($acc[0], $acc[1], 'AddonDomain', 'deladdondomain', ['domain' => $own['domain'], 'subdomain' => $own['key']]);

        return $r['success'] ? $this->ok("{$own['domain']} removed (its files were kept).") : $this->fail($r['message']);
    }

    public function addAlias(Service $service, string $domain): array
    {
        $domain = strtolower(trim($domain));
        if (! ($acc = $this->cpAccount($service)) || ! self::validDomain($domain)) {
            return $this->fail('Enter a domain name like example.com.');
        }
        $r = $this->api2($acc[0], $acc[1], 'Park', 'park', ['domain' => $domain]);

        return $r['success'] ? $this->ok("{$domain} now shows the same site as your main domain.") : $this->fail($r['message']);
    }

    public function deleteAlias(Service $service, string $domain): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! in_array(strtolower($domain), $this->domainList($service)['aliases'], true)) {
            return $this->fail('That alias is not part of this hosting account.');
        }
        $r = $this->api2($acc[0], $acc[1], 'Park', 'unpark', ['domain' => strtolower($domain)]);

        return $r['success'] ? $this->ok('Alias removed.') : $this->fail($r['message']);
    }

    // ------------------------------------------------------------------
    // Error log
    // ------------------------------------------------------------------

    /** @return list<array{date:string, entry:string}> newest first */
    public function errorLog(Service $service, string $domain, int $lines = 100): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! $this->ownsDomain($service, $domain)) {
            return [];
        }
        $r = $this->uapi($acc[0], $acc[1], 'Stats', 'get_site_errors', ['domain' => strtolower($domain), 'log' => 'error', 'maxlines' => max(10, min(500, $lines))]);
        $out = [];
        foreach ((array) ($r['data'] ?? []) as $e) {
            $out[] = ['date' => isset($e['date']) ? (is_numeric($e['date']) ? date('Y-m-d H:i:s', (int) $e['date']) : (string) $e['date']) : '', 'entry' => (string) ($e['entry'] ?? '')];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // IP blocker
    // ------------------------------------------------------------------

    /** @return list<string> */
    public function blockedIps(Service $service): array
    {
        if (! $acc = $this->cpAccount($service)) {
            return [];
        }
        $r = $this->api2($acc[0], $acc[1], 'DenyIp', 'listdenyips');

        return array_values(array_filter(array_map(fn ($x) => (string) ($x['ip'] ?? ''), $r['data'])));
    }

    /** One IP, a CIDR range, or a range like 1.2.3.4-1.2.3.9. */
    private static function validIpRule(string $v): bool
    {
        if (filter_var($v, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (preg_match('#^(.+)/(\d{1,3})$#', $v, $m) && filter_var($m[1], FILTER_VALIDATE_IP)) {
            return (int) $m[2] <= (str_contains($m[1], ':') ? 128 : 32) && (int) $m[2] >= (str_contains($m[1], ':') ? 32 : 16);
        }
        if (preg_match('#^([\d.]+)-([\d.]+)$#', $v, $m)) {
            return (bool) (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($m[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));
        }

        return false;
    }

    public function blockIp(Service $service, string $ip): array
    {
        $ip = trim($ip);
        if (! ($acc = $this->cpAccount($service)) || ! self::validIpRule($ip)) {
            return $this->fail('Enter an IP address (e.g. 203.0.113.7), a range (203.0.113.0/24), or 203.0.113.1-203.0.113.9.');
        }
        $r = $this->api2($acc[0], $acc[1], 'DenyIp', 'adddenyip', ['ip' => $ip]);

        return $r['success'] ? $this->ok("{$ip} can no longer open your websites.") : $this->fail($r['message']);
    }

    public function unblockIp(Service $service, string $ip): array
    {
        if (! ($acc = $this->cpAccount($service)) || ! in_array($ip, $this->blockedIps($service), true)) {
            return $this->fail('That address is not on your block list.');
        }
        $r = $this->api2($acc[0], $acc[1], 'DenyIp', 'deldenyip', ['ip' => $ip]);

        return $r['success'] ? $this->ok("{$ip} unblocked.") : $this->fail($r['message']);
    }
}
