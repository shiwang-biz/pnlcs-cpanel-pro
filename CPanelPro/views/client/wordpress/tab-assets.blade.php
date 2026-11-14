@php
    $kind = $tab; $one = $kind === 'plugins' ? 'plugin' : 'theme';
@endphp
<div class="cp-card">
    <div class="cp-ch"><i class="ri-search-line"></i>Add {{ $kind }} from WordPress.org</div>
    <form method="GET" action="{{ route('client.services.cpanelpro.wp.show', [$service, $id]) }}" class="cp-form">
        <input type="hidden" name="tab" value="{{ $kind }}">
        <div class="cp-fld"><input type="text" name="q" value="{{ $q }}" class="cp-inp" placeholder="Search {{ $kind }}, e.g. {{ $kind === 'plugins' ? 'contact form, SEO, WooCommerce' : 'Astra, GeneratePress' }}"></div>
        <button type="submit" class="cp-btn"><i class="ri-search-line"></i>Search</button>
    </form>
    @if($catalog !== null)
        @if(empty($catalog['items']))
            <div class="cp-note"><i class="ri-information-line"></i>Nothing found for "{{ $q }}".</div>
        @else
        <div class="wp-cat">
            @foreach($catalog['items'] as $it)
            <div class="wp-item">
                <div class="h">
                    @if(! empty($it['logoUrl']) || ! empty($it['screenshotUrl']))<img src="{{ $it['logoUrl'] ?: $it['screenshotUrl'] }}" alt="" loading="lazy">@endif
                    <div><div class="n">{!! strip_tags((string) $it['title']) !!}</div><div class="s">{{ $it['author']['name'] ?? '' }} · v{{ $it['version'] ?? '' }}</div></div>
                </div>
                <div class="s">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($it['shortDescription'] ?? '')), 120) }}</div>
                <div class="s">★ {{ number_format(((float) ($it['rating']['score'] ?? 0)) / 20, 1) }} · {{ number_format((int) ($it['downloaded'] ?? 0)) }} downloads</div>
                @if(! empty($it['installed']))
                    <span class="cp-pill ok">Installed</span>
                @else
                <form method="POST" action="{{ $r('asset.install', ['kind' => $kind]) }}" style="margin:0;display:flex;gap:6px">@csrf
                    <input type="hidden" name="slug" value="{{ $it['slug'] }}">
                    <button type="submit" name="activate" value="1" class="cp-btn"><i class="ri-download-2-line"></i>Install &amp; activate</button>
                    <button type="submit" name="activate" value="0" class="cp-act">Install only</button>
                </form>
                @endif
            </div>
            @endforeach
        </div>
        @if($catalog['total'] > 12)
        <div class="cp-note" style="padding-top:0">
            @php($page = max(1, (int) request('page', 1)))
            @if($page > 1)<a class="cp-act" href="{{ route('client.services.cpanelpro.wp.show', [$service, $id, 'tab' => $kind, 'q' => $q, 'page' => $page - 1]) }}">← Previous</a>@endif
            <span>Page {{ $page }} of {{ (int) ceil($catalog['total'] / 12) }}</span>
            @if($page * 12 < $catalog['total'])<a class="cp-act" href="{{ route('client.services.cpanelpro.wp.show', [$service, $id, 'tab' => $kind, 'q' => $q, 'page' => $page + 1]) }}">Next →</a>@endif
        </div>
        @endif
        @endif
    @endif
    <form method="POST" action="{{ $r('asset.upload', ['kind' => $kind]) }}" enctype="multipart/form-data" class="cp-form" style="border-top:1px solid var(--border)">@csrf
        <div class="cp-fld"><label class="cp-lbl">Or upload a {{ $one }} .zip (premium {{ $kind }}, up to 64 MB)</label><input type="file" name="file" accept=".zip" required class="cp-inp"></div>
        <input type="hidden" name="activate" value="1">
        <button type="submit" class="cp-btn light"><i class="ri-upload-2-line"></i>Upload &amp; install</button>
    </form>
</div>

<div class="cp-card">
    <div class="cp-ch"><i class="{{ $kind === 'plugins' ? 'ri-plug-line' : 'ri-palette-line' }}"></i>Installed {{ $kind }} <span class="cp-pill mute">{{ count($assets) }}</span></div>
    @if(empty($assets))
        <div class="cp-note"><i class="ri-information-line"></i>No {{ $kind }} installed.</div>
    @else
    <form method="POST" action="{{ $r('asset.delete', ['kind' => $kind]) }}" id="wp-del" onsubmit="return pnConfirm(event, @js('Delete the selected '.$kind.'? Their files and settings are removed.'))">@csrf</form>
    <div class="cp-wrap"><table class="cp-table">
        <thead><tr><th style="width:30px"></th><th>Name</th><th>Version</th><th>Status</th><th>Auto-update</th></tr></thead>
        <tbody>
        @foreach($assets as $a)
            <tr>
                <td>@if(! ($kind === 'themes' && ! empty($a['status'])) && empty($a['mustUse']))<input type="checkbox" name="slugs[]" value="{{ $a['slug'] }}" form="wp-del" aria-label="Select {{ $a['title'] }}">@endif</td>
                <td><strong>{!! strip_tags((string) $a['title']) !!}</strong>
                    @if(! empty($a['vulnerable']))<span class="cp-pill bad">Vulnerable</span>@endif
                    @if(! empty($a['mustUse']))<span class="cp-pill mute">Must-use</span>@endif
                    <div style="font-size:12px;color:var(--muted)">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($a['description'] ?? '')), 110) }}</div></td>
                <td style="white-space:nowrap">{{ $a['version'] }} @if(! empty($a['availableVersion']))<span class="cp-pill warn">→ {{ $a['availableVersion'] }}</span>@endif</td>
                <td>
                    @if(empty($a['mustUse']))
                    <form method="POST" action="{{ $r('asset.status', ['kind' => $kind]) }}" style="margin:0">@csrf<input type="hidden" name="slug" value="{{ $a['slug'] }}">
                        @if(! empty($a['status']))
                            <span class="cp-pill ok">Active</span>
                            @if($kind === 'plugins')<input type="hidden" name="state" value="0"><button class="cp-act" type="submit">Deactivate</button>@endif
                        @else
                            <input type="hidden" name="state" value="1"><button class="cp-act" type="submit">Activate</button>
                        @endif
                    </form>
                    @endif
                </td>
                <td>
                    @if(! empty($a['autoUpdatesAvailable']))
                    <form method="POST" action="{{ $r('asset.autoupdate', ['kind' => $kind]) }}" style="margin:0">@csrf<input type="hidden" name="slug" value="{{ $a['slug'] }}">
                        <input type="hidden" name="state" value="{{ ! empty($a['autoUpdates']) ? 0 : 1 }}">
                        <button class="cp-act" type="submit">{{ ! empty($a['autoUpdates']) ? 'On' : 'Off' }}</button></form>
                    @else — @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="cp-form" style="border-top:1px solid var(--border)">
        <button type="submit" form="wp-del" class="cp-btn light" style="color:var(--danger,#dc2626)"><i class="ri-delete-bin-line"></i>Delete selected</button>
        <a href="{{ route('client.services.cpanelpro.wp.show', [$service, $id, 'tab' => 'updates']) }}" class="cp-act">Update {{ $kind }} on the Updates tab →</a>
    </div>
    @endif
</div>
