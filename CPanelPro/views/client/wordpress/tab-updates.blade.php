@php
    $u = $updates ?? [];
    $coreNew = $u['core']['availableVersion'] ?? '';
    $pU = collect($u['plugins'] ?? [])->filter(fn ($p) => ($p['availableVersion'] ?? '') !== '');
    $tU = collect($u['themes'] ?? [])->filter(fn ($t) => ($t['availableVersion'] ?? '') !== '');
    $s = $settings ?? [];
@endphp
<div class="cp-card">
    <div class="cp-ch"><i class="ri-refresh-line"></i>Available updates
        @if(! empty($u['lastCheckUpdateTimestamp']))<span style="font-weight:500;color:var(--muted);font-size:12px">checked {{ \Carbon\Carbon::createFromTimestamp($u['lastCheckUpdateTimestamp'])->diffForHumans() }}</span>@endif
        <form method="POST" action="{{ $r('updates.check') }}" class="r" style="margin:0">@csrf<button type="submit" class="cp-act"><i class="ri-search-line"></i>Check now</button></form>
    </div>
    @if($coreNew === '' && $pU->isEmpty() && $tU->isEmpty())
        <div class="cp-note"><i class="ri-checkbox-circle-line" style="color:#16a34a"></i>Everything is up to date.</div>
    @else
    <form method="POST" action="{{ $r('updates.apply') }}">@csrf
        <div class="cp-wrap"><table class="cp-table"><tbody>
            @if($coreNew !== '')
                <tr><td style="width:30px"><input type="checkbox" name="core" value="1" checked aria-label="Update WordPress"></td><td><strong>WordPress</strong></td><td>{{ $u['core']['version'] ?? '' }} → <strong>{{ $coreNew }}</strong></td></tr>
            @endif
            @foreach($pU as $p)
                <tr><td><input type="checkbox" name="plugins[]" value="{{ $p['slug'] }}" checked aria-label="Update {{ $p['title'] }}"></td><td>{!! strip_tags((string) $p['title']) !!} <span class="cp-pill mute">plugin</span> @if(! empty($p['vulnerable']))<span class="cp-pill bad">Vulnerable</span>@endif</td><td>{{ $p['version'] }} → <strong>{{ $p['availableVersion'] }}</strong></td></tr>
            @endforeach
            @foreach($tU as $t)
                <tr><td><input type="checkbox" name="themes[]" value="{{ $t['slug'] }}" checked aria-label="Update {{ $t['title'] }}"></td><td>{!! strip_tags((string) $t['title']) !!} <span class="cp-pill mute">theme</span></td><td>{{ $t['version'] }} → <strong>{{ $t['availableVersion'] }}</strong></td></tr>
            @endforeach
        </tbody></table></div>
        <div class="cp-form" style="border-top:1px solid var(--border)">
            <button type="submit" class="cp-btn"><i class="ri-download-cloud-2-line"></i>Update selected</button>
            <span style="font-size:12.5px;color:var(--muted)">A restore point is made first, so a broken update can be rolled back.</span>
        </div>
    </form>
    @endif
</div>

@if(! empty($s['available']))
<div class="cp-card">
    <div class="cp-ch"><i class="ri-time-line"></i>Automatic updates</div>
    <form method="POST" action="{{ $r('updates.settings') }}" class="cp-form" style="flex-direction:column;align-items:stretch">@csrf
        <div class="cp-fld" style="max-width:360px"><label class="cp-lbl">WordPress itself</label>
            <select name="core" class="cp-inp">
                <option value="minor" @selected(($s['core'] ?? '') === 'minor')>Security (minor) updates only</option>
                <option value="major" @selected(($s['core'] ?? '') === 'major')>All updates</option>
                <option value="disabled" @selected(($s['core'] ?? '') === 'disabled')>No automatic updates</option>
            </select></div>
        @php($cb = fn ($name, $checked, $label) => '<label style="display:flex;gap:8px;align-items:center;font-size:13.5px;color:var(--text)"><input type="checkbox" name="'.$name.'" value="1" '.($checked ? 'checked' : '').'> '.e($label).'</label>')
        <div><div class="cp-lbl">Plugins</div>
            {!! $cb('plugins_all', $s['plugins']['forceUpdates'] ?? false, 'Update all plugins automatically') !!}
            {!! $cb('plugins_new', $s['plugins']['newlyInstalledUpdates'] ?? false, 'Turn on auto-updates for newly installed plugins') !!}
            {!! $cb('plugins_vulnerable', $s['plugins']['updateVulnerable'] ?? false, 'Always update plugins with known vulnerabilities') !!}
            {!! $cb('plugins_deactivate', $s['plugins']['deactivateVulnerable'] ?? false, 'Deactivate vulnerable plugins that have no fix yet') !!}</div>
        <div><div class="cp-lbl">Themes</div>
            {!! $cb('themes_all', $s['themes']['forceUpdates'] ?? false, 'Update all themes automatically') !!}
            {!! $cb('themes_new', $s['themes']['newlyInstalledUpdates'] ?? false, 'Turn on auto-updates for newly installed themes') !!}
            {!! $cb('themes_vulnerable', $s['themes']['updateVulnerable'] ?? false, 'Always update themes with known vulnerabilities') !!}</div>
        @if(array_key_exists('safeUpdate', $s))
            <input type="hidden" name="safe" value="0">
            {!! $cb('safe', $s['safeUpdate'] ?? false, 'Use Smart Updates for automatic updates (test on a copy first)') !!}
        @endif
        <div><button type="submit" class="cp-btn"><i class="ri-save-line"></i>Save</button></div>
    </form>
</div>
@endif
