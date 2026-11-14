<?php

namespace Modules\Servers\CPanelPro;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CPanelProServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'cpanelpro');

        Route::middleware(['web', 'admin.auth', 'admin.2fa'])
            ->prefix('admin/cpanelpro')->name('cpanelpro.admin.')
            ->group(function () {
                // Signing in to a client's cPanel acts on their hosting: same permission as the service's module buttons.
                Route::post('services/{service}/cpanel', [CPanelProController::class, 'clientCpanel'])
                    ->middleware(['admin.permission:manage_services', 'throttle:20,1'])->name('cpanel');
                // WHM is the whole server: same permission as Live Servers.
                Route::post('servers/{server}/whm', [CPanelProController::class, 'whm'])
                    ->middleware(['admin.permission:manage_servers', 'throttle:20,1'])->name('whm');
            });
    }
}
