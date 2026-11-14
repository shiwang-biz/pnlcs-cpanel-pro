{{-- cPanel/WHM Pro: staff links on the admin service page. --}}
<div class="card" style="margin-bottom:15px;">
    <div class="card-header"><strong>cPanel / WHM</strong></div>
    <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <form method="POST" action="{{ route('cpanelpro.admin.cpanel', $service) }}" target="_blank" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">Log in to cPanel as {{ $service->username }} ↗</button>
        </form>
        @if($service->server)
        <form method="POST" action="{{ route('cpanelpro.admin.whm', $service->server) }}" target="_blank" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-default btn-sm">Log in to WHM ({{ $service->server->name }}) ↗</button>
        </form>
        @endif
        <span style="font-size:12px;color:#777;">One-time sign-in links from WHM; each use is recorded in the activity log.</span>
    </div>
</div>
