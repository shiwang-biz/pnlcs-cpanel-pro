{{-- Added to the Live Servers page: cPanel/WHM Pro servers, one click from WHM. --}}
<div id="cpanelpro-live" class="card" style="margin:15px 0;">
    <div class="card-header"><strong>cPanel/WHM Pro servers</strong></div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;font-size:13px;">
            <thead><tr><th>Name</th><th>Hostname</th><th>IP address</th><th>Status</th><th>Load (1/5/15 min)</th><th>Disk</th><th>Accounts</th><th style="text-align:right"></th></tr></thead>
            <tbody>
            @foreach($servers as $s)
                <tr>
                    <td><strong>{{ $s->name }}</strong></td>
                    <td>{{ $s->hostname }}</td>
                    <td>{{ $s->ip_address }}</td>
                    <td>{!! $s->active ? '<span class="badge" style="background:#46A546;color:#fff">Active</span>' : '<span class="badge" style="background:#999;color:#fff">Inactive</span>' !!}</td>
                    <td class="cp-h-load" data-health="{{ route('cpanelpro.admin.health', $s) }}"><span style="color:#999">…</span></td>
                    <td class="cp-h-disk"><span style="color:#999">…</span></td>
                    <td class="cp-h-acc"><span style="color:#999">…</span></td>
                    <td style="text-align:right;">
                        <form method="POST" action="{{ route('cpanelpro.admin.whm', $s) }}" target="_blank" style="margin:0;display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-xs">Log in to WHM ↗</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
    // Move the box into the page body, under the Panelica servers.
    var box = document.getElementById('cpanelpro-live'), area = document.getElementById('contentarea');
    if (box && area) area.appendChild(box);

    // Server health, fetched after the page has loaded (a slow server can't hold it up).
    function esc(t) { var d = document.createElement('div'); d.textContent = String(t); return d.innerHTML; }
    function bar(p) {
        var c = p >= 90 ? '#dc2626' : (p >= 75 ? '#d97706' : '#16a34a');
        return '<span style="display:inline-block;width:60px;height:6px;background:#e5e7eb;border-radius:3px;vertical-align:middle;margin-right:5px"><span style="display:block;height:6px;width:' + Math.min(100, p) + '%;background:' + c + ';border-radius:3px"></span></span>';
    }
    document.querySelectorAll('#cpanelpro-live .cp-h-load').forEach(function (cell) {
        var row = cell.parentNode;
        fetch(cell.getAttribute('data-health'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (h) {
                cell.innerHTML = h.load ? esc(h.load.one.toFixed(2) + ' / ' + h.load.five.toFixed(2) + ' / ' + h.load.fifteen.toFixed(2)) : '<span style="color:#999">n/a</span>';
                row.querySelector('.cp-h-disk').innerHTML = (h.disks && h.disks.length) ? h.disks.map(function (d) {
                    return '<div style="white-space:nowrap">' + bar(d.percent) + esc(d.mount + ' ' + d.percent + '% (' + d.used_gb + '/' + d.total_gb + ' GB)') + '</div>';
                }).join('') : '<span style="color:#999">n/a</span>';
                row.querySelector('.cp-h-acc').innerHTML = h.accounts !== null ? esc(h.accounts) : '<span style="color:#999">n/a</span>';
            })
            .catch(function () {
                cell.innerHTML = '<span style="color:#dc2626">unreachable</span>';
                row.querySelector('.cp-h-disk').textContent = '';
                row.querySelector('.cp-h-acc').textContent = '';
            });
    });
})();
</script>
