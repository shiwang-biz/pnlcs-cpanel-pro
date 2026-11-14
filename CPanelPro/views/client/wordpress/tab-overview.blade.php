@php
    $sw = [
        ['maintenance', 'Maintenance mode', 'Visitors see a "coming soon" page; you can still log in.', ! empty($f['maintenance']['status']), true],
        ['indexing', 'Search engine indexing', 'Let Google and others list this site.', ! empty($f['indexing']['status']), true],
        ['debug', 'Debugging', 'Show and log PHP errors (turn off on a live site).', ! empty($f['debug']['status']), true],
        ['hotlink', 'Hotlink protection', 'Stop other websites from showing your images.', ! empty($f['hotlinkProtection']['status']), ! empty($f['hotlinkProtection']['available'])],
        ['smart', 'Smart Updates', 'Test updates on a hidden copy first and only apply them if nothing breaks.', ! empty($f['updates']['smart']['status']), ! empty($f['updates']['smart']['available'])],
        ['virtualpatch', 'Vulnerability protection', 'Block attacks on known vulnerabilities until you can update.', ! empty($f['virtualPatches']['status']), ! empty($f['virtualPatches']['available'])],
    ];
@endphp
<div class="cp-card">
    <div class="cp-ch"><i class="ri-toggle-line"></i>Site switches</div>
    @foreach($sw as [$key, $title, $desc, $on, $available])
        @continue(! $available)
        <div class="wp-sw">
            <div><div class="t">{{ $title }}</div><div class="d">{{ $desc }}</div></div>
            <form method="POST" action="{{ $r('toggle') }}" style="margin:0;display:flex;gap:8px;align-items:center">@csrf
                <input type="hidden" name="feature" value="{{ $key }}"><input type="hidden" name="state" value="{{ $on ? 0 : 1 }}">
                <span class="cp-pill {{ $on ? 'ok' : 'mute' }}">{{ $on ? 'On' : 'Off' }}</span>
                <button type="submit" class="cp-act">{{ $on ? 'Turn off' : 'Turn on' }}</button>
            </form>
        </div>
    @endforeach
</div>

@if(! empty($protection['available']))
<div class="cp-card">
    <div class="cp-ch"><i class="ri-lock-password-line"></i>Password protection <span class="cp-pill {{ ! empty($protection['status']) ? 'ok' : 'mute' }} r">{{ ! empty($protection['status']) ? 'On' : 'Off' }}</span></div>
    <form method="POST" action="{{ $r('protection') }}" class="cp-form">@csrf
        @if(empty($protection['status']))
            <input type="hidden" name="state" value="1">
            <div class="cp-fld"><label class="cp-lbl">Username</label><input type="text" name="username" class="cp-inp" required maxlength="32"></div>
            <div class="cp-fld"><label class="cp-lbl">Password (8+ chars)</label><input type="password" name="password" class="cp-inp" required minlength="8" autocomplete="new-password"></div>
            <button type="submit" class="cp-btn"><i class="ri-lock-line"></i>Protect the site</button>
        @else
            <input type="hidden" name="state" value="0">
            <div class="cp-note" style="padding:0">Visitors need username <strong class="cp-mono">&nbsp;{{ $protection['credentials']['username'] ?? '' }}&nbsp;</strong> and its password to see the site.</div>
            <button type="submit" class="cp-btn light"><i class="ri-lock-unlock-line"></i>Remove protection</button>
        @endif
    </form>
</div>
@endif

<div class="cp-card">
    <div class="cp-ch"><i class="ri-tools-line"></i>Tools</div>
    <div class="cp-form">
        <form method="POST" action="{{ $r('cache') }}" style="margin:0">@csrf<button type="submit" class="cp-btn light"><i class="ri-brush-line"></i>Clear cache</button></form>
        @if(count($sets ?? []))
        <form method="POST" action="{{ $r('set') }}" style="margin:0;display:flex;gap:8px">@csrf
            <select name="set" class="cp-inp">@foreach($sets as $s)<option value="{{ $s['id'] }}">{{ $s['title'] }}</option>@endforeach</select>
            <button type="submit" class="cp-btn light"><i class="ri-stack-line"></i>Install set</button></form>
        @endif
    </div>
    <div class="cp-wrap"><table class="cp-table"><tbody>
        <tr><td style="color:var(--muted);width:200px">Folder</td><td class="cp-mono">{{ $site['path'] ?? '' }}</td></tr>
        <tr><td style="color:var(--muted)">PHP</td><td>{{ $f['php']['handler']['name'] ?? '—' }} @if(! empty($f['php']['eoled']))<span class="cp-pill warn">outdated: change it under PHP Settings</span>@endif</td></tr>
        <tr><td style="color:var(--muted)">HTTPS</td><td>@if(! empty($site['domain']['ssl']['certificate']['installed']) && empty($site['domain']['ssl']['certificate']['selfSigned']))<span class="cp-pill ok">Certificate installed</span>@else<span class="cp-pill warn">No valid certificate: see SSL / HTTPS</span>@endif</td></tr>
    </tbody></table></div>
</div>
