<?php

namespace Modules\Servers\CPanelPro;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Daily: tell clients when their cPanel account passes 80% or 95% of its disk
 * or bandwidth limit, by email and through the "HostingUsageWarning" hook
 * (Flowsensy Notification turns that into a WhatsApp message).
 *
 * Each level is sent once: disk again only after usage has dropped below 80%,
 * bandwidth once per level per month. Unlimited accounts are skipped.
 */
class UsageAlerts
{
    public const LEVELS = [95, 80];

    private const STATE_FILE = 'cpanelpro/usage-alerts.json';

    public function __construct(private CPanelProModule $module) {}

    /** @return int warnings sent */
    public function run(): int
    {
        $state = $this->load();
        $month = now()->format('Y-m');
        $sent = 0;

        $servers = Server::whereRaw('LOWER(type) = ?', ['cpanelpro'])->where('active', true)->get();
        foreach ($servers as $server) {
            $services = Service::with(['client', 'product'])->where('server_id', $server->id)
                ->whereRaw('LOWER(status) = ?', ['active'])->whereNotNull('username')->where('username', '!=', '')->get();
            if ($services->isEmpty()) {
                continue;
            }

            $usage = $this->serverUsage($server);
            if ($usage === null) {
                continue;
            }

            foreach ($services as $service) {
                $u = $usage[strtolower((string) $service->username)] ?? null;
                if (! $u) {
                    continue;
                }
                foreach (['disk', 'bandwidth'] as $resource) {
                    [$usedMb, $limitMb] = $u[$resource];
                    $key = "svc{$service->id}.{$resource}";
                    if ($limitMb <= 0) {
                        unset($state[$key]);

                        continue;
                    }
                    $percent = (int) floor($usedMb / $limitMb * 100);
                    $level = self::levelFor($percent);
                    $prev = $state[$key] ?? null;

                    // Bandwidth starts over each month; disk once it falls back under 80%.
                    if ($prev && $resource === 'bandwidth' && ($prev['month'] ?? '') !== $month) {
                        $prev = null;
                    }
                    if ($level === null) {
                        if ($resource === 'disk' || ! $prev) {
                            unset($state[$key]);
                        }

                        continue;
                    }
                    if ($prev && (int) $prev['level'] >= $level) {
                        continue;
                    }

                    $info = [
                        'resource' => $resource === 'disk' ? 'Disk space' : 'Bandwidth',
                        'percent' => $percent,
                        'used' => self::size($usedMb),
                        'limit' => self::size($limitMb),
                        'level' => $level,
                    ];
                    $this->notify($service, $info);
                    $state[$key] = ['level' => $level, 'month' => $month];
                    $sent++;
                }
            }
        }

        $this->save($state);

        return $sent;
    }

    public static function levelFor(int $percent): ?int
    {
        foreach (self::LEVELS as $l) {
            if ($percent >= $l) {
                return $l;
            }
        }

        return null;
    }

    public static function size(int $mb): string
    {
        return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.').' GB' : $mb.' MB';
    }

    /** @return array<string, array{disk: array{0:int,1:int}, bandwidth: array{0:int,1:int}}>|null user => [used MB, limit MB] */
    private function serverUsage(Server $server): ?array
    {
        $accts = $this->module->whm($server, 'listaccts', ['want' => 'user,diskused,disklimit'], 60);
        if (! $accts['success']) {
            Log::warning("cPanel Pro usage check: listaccts failed on {$server->name}: {$accts['message']}");

            return null;
        }
        $out = [];
        foreach ((array) ($accts['raw']['acct'] ?? []) as $a) {
            $user = strtolower((string) ($a['user'] ?? ''));
            if ($user !== '') {
                $out[$user] = [
                    'disk' => [CPanelProModule::megabytes($a['diskused'] ?? 0), CPanelProModule::megabytes($a['disklimit'] ?? 0)],
                    'bandwidth' => [0, 0],
                ];
            }
        }
        $bw = $this->module->whm($server, 'showbw', [], 60);
        foreach ((array) ($bw['raw']['acct'] ?? []) as $b) {
            $user = strtolower((string) ($b['user'] ?? ''));
            if (isset($out[$user])) {
                $limit = $b['limit'] ?? 0;
                $out[$user]['bandwidth'] = [
                    (int) round(((float) ($b['totalbytes'] ?? 0)) / 1048576),
                    is_numeric($limit) ? (int) round(((float) $limit) / 1048576) : 0,
                ];
            }
        }

        return $out;
    }

    private function notify(Service $service, array $info): void
    {
        // WhatsApp etc.: any addon listening on this hook (Flowsensy Notification has an event for it).
        try {
            run_hook('HostingUsageWarning', ['service' => $service, 'client' => $service->client, 'usage' => $info]);
        } catch (\Throwable $e) {
            Log::warning("cPanel Pro usage hook failed for service #{$service->id}: {$e->getMessage()}");
        }

        $email = $service->client?->email;
        if ($email) {
            try {
                Mail::to($email)->send(new UsageWarningMail($service, $info));
            } catch (\Throwable $e) {
                Log::warning("cPanel Pro usage email failed for service #{$service->id}: {$e->getMessage()}");
            }
        }
    }

    private function load(): array
    {
        try {
            return json_decode((string) Storage::disk('local')->get(self::STATE_FILE), true) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function save(array $state): void
    {
        Storage::disk('local')->put(self::STATE_FILE, json_encode($state));
    }
}
