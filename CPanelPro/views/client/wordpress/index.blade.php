@extends('client.layouts.app')
@section('title', 'WordPress')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-wordpress-line', 'c1' => '#21759b', 'c2' => '#135e7f', 'title' => 'WordPress', 'sub' => 'Install WordPress and manage your sites, plugins, themes, updates, security and backups.'])
<style>
    .wp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;padding:18px}
    .wp-site{border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--card);display:flex;flex-direction:column}
    .wp-shot{height:150px;background:var(--bg) center top/cover no-repeat;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:40px}
    .wp-body{padding:14px;display:flex;flex-direction:column;gap:8px;flex:1}
    .wp-title{font-weight:800;color:var(--text);font-size:15px}
    .wp-url{font-size:12.5px;color:var(--muted);word-break:break-all}
    .wp-pills{display:flex;gap:6px;flex-wrap:wrap}
    .wp-actions{display:flex;gap:8px;margin-top:auto;flex-wrap:wrap}
    details.cp-card > summary{cursor:pointer;list-style:none}
    details.cp-card > summary::-webkit-details-marker{display:none}
</style>

@if(! $list['ok'])
    <div class="cp-card"><div class="cp-note"><i class="ri-error-warning-line" style="color:#dc2626"></i>{{ $list['message'] ?: 'WP Toolkit is not available right now.' }}</div></div>
@else

<details class="cp-card" @if(empty($list['sites']) || $errors->any()) open @endif>
    <summary class="cp-ch"><i class="ri-add-circle-line"></i>Install WordPress <span class="r" style="font-weight:600;color:var(--muted);font-size:12px">click to open</span></summary>
    <form method="POST" action="{{ route('client.services.cpanelpro.wp.install', $service) }}" class="cp-form">
        @csrf
        <div class="cp-fld"><label class="cp-lbl">Domain</label>
            <select name="domain" class="cp-inp" required>@foreach($domains as $d)<option value="{{ $d }}" @selected(old('domain') === $d)>{{ $d }}</option>@endforeach</select></div>
        <div class="cp-fld"><label class="cp-lbl">Folder (empty = the domain itself)</label><input type="text" name="path" class="cp-inp" placeholder="blog" value="{{ old('path') }}"></div>
        <div class="cp-fld" style="flex-basis:100%"><label class="cp-lbl">Site title</label><input type="text" name="title" class="cp-inp" placeholder="My WordPress site" value="{{ old('title') }}"></div>
        <div class="cp-fld"><label class="cp-lbl">Admin username (optional)</label><input type="text" name="admin_login" class="cp-inp" autocomplete="off" value="{{ old('admin_login') }}"></div>
        <div class="cp-fld"><label class="cp-lbl">Admin email (optional)</label><input type="email" name="admin_email" class="cp-inp" value="{{ old('admin_email', auth()->user()?->email) }}"></div>
        <div class="cp-fld"><label class="cp-lbl">Admin password (optional, 8+ chars)</label><input type="password" name="admin_password" class="cp-inp" autocomplete="new-password"></div>
        <div class="cp-fld"><label class="cp-lbl">WordPress updates</label>
            <select name="core_updates" class="cp-inp"><option value="minor">Security updates only (recommended)</option><option value="major">All updates</option><option value="disabled">None</option></select></div>
        @if(count($sets))
        <div class="cp-fld"><label class="cp-lbl">Plugin/theme set</label>
            <select name="set" class="cp-inp"><option value="">None</option>@foreach($sets as $s)<option value="{{ $s['id'] }}">{{ $s['title'] }} ({{ count($s['plugins'] ?? []) }} plugins, {{ count($s['themes'] ?? []) }} themes)</option>@endforeach</select></div>
        @endif
        <button type="submit" class="cp-btn"><i class="ri-download-2-line"></i>Install</button>
    </form>
    <div class="cp-note" style="padding-top:0"><i class="ri-information-line"></i>Leave the admin fields empty and WP Toolkit picks a secure login for you. Use "Log in" afterwards, so you don't need the password.</div>
</details>

<div class="cp-card">
    <div class="cp-ch"><i class="ri-stack-line"></i>Your WordPress sites <span class="cp-pill mute">{{ count($list['sites']) }}</span>
        <form method="POST" action="{{ route('client.services.cpanelpro.wp.scan', $service) }}" class="r" style="margin:0">@csrf
            <button type="submit" class="cp-act" title="Find WordPress sites installed another way"><i class="ri-search-eye-line"></i>Scan for sites</button></form>
        <a href="{{ route('client.services.cpanelpro.wp', $service) }}" class="cp-act"><i class="ri-refresh-line"></i>Refresh</a>
    </div>
    @if(empty($list['sites']))
        <div class="cp-note"><i class="ri-information-line"></i>No WordPress sites yet. Install one above, or "Scan for sites" if you installed WordPress another way.</div>
    @else
    <div class="wp-grid">
        @foreach($list['sites'] as $s)
        @php
            $f = $s['features'] ?? [];
            $upd = (int) ($f['updates']['amountOfPluginsWithUpdates'] ?? 0) + (int) ($f['updates']['amountOfThemesWithUpdates'] ?? 0) + (! empty($f['updates']['availableVersion']) ? 1 : 0);
            $sec = $f['security']['status'] ?? 'unknown';
        @endphp
        <div class="wp-site">
            <div class="wp-shot" @if(! empty($s['screenshot']['url'])) style="background-image:url('{{ $s['screenshot']['url'] }}')" @endif>@if(empty($s['screenshot']['url']))<i class="ri-wordpress-line"></i>@endif</div>
            <div class="wp-body">
                <div class="wp-title">{{ $s['displayTitle'] ?: $s['title'] }}</div>
                <a class="wp-url" href="{{ $s['url'] }}" target="_blank" rel="noopener">{{ $s['url'] }}</a>
                <div class="wp-pills">
                    <span class="cp-pill mute">WordPress {{ $s['version'] }}</span>
                    @if(! empty($s['status']['infected']))<span class="cp-pill bad">Infected</span>@endif
                    @if(isset($s['status']['alive']) && ! $s['status']['alive'])<span class="cp-pill bad">Not responding</span>@endif
                    @if(! empty($f['vulnerability']['vulnerable']))<span class="cp-pill bad">Vulnerable</span>@endif
                    @if($upd)<span class="cp-pill warn">{{ $upd }} update{{ $upd > 1 ? 's' : '' }}</span>@endif
                    @if($sec === 'insecure')<span class="cp-pill warn">Security: needs attention</span>@elseif(str_starts_with($sec, 'secure'))<span class="cp-pill ok">Secured</span>@endif
                    @if(! empty($f['maintenance']['status']))<span class="cp-pill warn">Maintenance on</span>@endif
                </div>
                @if(! empty($s['errorMessage']))<div style="font-size:12px;color:#dc2626">{{ $s['errorMessage'] }}</div>@endif
                <div class="wp-actions">
                    <form method="POST" action="{{ route('client.services.cpanelpro.wp.login', [$service, $s['id']]) }}" target="_blank" style="margin:0">@csrf
                        <button type="submit" class="cp-btn"><i class="ri-login-box-line"></i>Log in</button></form>
                    <a href="{{ route('client.services.cpanelpro.wp.show', [$service, $s['id']]) }}" class="cp-btn light"><i class="ri-settings-4-line"></i>Manage</a>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endif
@endsection
