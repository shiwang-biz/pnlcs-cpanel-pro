@extends('client.layouts.app')
@section('title', 'Domains')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-global-line', 'c1' => '#2563eb', 'c2' => '#1d4ed8', 'title' => 'Domains', 'sub' => 'Host more websites on this account, or point extra domains at your main site.'])

<div class="cp-card">
    <div class="cp-ch"><i class="ri-home-4-line"></i>Main domain <span class="cp-mono" style="font-weight:600">{{ $list['main'] }}</span></div>
</div>

<div class="cp-card">
    <div class="cp-ch"><i class="ri-add-box-line"></i>Addon domains (a separate website each)</div>
    <form method="POST" action="{{ route('client.services.cpanelpro.domains.addon.store', $service) }}" class="cp-form">
        @csrf
        <div class="cp-fld"><label class="cp-lbl">Domain</label><input type="text" name="domain" required class="cp-inp" placeholder="mynewsite.com"></div>
        <div class="cp-fld"><label class="cp-lbl">Folder (optional)</label><input type="text" name="dir" class="cp-inp" placeholder="public_html/mynewsite.com"></div>
        <button type="submit" class="cp-btn"><i class="ri-add-line"></i>Add domain</button>
    </form>
    @if(count($list['addons']))
    <div class="cp-wrap"><table class="cp-table"><thead><tr><th>Domain</th><th>Folder</th><th></th></tr></thead><tbody>
        @foreach($list['addons'] as $a)
        <tr><td class="cp-mono">{{ $a['domain'] }}</td><td class="cp-mono" style="color:var(--muted)">{{ $a['dir'] }}</td>
            <td style="text-align:right"><form method="POST" action="{{ route('client.services.cpanelpro.domains.addon.destroy', $service) }}" style="margin:0" onsubmit="return pnConfirm(event, @js('Remove '.$a['domain'].' from this account? Its files are kept.'))">
                @csrf<input type="hidden" name="domain" value="{{ $a['domain'] }}"><button class="cp-act danger" type="submit"><i class="ri-delete-bin-line"></i>Remove</button></form></td></tr>
        @endforeach
    </tbody></table></div>
    @endif
</div>

<div class="cp-card">
    <div class="cp-ch"><i class="ri-links-line"></i>Aliases (show the main website)</div>
    <form method="POST" action="{{ route('client.services.cpanelpro.domains.alias.store', $service) }}" class="cp-form">
        @csrf
        <div class="cp-fld"><label class="cp-lbl">Domain</label><input type="text" name="domain" required class="cp-inp" placeholder="{{ $list['main'] ? 'my-'.$list['main'] : 'example.net' }}"></div>
        <button type="submit" class="cp-btn"><i class="ri-add-line"></i>Add alias</button>
    </form>
    @if(count($list['aliases']))
    <div class="cp-wrap"><table class="cp-table"><tbody>
        @foreach($list['aliases'] as $al)
        <tr><td class="cp-mono">{{ $al }}</td>
            <td style="text-align:right"><form method="POST" action="{{ route('client.services.cpanelpro.domains.alias.destroy', $service) }}" style="margin:0" onsubmit="return pnConfirm(event, @js('Remove this alias?'))">
                @csrf<input type="hidden" name="domain" value="{{ $al }}"><button class="cp-act danger" type="submit"><i class="ri-delete-bin-line"></i>Remove</button></form></td></tr>
        @endforeach
    </tbody></table></div>
    @endif
</div>
<div class="cp-note"><i class="ri-information-line"></i>A domain works here once its nameservers (or A record) point to this server.</div>
@endsection
