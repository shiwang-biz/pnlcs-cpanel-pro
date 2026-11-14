@extends('client.layouts.app')
@section('title', 'WordPress: '.($site['displayTitle'] ?? $site['title'] ?? ''))
@section('content')
@include('cpanelpro::client._style')
@php
    $id = (int) $site['id'];
    $f = $site['features'] ?? [];
    $r = fn ($name, $extra = []) => route('client.services.cpanelpro.wp.'.$name, array_merge([$service, $id], $extra));
    $tabs = ['overview' => ['Overview', 'ri-dashboard-line'], 'plugins' => ['Plugins', 'ri-plug-line'], 'themes' => ['Themes', 'ri-palette-line'],
        'updates' => ['Updates', 'ri-refresh-line'], 'security' => ['Security', 'ri-shield-check-line'], 'backups' => ['Backups', 'ri-archive-line'],
        'clone' => ['Clone', 'ri-file-copy-2-line'], 'settings' => ['Settings', 'ri-settings-3-line']];
@endphp
<style>
    .wp-tabs{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--border);margin-bottom:18px}
    .wp-tab{padding:10px 14px;font-size:13.5px;font-weight:700;color:var(--muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;display:inline-flex;gap:6px;align-items:center}
    .wp-tab.on{color:var(--primary);border-bottom-color:var(--primary)}
    .wp-sw{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border)}
    .wp-sw:last-child{border-bottom:none}
    .wp-sw .t{font-weight:700;color:var(--text);font-size:13.5px}
    .wp-sw .d{font-size:12.5px;color:var(--muted)}
    .wp-task{padding:10px 18px;border-bottom:1px solid var(--border);font-size:13px}
    .wp-bar{height:6px;background:var(--bg);border-radius:3px;overflow:hidden;margin-top:6px}
    .wp-bar > span{display:block;height:6px;background:var(--primary)}
    .wp-cat{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;padding:18px}
    .wp-item{border:1px solid var(--border);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:8px}
    .wp-item img{width:48px;height:48px;border-radius:8px;object-fit:cover;background:var(--bg)}
    .wp-item .h{display:flex;gap:10px;align-items:center}
    .wp-item .n{font-weight:700;color:var(--text);font-size:13.5px}
    .wp-item .s{font-size:12.5px;color:var(--muted)}
</style>

<a href="{{ route('client.services.cpanelpro.wp', $service) }}" class="cp-back"><i class="ri-arrow-left-line"></i>WordPress sites</a>
<div class="cp-head">
    <div class="cp-head-ic" style="background:linear-gradient(135deg,#21759b,#135e7f)"><i class="ri-wordpress-line"></i></div>
    <div><h1>{{ $site['displayTitle'] ?? $site['title'] }}</h1><div class="sub"><a href="{{ $site['url'] }}" target="_blank" rel="noopener" style="color:var(--muted)">{{ $site['url'] }}</a> · WordPress {{ $site['version'] }}</div></div>
    <div style="margin-left:auto;display:flex;gap:8px">
        <form method="POST" action="{{ $r('login') }}" target="_blank" style="margin:0">@csrf<button type="submit" class="cp-btn"><i class="ri-login-box-line"></i>Log in to wp-admin</button></form>
        <a href="{{ $site['url'] }}" target="_blank" rel="noopener" class="cp-btn light"><i class="ri-external-link-line"></i>Visit</a>
    </div>
</div>

<div class="wp-tabs">
    @foreach($tabs as $k => [$label, $ic])
        <a href="{{ route('client.services.cpanelpro.wp.show', [$service, $id, 'tab' => $k]) }}" class="wp-tab {{ $tab === $k ? 'on' : '' }}"><i class="{{ $ic }}"></i>{{ $label }}</a>
    @endforeach
</div>

{{-- Background jobs (install, update, backup, clone …), refreshed while any is running. --}}
<div class="cp-card" id="wp-tasks" style="display:none" data-url="{{ $r('tasks') }}">
    <div class="cp-ch"><i class="ri-loader-4-line"></i>In progress</div>
    <div data-list></div>
</div>

@include('cpanelpro::client.wordpress.tab-'.(in_array($tab, ['plugins', 'themes'], true) ? 'assets' : $tab))

<script>
(function () {
    var box = document.getElementById('wp-tasks'); if (!box) return;
    var list = box.querySelector('[data-list]'), wasRunning = false, timer;
    function esc(t) { var d = document.createElement('div'); d.textContent = String(t); return d.innerHTML; }
    function poll() {
        fetch(box.dataset.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (tasks) {
                var running = tasks.filter(function (t) { return ['scheduling', 'not_started', 'started', 'running'].indexOf(t.status) !== -1; });
                var failed = tasks.filter(function (t) { return t.status === 'error'; }).slice(0, 2);
                var show = running.concat(failed);
                box.style.display = show.length ? '' : 'none';
                list.innerHTML = show.map(function (t) {
                    var err = t.status === 'error';
                    return '<div class="wp-task"><strong>' + esc(t.title) + '</strong> <span class="cp-pill ' + (err ? 'bad' : 'mute') + '">' + esc(err ? 'failed' : Math.round(t.progress) + '%') + '</span>'
                        + (err && t.errors.length ? '<div style="color:#dc2626;font-size:12px;margin-top:4px">' + esc(t.errors.join(' ')) + '</div>' : '<div class="wp-bar"><span style="width:' + Math.max(3, Math.min(100, t.progress)) + '%"></span></div>') + '</div>';
                }).join('');
                if (running.length) { wasRunning = true; timer = setTimeout(poll, 4000); }
                else if (wasRunning) { location.reload(); } // finished: show the new state
            }).catch(function () {});
    }
    poll();
})();
</script>
@endsection
