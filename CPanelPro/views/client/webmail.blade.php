@extends('client.layouts.app')
@section('title', 'Webmail')
@section('content')
@include('cpanelpro::client._style')
@include('cpanelpro::client._head', ['icon' => 'ri-inbox-2-line', 'c1' => '#e11d48', 'c2' => '#be123c', 'title' => 'Webmail', 'sub' => 'Open a mailbox in webmail, already signed in. No password needed.'])

<div class="cp-card">
    <div class="cp-ch"><i class="ri-mail-line"></i>Your mailboxes</div>
    @if(empty($emails))
        <div class="cp-note"><i class="ri-information-line"></i>No mailboxes yet. Create one under <a href="{{ route('client.services.emails', $service) }}">Email Accounts</a>.</div>
    @else
    <div class="cp-wrap"><table class="cp-table"><tbody>
        @foreach($emails as $e)
        <tr>
            <td class="cp-mono">{{ $e['email'] }}</td>
            <td style="text-align:right">
                <form method="POST" action="{{ route('client.services.cpanelpro.webmail.login', $service) }}" target="_blank" style="margin:0">
                    @csrf<input type="hidden" name="email" value="{{ $e['email'] }}">
                    <button type="submit" class="cp-btn"><i class="ri-external-link-line"></i>Open webmail</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody></table></div>
    @endif
</div>
@endsection
