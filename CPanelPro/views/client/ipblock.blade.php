@extends('client.layouts.app')
@section('title', 'IP Blocker')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-forbid-2-line', 'c1' => '#475569', 'c2' => '#334155', 'title' => 'IP Blocker', 'sub' => 'Blocked addresses can\'t open any website on this account.'])

<div class="cp-card">
    <div class="cp-ch"><i class="ri-add-circle-line"></i>Block an address</div>
    <form method="POST" action="{{ route('client.services.cpanelpro.ipblock.store', $service) }}" class="cp-form">
        @csrf
        <div class="cp-fld"><label class="cp-lbl">IP address or range</label><input type="text" name="ip" required class="cp-inp cp-mono" placeholder="203.0.113.7 or 203.0.113.0/24"></div>
        <button type="submit" class="cp-btn"><i class="ri-forbid-line"></i>Block</button>
    </form>
    <div class="cp-note" style="padding-top:0"><i class="ri-information-line"></i>Your current address is <span class="cp-mono">{{ $yourIp }}</span>. Don't block it.</div>
</div>

<div class="cp-card">
    <div class="cp-ch"><i class="ri-list-check"></i>Blocked</div>
    @if(empty($ips))
        <div class="cp-note"><i class="ri-information-line"></i>Nothing blocked.</div>
    @else
    <div class="cp-wrap"><table class="cp-table"><tbody>
        @foreach($ips as $ip)
        <tr><td class="cp-mono">{{ $ip }}</td>
            <td style="text-align:right"><form method="POST" action="{{ route('client.services.cpanelpro.ipblock.destroy', $service) }}" style="margin:0">
                @csrf<input type="hidden" name="ip" value="{{ $ip }}"><button class="cp-act" type="submit"><i class="ri-checkbox-circle-line"></i>Unblock</button></form></td></tr>
        @endforeach
    </tbody></table></div>
    @endif
</div>
@endsection
