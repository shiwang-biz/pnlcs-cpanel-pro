<?php

namespace Modules\Servers\CPanelPro;

use App\Http\Controllers\Concerns\ResolvesClient;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\ProvisioningService;
use Illuminate\Http\Request;

/**
 * The client's extra cPanel pages. Route names start with
 * "client.services." so PNLCS's own permission check applies to them as to
 * its other service pages (a contact needs "manage products").
 */
class ExtrasController extends Controller
{
    use ResolvesClient;

    /** The service, its module, and whether changes are allowed now. */
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

    private function done(array $r)
    {
        return back()->with(($r['success'] ?? false) ? 'success' : 'error', $r['message'] ?? 'Something went wrong.');
    }

    private function page(string $view, Service $service, array $data = [])
    {
        return view('cpanelpro::client.'.$view, $data + ['service' => $service]);
    }

    // ---------- One-click logins ----------

    public function phpMyAdmin(Service $service)
    {
        $r = $this->context($service, true)->phpMyAdminLogin($service);

        return $r['success'] ? redirect()->away($r['data']['url']) : $this->done($r);
    }

    public function webmail(Service $service)
    {
        $m = $this->context($service);

        return $this->page('webmail', $service, ['emails' => $m->listEmails($service)]);
    }

    public function webmailLogin(Request $request, Service $service)
    {
        $r = $this->context($service, true)->webmailLogin($service, (string) $request->input('email'));

        return $r['success'] ? redirect()->away($r['data']['url']) : $this->done($r);
    }

    // ---------- SSL ----------

    public function ssl(Service $service)
    {
        return $this->page('ssl', $service, ['rows' => $this->context($service)->sslStatus($service)]);
    }

    public function autoSsl(Service $service)
    {
        return $this->done($this->context($service, true)->runAutoSsl($service));
    }

    public function httpsRedirect(Request $request, Service $service)
    {
        $d = $request->validate(['domain' => ['required', 'string', 'max:253'], 'state' => ['required', 'in:0,1']]);

        return $this->done($this->context($service, true)->setHttpsRedirect($service, $d['domain'], $d['state'] === '1'));
    }

    // ---------- PHP ----------

    public function php(Request $request, Service $service)
    {
        $m = $this->context($service);
        $overview = $m->phpOverview($service);
        $domains = array_column($overview['domains'], 'domain');
        $selected = in_array($request->query('domain'), $domains, true) ? (string) $request->query('domain') : ($domains[0] ?? '');

        return $this->page('php', $service, [
            'overview' => $overview,
            'selected' => $selected,
            'settings' => $selected !== '' ? $m->phpSettings($service, $selected) : [],
        ]);
    }

    public function phpVersion(Request $request, Service $service)
    {
        $d = $request->validate(['domain' => ['required', 'string', 'max:253'], 'version' => ['required', 'string', 'max:40']]);

        return $this->done($this->context($service, true)->setPhpVersion($service, $d['domain'], $d['version']));
    }

    public function phpSettings(Request $request, Service $service)
    {
        $d = $request->validate(['domain' => ['required', 'string', 'max:253']] + array_fill_keys(
            array_map(fn ($k) => 'ini.'.$k, CPanelProModule::PHP_DIRECTIVES), ['nullable', 'string', 'max:20']
        ));

        return $this->done($this->context($service, true)->savePhpSettings($service, $d['domain'], (array) $request->input('ini', [])));
    }

    // ---------- Email extras ----------

    public function emailExtras(Service $service)
    {
        $m = $this->context($service);

        return $this->page('email-extras', $service, [
            'domains' => $m->accountDomains($service),
            'emails' => $m->listEmails($service),
            'forwarders' => $m->forwarders($service),
            'autoresponders' => $m->autoresponders($service),
            'catchAll' => $m->catchAll($service),
        ]);
    }

    public function addForwarder(Request $request, Service $service)
    {
        $d = $request->validate([
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._%+\-]+$/'],
            'domain' => ['required', 'string', 'max:253'],
            'target' => ['required', 'email', 'max:254'],
        ]);

        return $this->done($this->context($service, true)->addForwarder($service, $d['local_part'], $d['domain'], $d['target']));
    }

    public function deleteForwarder(Request $request, Service $service)
    {
        return $this->done($this->context($service, true)->deleteForwarder($service, (string) $request->input('from'), (string) $request->input('to')));
    }

    public function addAutoresponder(Request $request, Service $service)
    {
        $d = $request->validate([
            'email' => ['required', 'email'],
            'from' => ['required', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'interval' => ['nullable', 'integer', 'min:0', 'max:720'],
            'start' => ['nullable', 'date'],
            'stop' => ['nullable', 'date', 'after_or_equal:start'],
        ]);

        return $this->done($this->context($service, true)->addAutoresponder($service, $d['email'], $d['from'], $d['subject'], $d['body'], (int) ($d['interval'] ?? 8), $d['start'] ?? null, $d['stop'] ?? null));
    }

    public function deleteAutoresponder(Request $request, Service $service)
    {
        return $this->done($this->context($service, true)->deleteAutoresponder($service, (string) $request->input('email')));
    }

    public function catchAll(Request $request, Service $service)
    {
        $d = $request->validate(['domain' => ['required', 'string'], 'mode' => ['required', 'in:fail,blackhole,fwd'], 'target' => ['nullable', 'required_if:mode,fwd', 'email']]);

        return $this->done($this->context($service, true)->setCatchAll($service, $d['domain'], $d['mode'], (string) ($d['target'] ?? '')));
    }

    // ---------- Domains ----------

    public function domains(Service $service)
    {
        return $this->page('domains', $service, ['list' => $this->context($service)->domainList($service)]);
    }

    public function addAddon(Request $request, Service $service)
    {
        $d = $request->validate(['domain' => ['required', 'string', 'max:253'], 'dir' => ['nullable', 'string', 'max:255']]);

        return $this->done($this->context($service, true)->addAddonDomain($service, $d['domain'], $d['dir'] ?? null));
    }

    public function deleteAddon(Request $request, Service $service)
    {
        return $this->done($this->context($service, true)->deleteAddonDomain($service, (string) $request->input('domain')));
    }

    public function addAlias(Request $request, Service $service)
    {
        $d = $request->validate(['domain' => ['required', 'string', 'max:253']]);

        return $this->done($this->context($service, true)->addAlias($service, $d['domain']));
    }

    public function deleteAlias(Request $request, Service $service)
    {
        return $this->done($this->context($service, true)->deleteAlias($service, (string) $request->input('domain')));
    }

    // ---------- Error log ----------

    public function errors(Request $request, Service $service)
    {
        $m = $this->context($service);
        $domains = $m->accountDomains($service);
        $selected = isset($domains[strtolower((string) $request->query('domain'))]) ? strtolower((string) $request->query('domain')) : (string) array_key_first($domains);

        return $this->page('errors', $service, ['domains' => $domains, 'selected' => $selected, 'entries' => $selected !== '' ? $m->errorLog($service, $selected) : []]);
    }

    // ---------- IP blocker ----------

    public function ipBlock(Service $service)
    {
        return $this->page('ipblock', $service, ['ips' => $this->context($service)->blockedIps($service), 'yourIp' => request()->ip()]);
    }

    public function addIp(Request $request, Service $service)
    {
        $d = $request->validate(['ip' => ['required', 'string', 'max:100']]);

        return $this->done($this->context($service, true)->blockIp($service, $d['ip']));
    }

    public function deleteIp(Request $request, Service $service)
    {
        return $this->done($this->context($service, true)->unblockIp($service, (string) $request->input('ip')));
    }

    // ---------- File tools ----------

    public function files(Request $request, Service $service)
    {
        return $this->page('files', $service, ['list' => $this->context($service)->listFiles($service, $request->query('path'))]);
    }

    public function extract(Request $request, Service $service)
    {
        return $this->done($this->context($service, true)->extractArchive($service, (string) $request->input('archive'), $request->input('dest')));
    }

    public function compress(Request $request, Service $service)
    {
        $d = $request->validate(['paths' => ['required', 'array', 'max:200'], 'paths.*' => ['string', 'max:1024'], 'name' => ['required', 'string', 'max:200'], 'type' => ['required', 'in:'.implode(',', CPanelProModule::ARCHIVE_TYPES)]]);

        return $this->done($this->context($service, true)->compressEntries($service, $d['paths'], $d['name'], $d['type']));
    }

    public function chmod(Request $request, Service $service)
    {
        $d = $request->validate(['paths' => ['required', 'array', 'max:200'], 'paths.*' => ['string', 'max:1024'], 'mode' => ['required', 'string', 'max:4']]);

        return $this->done($this->context($service, true)->chmodEntries($service, $d['paths'], $d['mode']));
    }
}
