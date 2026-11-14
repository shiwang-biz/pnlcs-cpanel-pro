@php
    $dbDefault = $stub['value']['database']['value']['name']['value'] ?? '';
    $subName = $stub['value']['domain']['value']['newSubdomain']['value']['name']['value'] ?? 'staging';
    $subParent = $stub['value']['domain']['value']['newSubdomain']['value']['parentDomain']['value'] ?? '';
@endphp
<div class="cp-card">
    <div class="cp-ch"><i class="ri-file-copy-2-line"></i>Clone this site</div>
    <div class="cp-note" style="padding-bottom:0"><i class="ri-information-line"></i>Makes a full copy (files and database), e.g. a staging site to try changes safely. Search engines won't index the copy.</div>
    <form method="POST" action="{{ $r('clone') }}" class="cp-form">@csrf
        <div class="cp-fld" style="flex-basis:100%">
            <label style="display:flex;gap:8px;align-items:center;font-size:13.5px"><input type="radio" name="target" value="sub" checked> To a new subdomain</label>
        </div>
        <div class="cp-fld"><label class="cp-lbl">Subdomain</label><input type="text" name="name" class="cp-inp" value="{{ $subName }}"></div>
        <div class="cp-fld"><label class="cp-lbl">of</label><select name="parent" class="cp-inp">@foreach($domains as $d)<option value="{{ $d }}" @selected($d === $subParent)>{{ $d }}</option>@endforeach</select></div>
        <div class="cp-fld" style="flex-basis:100%">
            <label style="display:flex;gap:8px;align-items:center;font-size:13.5px"><input type="radio" name="target" value="existing"> To an existing domain or subdomain</label>
        </div>
        <div class="cp-fld"><label class="cp-lbl">Domain</label><select name="existing" class="cp-inp">@foreach(array_unique(array_merge(array_values($domains), $subdomains)) as $d)<option value="{{ $d }}">{{ $d }}</option>@endforeach</select></div>
        <div class="cp-fld"><label class="cp-lbl">Folder (optional)</label><input type="text" name="path" class="cp-inp" placeholder="empty = the domain itself"></div>
        <div class="cp-fld"><label class="cp-lbl">New database name</label><input type="text" name="database" class="cp-inp cp-mono" value="{{ $dbDefault }}"></div>
        <button type="submit" class="cp-btn"><i class="ri-file-copy-2-line"></i>Clone</button>
    </form>
</div>
