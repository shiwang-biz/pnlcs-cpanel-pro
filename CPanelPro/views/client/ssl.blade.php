@extends('client.layouts.app')
@section('title', 'SSL / HTTPS')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-shield-check-line', 'c1' => '#16a34a', 'c2' => '#15803d', 'title' => 'SSL / HTTPS', 'sub' => 'Free certificates are issued and renewed automatically by AutoSSL.'])

<div class="cp-card">
    <div class="cp-ch"><i class="ri-lock-line"></i>Certificates
        <form method="POST" action="{{ route('client.services.cpanelpro.ssl.autossl', $service) }}" class="r" style="margin:0">@csrf
            <button type="submit" class="cp-btn light"><i class="ri-refresh-line"></i>Run AutoSSL now</button></form>
    </div>
    <div class="cp-wrap"><table class="cp-table">
        <thead><tr><th>Domain</th><th>Certificate</th><th>Expires</th><th>Always use HTTPS</th></tr></thead>
        <tbody>
        @forelse($rows as $r)
            <tr>
                <td class="cp-mono">{{ $r['domain'] }}</td>
                <td>
                    @if(!$r['has_cert'])<span class="cp-pill bad">None</span>
                    @elseif($r['self_signed'])<span class="cp-pill warn">Self-signed</span>
                    @else<span class="cp-pill ok">Valid</span> <span style="color:var(--muted);font-size:12px">{{ $r['issuer'] }}</span>@endif
                </td>
                <td>
                    @if($r['expires']){{ $r['expires'] }}
                        @if($r['days_left'] !== null && $r['days_left'] < 0)<span class="cp-pill bad">expired</span>
                        @elseif($r['days_left'] !== null && $r['days_left'] < 15)<span class="cp-pill warn">{{ $r['days_left'] }} days</span>@endif
                    @else — @endif
                </td>
                <td>
                    <form method="POST" action="{{ route('client.services.cpanelpro.ssl.redirect', $service) }}" style="margin:0;display:inline-flex;gap:6px;align-items:center">
                        @csrf<input type="hidden" name="domain" value="{{ $r['domain'] }}">
                        @if($r['redirect'] === true)
                            <span class="cp-pill ok">On</span><input type="hidden" name="state" value="0"><button class="cp-act" type="submit">Turn off</button>
                        @else
                            @if($r['redirect'] === false)<span class="cp-pill mute">Off</span>@endif
                            <input type="hidden" name="state" value="1"><button class="cp-act" type="submit" @disabled(!$r['has_cert'] || $r['self_signed'])>Turn on</button>
                        @endif
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" style="color:var(--muted)">No domains found.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
<div class="cp-note"><i class="ri-information-line"></i>A new domain gets its certificate once it points to this server. "Always use HTTPS" needs a valid certificate.</div>
@endsection
