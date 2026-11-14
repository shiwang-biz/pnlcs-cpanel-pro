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
