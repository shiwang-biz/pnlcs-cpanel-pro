@extends('client.layouts.app')
@section('title', 'Error Log')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-bug-line', 'c1' => '#ea580c', 'c2' => '#c2410c', 'title' => 'Error Log', 'sub' => 'The latest errors from your websites, newest first.'])

<div class="cp-card">
    <div class="cp-ch"><i class="ri-file-list-3-line"></i>Errors for
        <form method="GET" action="{{ route('client.services.cpanelpro.errors', $service) }}" style="margin:0">
            <select name="domain" class="cp-inp" onchange="this.form.submit()" style="padding:5px 8px">
                @foreach($domains as $d)<option value="{{ $d }}" @selected($d === $selected)>{{ $d }}</option>@endforeach
            </select>
        </form>
        <a href="{{ route('client.services.cpanelpro.errors', ['service' => $service, 'domain' => $selected]) }}" class="cp-act r"><i class="ri-refresh-line"></i>Refresh</a>
    </div>
    @if(empty($entries))
        <div class="cp-note"><i class="ri-checkbox-circle-line" style="color:#16a34a"></i>No errors logged recently.</div>
    @else
    <div class="cp-wrap"><table class="cp-table"><tbody>
        @foreach($entries as $e)
        <tr><td style="white-space:nowrap;color:var(--muted);font-size:12px;width:160px">{{ $e['date'] }}</td><td class="cp-mono" style="word-break:break-word">{{ $e['entry'] }}</td></tr>
        @endforeach
    </tbody></table></div>
    @endif
</div>
@endsection
