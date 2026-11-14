@extends('client.layouts.app')
@section('title', 'File Tools')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-file-zip-line', 'c1' => '#ca8a04', 'c2' => '#a16207', 'title' => 'File Tools', 'sub' => 'Extract archives, create zip files and change permissions.'])
@php
    $home = $list['home'];
    $rel = ltrim(substr($list['path'], strlen($home)), '/');
    $crumbs = $rel === '' ? [] : explode('/', $rel);
    $up = $rel === '' ? null : (dirname($rel) === '.' ? '' : dirname($rel));
@endphp

<div class="cp-card">
    <div class="cp-ch"><i class="ri-folder-open-line"></i>
        <a href="{{ route('client.services.cpanelpro.files', $service) }}" style="color:var(--text);text-decoration:none">home</a>
        @foreach($crumbs as $i => $c)
            <span style="color:var(--muted)">/</span>
            <a href="{{ route('client.services.cpanelpro.files', ['service' => $service, 'path' => implode('/', array_slice($crumbs, 0, $i + 1))]) }}" style="color:var(--text);text-decoration:none">{{ $c }}</a>
        @endforeach
        <a href="{{ route('client.services.files', $service) }}" class="cp-act r"><i class="ri-folder-2-line"></i>File Manager</a>
    </div>

    @if(! $list['ok'])
        <div class="cp-note"><i class="ri-error-warning-line" style="color:#dc2626"></i>{{ $list['message'] }}</div>
    @else
    <form method="POST" id="cp-bulk">
        @csrf
        <div class="cp-wrap"><table class="cp-table">
            <thead><tr><th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('.cp-sel').forEach(c => c.checked = this.checked)" aria-label="Select all"></th><th>Name</th><th>Size</th><th>Permissions</th><th></th></tr></thead>
            <tbody>
            @if($up !== null)
                <tr><td></td><td colspan="4"><a href="{{ route('client.services.cpanelpro.files', ['service' => $service, 'path' => $up]) }}" style="color:var(--muted);text-decoration:none"><i class="ri-arrow-up-line"></i> Up one level</a></td></tr>
            @endif
            @forelse($list['entries'] as $e)
                <tr>
                    <td><input type="checkbox" class="cp-sel" name="paths[]" value="{{ $e['path'] }}" aria-label="Select {{ $e['name'] }}"></td>
                    <td>
                        @if($e['type'] === 'folder')
                            <a href="{{ route('client.services.cpanelpro.files', ['service' => $service, 'path' => ltrim(substr($e['path'], strlen($home)), '/')]) }}" style="color:var(--text);font-weight:600;text-decoration:none"><i class="ri-folder-fill" style="color:#ca8a04"></i> {{ $e['name'] }}</a>
                        @else
                            <i class="{{ \Modules\Servers\CPanelPro\CPanelProModule::isArchive($e['name']) ? 'ri-file-zip-line' : 'ri-file-line' }}" style="color:var(--muted)"></i> {{ $e['name'] }}
                        @endif
                    </td>
                    <td style="color:var(--muted)">{{ $e['size_formatted'] }}</td>
                    <td class="cp-mono">{{ $e['permissions_octal'] }}</td>
                    <td style="text-align:right">
                        @if($e['type'] === 'file' && \Modules\Servers\CPanelPro\CPanelProModule::isArchive($e['name']))
                            <button type="submit" class="cp-act" formaction="{{ route('client.services.cpanelpro.files.extract', $service) }}" name="archive" value="{{ $e['path'] }}"><i class="ri-inbox-unarchive-line"></i>Extract here</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td></td><td colspan="4" style="color:var(--muted)">This folder is empty.</td></tr>
            @endforelse
            </tbody>
        </table></div>

        <div class="cp-form" style="border-top:1px solid var(--border)">
            <div class="cp-fld"><label class="cp-lbl">Compress selected into</label><input type="text" name="name" class="cp-inp" placeholder="backup" value="archive"></div>
            <div class="cp-fld" style="max-width:140px"><label class="cp-lbl">Type</label>
                <select name="type" class="cp-inp">@foreach(\Modules\Servers\CPanelPro\CPanelProModule::ARCHIVE_TYPES as $t)<option value="{{ $t }}">.{{ $t }}</option>@endforeach</select></div>
            <button type="submit" class="cp-btn" formaction="{{ route('client.services.cpanelpro.files.compress', $service) }}"><i class="ri-file-zip-line"></i>Compress</button>
            <div class="cp-fld" style="max-width:150px"><label class="cp-lbl">Permissions for selected</label><input type="text" name="mode" class="cp-inp cp-mono" placeholder="644" maxlength="4" pattern="0?[0-7]{3}"></div>
            <button type="submit" class="cp-btn light" formaction="{{ route('client.services.cpanelpro.files.chmod', $service) }}"><i class="ri-lock-2-line"></i>Set permissions</button>
        </div>
    </form>
    @endif
</div>
<div class="cp-note"><i class="ri-information-line"></i>Usual permissions: 644 for files, 755 for folders. Avoid 777. Archives are extracted into the folder they're in, and existing files with the same names are replaced.</div>
@endsection
