<?php

/*
 * cPanel/WHM Pro, admin pages only. PNLCS loads this file for server modules
 * on every request, so each hook checks the page first and never throws.
 *
 *  - Setup → Servers: the form gets the same settings for "cPanel/WHM Pro" as
 *    for "cPanel/WHM" (port 2087, username, the API Token field and its hint).
 *  - Live Servers: a box with one-click WHM logins for cPanel/WHM Pro servers
 *    (that page lists only Panelica servers on its own).
 *  - Every admin page: "Cpanelpro" in a dropdown or list reads "cPanel/WHM Pro".
 *    (The type key has to stay "cpanelpro": PNLCS finds a product's servers by it,
 *    and the nicer built-in labels are a fixed list in PNLCS itself.)
 */

use App\Models\Server;
use Illuminate\Support\Facades\Route;

add_hook('AdminAreaFooterOutput', 60, function () {
    try {
        $route = request()->route()?->getName();
        if (! is_string($route) || ! str_starts_with($route, 'admin.')) {
            return '';
        }
        // Loaded with every admin page; only touches text that is exactly "cpanelpro".
        $out = <<<'HTML'
<script>
(function () {
    try {
        document.querySelectorAll('option[value="cpanelpro"], option[value="CPanelPro"]').forEach(function (o) {
            if (/^\s*cpanelpro\s*$/i.test(o.textContent)) o.textContent = 'cPanel/WHM Pro';
        });
        document.querySelectorAll('td, span, small').forEach(function (el) {
            if (el.children.length === 0 && /^\s*cpanelpro\s*$/i.test(el.textContent)) el.textContent = 'cPanel/WHM Pro';
        });
    } catch (e) {}
})();
</script>
HTML;

        if ($route === 'admin.config.servers') {
            return $out.<<<'HTML'
<script>
(function () {
    try {
        if (typeof SERVER_TYPE_TUNING === 'undefined' || !SERVER_TYPE_TUNING.cpanel) return;
        SERVER_TYPE_TUNING.cpanelpro = Object.assign({}, SERVER_TYPE_TUNING.cpanel, {
            hint: SERVER_TYPE_TUNING.cpanel.hint.replace('<strong>cPanel/WHM:</strong>', '<strong>cPanel/WHM Pro:</strong>')
                + ' Adds the client "Log in to cPanel" button, live usage, and staff cPanel/WHM logins.'
        });
        document.querySelectorAll('select[name="type"]').forEach(function (s) {
            if (s.value === 'cpanelpro') s.dispatchEvent(new Event('change', { bubbles: true }));
        });
    } catch (e) {}
})();
</script>
HTML;
        }

        if ($route === 'admin.live-servers.index' && Route::has('cpanelpro.admin.whm')) {
            $servers = Server::whereRaw('LOWER(type) = ?', ['cpanelpro'])->orderBy('name')->get();
            if ($servers->isNotEmpty()) {
                $out .= view('cpanelpro::live-servers', ['servers' => $servers])->render();
            }
        }

        return $out;
    } catch (\Throwable) {
    }

    return '';
});
