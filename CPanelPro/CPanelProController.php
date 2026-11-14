<?php

namespace Modules\Servers\CPanelPro;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Models\Service;

class CPanelProController extends Controller
{
    private function module(): CPanelProModule
    {
        return new CPanelProModule;
    }

    private static function isPro(?string $type): bool
    {
        return strtolower((string) $type) === 'cpanelpro';
    }

    /** Staff: open this client's cPanel, already signed in. */
    public function clientCpanel(Service $service)
    {
        $service->loadMissing('server', 'client');
        abort_unless($service->server && self::isPro($service->server->type), 404);

        $result = $this->module()->ssoLogin($service);
        $admin = auth('admin')->user();
        ActivityLog::log(
            ($result['success'] ? 'cPanel Pro: signed in to cPanel of ' : 'cPanel Pro: cPanel sign-in failed for ')
                .$service->username.' (service #'.$service->id.', '.$service->server->hostname.')',
            $admin?->username
        );

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        return redirect()->away($result['data']['url']);
    }

    /** Staff: open WHM on this server, already signed in. */
    public function whm(Server $server)
    {
        abort_unless(self::isPro($server->type), 404);

        $result = $this->module()->operatorLogin($server);
        $admin = auth('admin')->user();
        ActivityLog::log(
            ($result['success'] ? 'cPanel Pro: signed in to WHM on ' : 'cPanel Pro: WHM sign-in failed for ')
                .$server->name.' ('.$server->hostname.')',
            $admin?->username
        );

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        return redirect()->away($result['url']);
    }
}
