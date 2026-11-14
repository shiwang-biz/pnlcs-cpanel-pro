@extends('client.layouts.app')
@section('title', 'PHP Settings')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-code-s-slash-line', 'c1' => '#7c3aed', 'c2' => '#6d28d9', 'title' => 'PHP Settings', 'sub' => 'Choose the PHP version and common limits for each domain.'])
@php($labels = ['memory_limit' => ['Memory limit', '256M'], 'upload_max_filesize' => ['Max upload size', '64M'], 'post_max_size' => ['Max POST size', '64M'], 'max_execution_time' => ['Max execution time (seconds)', '300'], 'max_input_time' => ['Max input time (seconds)', '300'], 'max_input_vars' => ['Max input vars', '3000']])

<div class="cp-card">
    <div class="cp-ch"><i class="ri-stack-line"></i>PHP version per domain</div>
    @if(empty($overview['domains']))
        <div class="cp-note"><i class="ri-information-line"></i>PHP versions can't be read on this server right now.</div>
    @else
    <div class="cp-wrap"><table class="cp-table"><tbody>
        @foreach($overview['domains'] as $d)
        <tr>
            <td class="cp-mono">{{ $d['domain'] }}</td>
            <td>
                <form method="POST" action="{{ route('client.services.cpanelpro.php.version', $service) }}" style="margin:0;display:flex;gap:8px;justify-content:flex-end">
                    @csrf<input type="hidden" name="domain" value="{{ $d['domain'] }}">
                    <select name="version" class="cp-inp" style="max-width:200px">
                        @foreach($overview['versions'] as $id => $label)<option value="{{ $id }}" @selected($id === $d['version'])>{{ $label }}</option>@endforeach
                    </select>
                    <button type="submit" class="cp-act">Save</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody></table></div>
    @endif
</div>

@if($selected !== '')
<div class="cp-card">
    <div class="cp-ch"><i class="ri-settings-3-line"></i>PHP limits for
        <form method="GET" action="{{ route('client.services.cpanelpro.php', $service) }}" style="margin:0">
            <select name="domain" class="cp-inp" onchange="this.form.submit()" style="padding:5px 8px">
                @foreach($overview['domains'] as $d)<option value="{{ $d['domain'] }}" @selected($d['domain'] === $selected)>{{ $d['domain'] }}</option>@endforeach
            </select>
        </form>
    </div>
    <form method="POST" action="{{ route('client.services.cpanelpro.php.settings', $service) }}" class="cp-form">
        @csrf<input type="hidden" name="domain" value="{{ $selected }}">
        @foreach($labels as $k => [$label, $ph])
            <div class="cp-fld"><label class="cp-lbl">{{ $label }}</label>
                <input type="text" name="ini[{{ $k }}]" value="{{ $settings[$k] ?? '' }}" placeholder="{{ $ph }}" class="cp-inp cp-mono"></div>
        @endforeach
        <button type="submit" class="cp-btn"><i class="ri-save-line"></i>Save</button>
    </form>
</div>
@endif
@endsection
