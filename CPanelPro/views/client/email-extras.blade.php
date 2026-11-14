@extends('client.layouts.app')
@section('title', 'Email Extras')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-mail-send-line', 'c1' => '#db2777', 'c2' => '#be185d', 'title' => 'Email Extras', 'sub' => 'Forwarders, out-of-office replies and what happens to mail for unknown addresses.'])

{{-- Forwarders --}}
<div class="cp-card">
    <div class="cp-ch"><i class="ri-share-forward-line"></i>Forwarders</div>
    <form method="POST" action="{{ route('client.services.cpanelpro.email.forwarders.store', $service) }}" class="cp-form">
        @csrf
        <div class="cp-fld"><label class="cp-lbl">Address</label><input type="text" name="local_part" required class="cp-inp" placeholder="sales"></div>
        <div class="cp-fld"><label class="cp-lbl">@ Domain</label><select name="domain" class="cp-inp">@foreach($domains as $d)<option>{{ $d }}</option>@endforeach</select></div>
        <div class="cp-fld"><label class="cp-lbl">Forward to</label><input type="email" name="target" required class="cp-inp" placeholder="you@gmail.com"></div>
        <button type="submit" class="cp-btn"><i class="ri-add-line"></i>Add forwarder</button>
    </form>
    @if(count($forwarders))
    <div class="cp-wrap"><table class="cp-table"><thead><tr><th>Mail to</th><th>Goes to</th><th></th></tr></thead><tbody>
        @foreach($forwarders as $f)
        <tr><td class="cp-mono">{{ $f['from'] }}</td><td class="cp-mono">{{ $f['to'] }}</td>
            <td style="text-align:right"><form method="POST" action="{{ route('client.services.cpanelpro.email.forwarders.destroy', $service) }}" style="margin:0" onsubmit="return pnConfirm(event, @js('Remove this forwarder?'))">
                @csrf<input type="hidden" name="from" value="{{ $f['from'] }}"><input type="hidden" name="to" value="{{ $f['to'] }}">
                <button class="cp-act danger" type="submit"><i class="ri-delete-bin-line"></i></button></form></td></tr>
        @endforeach
    </tbody></table></div>
    @endif
</div>

{{-- Autoresponders --}}
<div class="cp-card">
    <div class="cp-ch"><i class="ri-reply-line"></i>Autoresponders (out of office)</div>
    @if(empty($emails))
        <div class="cp-note"><i class="ri-information-line"></i>Create a mailbox first under <a href="{{ route('client.services.emails', $service) }}">Email Accounts</a>.</div>
    @else
    <form method="POST" action="{{ route('client.services.cpanelpro.email.autoresponders.store', $service) }}" class="cp-form">
        @csrf
        <div class="cp-fld"><label class="cp-lbl">Mailbox</label><select name="email" class="cp-inp">@foreach($emails as $e)<option>{{ $e['email'] }}</option>@endforeach</select></div>
        <div class="cp-fld"><label class="cp-lbl">From name</label><input type="text" name="from" required class="cp-inp" placeholder="Your name or company"></div>
        <div class="cp-fld" style="flex-basis:100%"><label class="cp-lbl">Subject</label><input type="text" name="subject" required class="cp-inp" placeholder="Out of office"></div>
        <div class="cp-fld" style="flex-basis:100%"><label class="cp-lbl">Message</label><textarea name="body" required class="cp-inp" placeholder="Thanks for your email. I'm away until…"></textarea></div>
        <div class="cp-fld"><label class="cp-lbl">Starts</label><input type="date" name="start" class="cp-inp"></div>
        <div class="cp-fld"><label class="cp-lbl">Ends (optional)</label><input type="date" name="stop" class="cp-inp"></div>
        <div class="cp-fld"><label class="cp-lbl">Reply to the same sender every (hours)</label><input type="number" name="interval" value="8" min="0" max="720" class="cp-inp"></div>
        <button type="submit" class="cp-btn"><i class="ri-add-line"></i>Set autoresponder</button>
    </form>
    @endif
    @if(count($autoresponders))
    <div class="cp-wrap"><table class="cp-table"><thead><tr><th>Mailbox</th><th>Subject</th><th></th></tr></thead><tbody>
        @foreach($autoresponders as $a)
        <tr><td class="cp-mono">{{ $a['email'] }}</td><td>{{ $a['subject'] }}</td>
            <td style="text-align:right"><form method="POST" action="{{ route('client.services.cpanelpro.email.autoresponders.destroy', $service) }}" style="margin:0" onsubmit="return pnConfirm(event, @js('Remove this autoresponder?'))">
                @csrf<input type="hidden" name="email" value="{{ $a['email'] }}"><button class="cp-act danger" type="submit"><i class="ri-delete-bin-line"></i></button></form></td></tr>
        @endforeach
    </tbody></table></div>
    @endif
</div>

{{-- Catch-all --}}
<div class="cp-card">
    <div class="cp-ch"><i class="ri-mail-unread-line"></i>Catch-all (mail to addresses that don't exist)</div>
    <div class="cp-wrap"><table class="cp-table"><tbody>
        @foreach($catchAll as $domain => $current)
        @php($mode = str_contains($current, ':fail:') ? 'fail' : (str_contains($current, ':blackhole:') ? 'blackhole' : ($current !== '' && str_contains($current, '@') ? 'fwd' : 'fail')))
        <tr><td class="cp-mono">{{ $domain }}</td>
            <td><form method="POST" action="{{ route('client.services.cpanelpro.email.catchall', $service) }}" style="margin:0;display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
                @csrf<input type="hidden" name="domain" value="{{ $domain }}">
                <select name="mode" class="cp-inp" style="max-width:220px">
                    <option value="fail" @selected($mode === 'fail')>Bounce back to sender</option>
                    <option value="blackhole" @selected($mode === 'blackhole')>Discard silently</option>
                    <option value="fwd" @selected($mode === 'fwd')>Forward to…</option>
                </select>
                <input type="email" name="target" class="cp-inp" style="max-width:220px" placeholder="address for Forward to" value="{{ $mode === 'fwd' ? $current : '' }}">
                <button type="submit" class="cp-act">Save</button>
            </form></td></tr>
        @endforeach
    </tbody></table></div>
</div>
@endsection
