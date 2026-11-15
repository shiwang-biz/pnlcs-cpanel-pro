<div class="cp-card">
    <div class="cp-ch"><i class="ri-user-settings-line"></i>WordPress administrator</div>
    @if(empty($account))
        <div class="cp-note"><i class="ri-information-line"></i>These settings can't be read for this site right now.</div>
    @else
    <form method="POST" action="{{ $r('account') }}" class="cp-form">@csrf
        <div class="cp-fld"><label class="cp-lbl">Administrator</label>
            <select name="login" class="cp-inp">@foreach($account['availableLogins'] ?? [] as $l)<option value="{{ $l['login'] }}" @selected($l['login'] === ($account['currentLogin'] ?? ''))>{{ $l['title'] ?: $l['login'] }}</option>@endforeach</select></div>
        <div class="cp-fld"><label class="cp-lbl">Email</label><input type="email" name="email" class="cp-inp" value="{{ $account['currentEmail'] ?? '' }}"></div>
        <div class="cp-fld"><label class="cp-lbl">New password (leave empty to keep)</label><input type="password" name="password" class="cp-inp" minlength="8" autocomplete="new-password"></div>
        @if(! empty($account['availableLanguages']))
        <div class="cp-fld"><label class="cp-lbl">Site language</label>
            <select name="language" class="cp-inp">@foreach($account['availableLanguages'] as $lang)<option value="{{ $lang['code'] }}" @selected($lang['code'] === ($account['currentLanguage'] ?? ''))>{{ $lang['title'] }}</option>@endforeach</select></div>
        @endif
        <button type="submit" class="cp-btn"><i class="ri-save-line"></i>Save</button>
    </form>
    <div class="cp-note" style="padding-top:0"><i class="ri-information-line"></i>You don't need the password to manage the site: "Log in to wp-admin" signs you in directly.</div>
    @endif
</div>

@if($plan)
@php($addr = rtrim(preg_replace('#^https?://#i', '', strtolower((string) $site['url'])), '/'))
<div class="cp-card" style="border-color:rgba(220,38,38,.45)">
    <div class="cp-ch" style="color:#dc2626"><i class="ri-delete-bin-2-line"></i>Remove this WordPress site</div>
    <div style="padding:16px 18px;font-size:13.5px;color:var(--text);line-height:1.6">
        This removes:
        <ul style="margin:6px 0 10px 18px;padding:0">
            <li>WordPress's files in <span class="cp-mono">{{ $plan['path'] }}</span> ({{ count($plan['files']) }} items: {{ implode(', ', array_slice($plan['files'], 0, 6)) }}{{ count($plan['files']) > 6 ? ', …' : '' }}). They go to the <strong>trash</strong>, so they can still be recovered in File Manager until the trash is emptied.</li>
            @if($plan['database'])<li>The database <span class="cp-mono">{{ $plan['database'] }}</span>@if($plan['dbUser']) and its user <span class="cp-mono">{{ $plan['dbUser'] }}</span>@endif. These can't be recovered.</li>
            @elseif($plan['databaseShared'])<li>The database is <strong>kept</strong>, because another site uses it.</li>@endif
        </ul>
        @if($plan['others'] > 0)<div style="color:var(--muted)"><i class="ri-information-line"></i> {{ $plan['others'] }} other item(s) in that folder (other sites, folders, .htaccess) are left alone.</div>@endif
        <div style="color:var(--muted)"><i class="ri-information-line"></i> Want a copy first? Make one on the <a href="{{ route('client.services.cpanelpro.wp.show', [$service, $id, 'tab' => 'backups']) }}">Backups</a> tab and download it from File Manager.</div>
    </div>
    @if(empty($plan['files']))
        <div class="cp-note" style="padding-top:0"><i class="ri-error-warning-line"></i>No WordPress files were found in that folder, so there is nothing to remove here.</div>
    @else
    <form method="POST" action="{{ $r('remove') }}" class="cp-form" style="border-top:1px solid var(--border)" onsubmit="return pnConfirm(event, @js('Remove '.$addr.' now?'))">@csrf
        <div class="cp-fld"><label class="cp-lbl">Type <strong class="cp-mono">{{ $addr }}</strong> to confirm</label><input type="text" name="confirm" class="cp-inp cp-mono" required autocomplete="off" placeholder="{{ $addr }}"></div>
        @if($plan['database'])
        <div class="cp-fld" style="flex-basis:100%"><label style="display:flex;gap:8px;align-items:center;font-size:13.5px"><input type="hidden" name="database" value="0"><input type="checkbox" name="database" value="1" checked> Also delete the database</label></div>
        @endif
        <button type="submit" class="cp-btn" style="background:#dc2626"><i class="ri-delete-bin-line"></i>Remove site</button>
    </form>
    @endif
</div>
@endif
