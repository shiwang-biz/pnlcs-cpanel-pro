<?php

namespace Modules\Servers\CPanelPro;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CPanelProServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'cpanelpro');

        if ($this->app->runningInConsole()) {
            $this->commands([UsageCheckCommand::class]);
            // Into PNLCS's own scheduler (the existing cron), nothing to set up.
            $this->app->booted(function () {
                try {
                    $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                        ->command('cpanelpro:usage-check')->dailyAt('09:17')->withoutOverlapping(120);
                } catch (\Throwable) {
                }
            });
        }

        Route::middleware(['web', 'admin.auth', 'admin.2fa'])
            ->prefix('admin/cpanelpro')->name('cpanelpro.admin.')
            ->group(function () {
                // Signing in to a client's cPanel acts on their hosting: same permission as the service's module buttons.
                Route::post('services/{service}/cpanel', [CPanelProController::class, 'clientCpanel'])
                    ->middleware(['admin.permission:manage_services', 'throttle:20,1'])->name('cpanel');
                // WHM is the whole server: same permission as Live Servers.
                Route::post('servers/{server}/whm', [CPanelProController::class, 'whm'])
                    ->middleware(['admin.permission:manage_servers', 'throttle:20,1'])->name('whm');
                Route::get('servers/{server}/health', [CPanelProController::class, 'health'])
                    ->middleware(['admin.permission:manage_servers', 'throttle:60,1'])->name('health');
            });

        // Client pages. Names start with "client.services." so PNLCS applies its
        // own client-permission rules (CheckClientPermission) to them.
        Route::middleware(['web', 'banned.ip', 'client.permission', 'auth', '2fa', 'throttle:60,1'])
            ->prefix('client/services/{service}/cp')->name('client.services.cpanelpro.')
            ->group(function () {
                $c = ExtrasController::class;
                Route::post('phpmyadmin', [$c, 'phpMyAdmin'])->name('phpmyadmin');
                Route::get('webmail', [$c, 'webmail'])->name('webmail');
                Route::post('webmail', [$c, 'webmailLogin'])->name('webmail.login');
                Route::get('ssl', [$c, 'ssl'])->name('ssl');
                Route::post('ssl/autossl', [$c, 'autoSsl'])->name('ssl.autossl');
                Route::post('ssl/redirect', [$c, 'httpsRedirect'])->name('ssl.redirect');
                Route::get('php', [$c, 'php'])->name('php');
                Route::post('php/version', [$c, 'phpVersion'])->name('php.version');
                Route::post('php/settings', [$c, 'phpSettings'])->name('php.settings');
                Route::get('email-extras', [$c, 'emailExtras'])->name('email');
                Route::post('email-extras/forwarders', [$c, 'addForwarder'])->name('email.forwarders.store');
                Route::post('email-extras/forwarders/delete', [$c, 'deleteForwarder'])->name('email.forwarders.destroy');
                Route::post('email-extras/autoresponders', [$c, 'addAutoresponder'])->name('email.autoresponders.store');
                Route::post('email-extras/autoresponders/delete', [$c, 'deleteAutoresponder'])->name('email.autoresponders.destroy');
                Route::post('email-extras/catch-all', [$c, 'catchAll'])->name('email.catchall');
                Route::get('domains', [$c, 'domains'])->name('domains');
                Route::post('domains/addon', [$c, 'addAddon'])->name('domains.addon.store');
                Route::post('domains/addon/delete', [$c, 'deleteAddon'])->name('domains.addon.destroy');
                Route::post('domains/alias', [$c, 'addAlias'])->name('domains.alias.store');
                Route::post('domains/alias/delete', [$c, 'deleteAlias'])->name('domains.alias.destroy');
                Route::get('error-log', [$c, 'errors'])->name('errors');
                Route::get('ip-blocker', [$c, 'ipBlock'])->name('ipblock');
                Route::post('ip-blocker', [$c, 'addIp'])->name('ipblock.store');
                Route::post('ip-blocker/delete', [$c, 'deleteIp'])->name('ipblock.destroy');
                Route::get('file-tools', [$c, 'files'])->name('files');
                Route::post('file-tools/extract', [$c, 'extract'])->name('files.extract');
                Route::post('file-tools/compress', [$c, 'compress'])->name('files.compress');
                Route::post('file-tools/permissions', [$c, 'chmod'])->name('files.chmod');
            });
    }
}
