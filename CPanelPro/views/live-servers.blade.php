{{-- Added to the Live Servers page: cPanel/WHM Pro servers, one click from WHM. --}}
<div id="cpanelpro-live" class="card" style="margin:15px 0;">
    <div class="card-header"><strong>cPanel/WHM Pro servers</strong></div>
    <div class="card-body" style="padding:0;">
        <table class="table" style="margin:0;font-size:13px;">
            <thead><tr><th>Name</th><th>Hostname</th><th>IP address</th><th>Status</th><th style="text-align:right"></th></tr></thead>
            <tbody>
            @foreach($servers as $s)
                <tr>
                    <td><strong>{{ $s->name }}</strong></td>
                    <td>{{ $s->hostname }}</td>
                    <td>{{ $s->ip_address }}</td>
                    <td>{!! $s->active ? '<span class="badge" style="background:#46A546;color:#fff">Active</span>' : '<span class="badge" style="background:#999;color:#fff">Inactive</span>' !!}</td>
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
})();
</script>
