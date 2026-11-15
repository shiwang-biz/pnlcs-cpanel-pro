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

                // WordPress (cPanel WP Toolkit)
                $w = WordPressController::class;
                Route::get('wordpress', [$w, 'index'])->name('wp');
                Route::post('wordpress/install', [$w, 'install'])->name('wp.install');
                Route::post('wordpress/scan', [$w, 'scan'])->name('wp.scan');
                Route::prefix('wordpress/{site}')->whereNumber('site')->name('wp.')->group(function () use ($w) {
                    Route::get('/', [$w, 'show'])->name('show');
                    Route::get('tasks', [$w, 'tasks'])->name('tasks');
                    Route::post('login', [$w, 'login'])->name('login');
                    Route::post('toggle', [$w, 'toggle'])->name('toggle');
                    Route::post('password-protection', [$w, 'passwordProtection'])->name('protection');
                    Route::post('cache', [$w, 'clearCache'])->name('cache');
                    Route::post('account', [$w, 'account'])->name('account');
                    Route::post('set', [$w, 'installSet'])->name('set');
                    Route::post('{kind}/install', [$w, 'assetInstall'])->whereIn('kind', ['plugins', 'themes'])->name('asset.install');
                    Route::post('{kind}/upload', [$w, 'assetUpload'])->whereIn('kind', ['plugins', 'themes'])->name('asset.upload');
                    Route::post('{kind}/status', [$w, 'assetStatus'])->whereIn('kind', ['plugins', 'themes'])->name('asset.status');
                    Route::post('{kind}/auto-update', [$w, 'assetAutoUpdate'])->whereIn('kind', ['plugins', 'themes'])->name('asset.autoupdate');
                    Route::post('{kind}/delete', [$w, 'assetDelete'])->whereIn('kind', ['plugins', 'themes'])->name('asset.delete');
                    Route::post('updates/check', [$w, 'checkUpdates'])->name('updates.check');
                    Route::post('updates/apply', [$w, 'applyUpdates'])->name('updates.apply');
                    Route::post('updates/settings', [$w, 'updateSettings'])->name('updates.settings');
                    Route::post('security', [$w, 'security'])->name('security');
                    Route::post('backups', [$w, 'backup'])->name('backups.create');
                    Route::post('backups/restore', [$w, 'restore'])->name('backups.restore');
                    Route::post('backups/delete', [$w, 'deleteBackups'])->name('backups.delete');
                    Route::post('clone', [$w, 'cloneSite'])->name('clone');
                    Route::post('remove', [$w, 'remove'])->name('remove');
                });
            });
    }
}
