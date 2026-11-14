{{-- cPanel/WHM Pro: extra tools, added to the service page's hosting-tools grid. --}}
@php
    $tiles = [
        ['route' => route('client.services.cpanelpro.wp', $service), 'name' => 'WordPress', 'desc' => 'Install and manage WordPress sites, plugins and themes.', 'ic' => 'ri-wordpress-line', 'c' => '#21759b'],
        ['route' => route('client.services.cpanelpro.ssl', $service), 'name' => 'SSL / HTTPS', 'desc' => 'Certificates, AutoSSL and the HTTPS redirect.', 'ic' => 'ri-shield-check-line', 'c' => '#16a34a'],
        ['route' => route('client.services.cpanelpro.php', $service), 'name' => 'PHP Settings', 'desc' => 'PHP version and limits for each domain.', 'ic' => 'ri-code-s-slash-line', 'c' => '#7c3aed'],
        ['route' => route('client.services.cpanelpro.email', $service), 'name' => 'Email Extras', 'desc' => 'Forwarders, autoresponders and catch-all.', 'ic' => 'ri-mail-send-line', 'c' => '#db2777'],
        ['route' => route('client.services.cpanelpro.webmail', $service), 'name' => 'Webmail', 'desc' => 'Open a mailbox in webmail, already signed in.', 'ic' => 'ri-inbox-2-line', 'c' => '#e11d48'],
        ['form' => route('client.services.cpanelpro.phpmyadmin', $service), 'name' => 'phpMyAdmin', 'desc' => 'Open your databases, already signed in.', 'ic' => 'ri-database-2-line', 'c' => '#0284c7'],
        ['route' => route('client.services.cpanelpro.domains', $service), 'name' => 'Domains', 'desc' => 'Addon domains and aliases on this account.', 'ic' => 'ri-global-line', 'c' => '#2563eb'],
        ['route' => route('client.services.cpanelpro.files', $service), 'name' => 'File Tools', 'desc' => 'Unzip, zip and change permissions.', 'ic' => 'ri-file-zip-line', 'c' => '#ca8a04'],
        ['route' => route('client.services.cpanelpro.errors', $service), 'name' => 'Error Log', 'desc' => 'Your websites\' latest errors.', 'ic' => 'ri-bug-line', 'c' => '#ea580c'],
        ['route' => route('client.services.cpanelpro.ipblock', $service), 'name' => 'IP Blocker', 'desc' => 'Stop addresses from opening your sites.', 'ic' => 'ri-forbid-2-line', 'c' => '#475569'],
    ];
@endphp
<div id="cpanelpro-tools">
    <div class="sv-sec" data-cp-own><i class="ri-tools-line"></i>More tools</div>
    <div class="sv-apps" data-cp-own>
    @foreach($tiles as $t)
        @if(isset($t['form']))
        <form method="POST" action="{{ $t['form'] }}" target="_blank" class="cp-tile" style="margin:0;display:contents">
            @csrf
            <button type="submit" class="sv-app live" style="font:inherit;text-align:left;cursor:pointer;width:100%">
                <span class="sv-aic" style="background:{{ $t['c'] }}1f;color:{{ $t['c'] }}"><i class="{{ $t['ic'] }}"></i></span>
                <div><div class="sv-aname">{{ $t['name'] }}</div><div class="sv-adesc">{{ $t['desc'] }}</div></div>
            </button>
        </form>
        @else
        <a href="{{ $t['route'] }}" class="sv-app live cp-tile">
            <span class="sv-aic" style="background:{{ $t['c'] }}1f;color:{{ $t['c'] }}"><i class="{{ $t['ic'] }}"></i></span>
            <div><div class="sv-aname">{{ $t['name'] }}</div><div class="sv-adesc">{{ $t['desc'] }}</div></div>
        </a>
        @endif
    @endforeach
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('cpanelpro-tools');
    if (!box) return;
    // The service page's own hosting-tools grid (the first one that isn't ours).
    var grids = Array.prototype.filter.call(document.querySelectorAll('.sv-apps'), function (g) { return !box.contains(g); });
    if (!grids.length) return; // no grid: our own section stays as it is
    var grid = grids[0];
    box.querySelectorAll('.cp-tile').forEach(function (t) { grid.appendChild(t); });
    box.remove();
});
</script>
