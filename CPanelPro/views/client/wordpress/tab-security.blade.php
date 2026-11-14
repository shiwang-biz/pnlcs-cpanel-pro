@php
    $st = $status['securityStatus'] ?? 'unknown';
    $label = ['insecure' => ['Needs attention', 'warn'], 'secureRecommended' => ['Recommended measures applied', 'ok'], 'secureFull' => ['All measures applied', 'ok'], 'unknown' => ['Not checked yet', 'mute']][$st] ?? ['Not checked yet', 'mute'];
    $v = $vulnerabilities ?? null;
    $vulns = [];
    foreach (($v['core']['vulnerabilities'] ?? []) as $x) { $vulns[] = ['WordPress', $x]; }
    foreach (($v['plugins'] ?? []) as $p) { foreach (($p['vulnerabilities'] ?? []) as $x) { $vulns[] = [strip_tags((string) $p['title']).' (plugin)', $x]; } }
    foreach (($v['themes'] ?? []) as $t) { foreach (($t['vulnerabilities'] ?? []) as $x) { $vulns[] = [strip_tags((string) $t['title']).' (theme)', $x]; } }
    $rank = ['critical' => 'bad', 'high' => 'bad', 'medium' => 'warn', 'low' => 'mute'];
@endphp
<div class="cp-card">
    <div class="cp-ch"><i class="ri-shield-check-line"></i>Security hardening <span class="cp-pill {{ $label[1] }}">{{ $label[0] }}</span>
        <form method="POST" action="{{ $r('security') }}" class="r" style="margin:0">@csrf<input type="hidden" name="action" value="check"><button type="submit" class="cp-act"><i class="ri-search-line"></i>Check now</button></form>
    </div>
    @if(empty($measures))
        <div class="cp-note"><i class="ri-information-line"></i>Security measures aren't available for this site.</div>
    @else
    <form method="POST" action="{{ $r('security') }}">@csrf
        <div class="cp-wrap"><table class="cp-table"><tbody>
        @foreach($measures as $m)
            <tr>
                <td style="width:30px"><input type="checkbox" name="measures[]" value="{{ $m['id'] }}" @checked(! $m['applied'] && ! empty($m['critical'])) aria-label="{{ $m['title'] }}"></td>
                <td><strong>{{ $m['title'] }}</strong> @if(! empty($m['critical']))<span class="cp-pill warn">Recommended</span>@endif
                    <div style="font-size:12px;color:var(--muted)">{{ \Illuminate\Support\Str::limit(strip_tags((string) $m['description']), 200) }}</div></td>
                <td style="white-space:nowrap">@if($m['applied'])<span class="cp-pill ok">Applied</span>@else<span class="cp-pill mute">Not applied</span>@endif</td>
            </tr>
        @endforeach
        </tbody></table></div>
        <div class="cp-form" style="border-top:1px solid var(--border)">
            <button type="submit" name="action" value="apply" class="cp-btn"><i class="ri-shield-check-line"></i>Apply selected</button>
            <button type="submit" name="action" value="revert" class="cp-btn light"><i class="ri-arrow-go-back-line"></i>Revert selected</button>
            <span style="font-size:12.5px;color:var(--muted)">If a measure breaks something on your site, revert it.</span>
        </div>
    </form>
    @endif
</div>

<div class="cp-card">
    <div class="cp-ch"><i class="ri-bug-2-line"></i>Known vulnerabilities
        @if($v && ! empty($v['lastVulnerabilityCheckTimestamp']))<span style="font-weight:500;color:var(--muted);font-size:12px">checked {{ \Carbon\Carbon::createFromTimestamp($v['lastVulnerabilityCheckTimestamp'])->diffForHumans() }}</span>@endif
        <form method="POST" action="{{ $r('security') }}" class="r" style="margin:0">@csrf<input type="hidden" name="action" value="vulnerabilities"><button type="submit" class="cp-act"><i class="ri-search-line"></i>Check now</button></form>
    </div>
    @if(empty($vulns))
        <div class="cp-note"><i class="ri-checkbox-circle-line" style="color:#16a34a"></i>No known vulnerabilities in WordPress, plugins or themes.</div>
    @else
    <div class="cp-wrap"><table class="cp-table">
        <thead><tr><th>In</th><th>Vulnerability</th><th>Risk</th><th>Fixed in</th></tr></thead>
        <tbody>
        @foreach($vulns as [$where, $x])
            <tr><td>{{ $where }}</td>
                <td>@if(! empty($x['directUrl']))<a href="{{ $x['directUrl'] }}" target="_blank" rel="noopener">{{ $x['title'] }}</a>@else{{ $x['title'] }}@endif
                    @if(! empty($x['exploited']))<span class="cp-pill bad">Being exploited</span>@endif</td>
                <td><span class="cp-pill {{ $rank[$x['riskRank'] ?? 'low'] ?? 'mute' }}">{{ ucfirst($x['riskRank'] ?? '') }}</span></td>
                <td>{{ $x['fixedIn'] ?: 'No fix yet' }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="cp-note"><i class="ri-information-line"></i>Update the affected items on the <a href="{{ route('client.services.cpanelpro.wp.show', [$service, $id, 'tab' => 'updates']) }}">Updates</a> tab. Without a fix, deactivate the plugin or turn on vulnerability protection on the Overview tab.</div>
    @endif
</div>
