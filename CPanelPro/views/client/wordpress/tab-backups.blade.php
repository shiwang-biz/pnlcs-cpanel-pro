<div class="cp-card">
    <div class="cp-ch"><i class="ri-archive-line"></i>Create a backup</div>
    <form method="POST" action="{{ $r('backups.create') }}" class="cp-form">@csrf
        <div class="cp-fld"><label class="cp-lbl">Note (optional)</label><input type="text" name="note" class="cp-inp" maxlength="200" placeholder="Before redesign"></div>
        <button type="submit" class="cp-btn"><i class="ri-save-3-line"></i>Back up now</button>
    </form>
    <div class="cp-note" style="padding-top:0"><i class="ri-information-line"></i>Backs up the site's files and database. Backups use your disk space.</div>
</div>

<div class="cp-card">
    <div class="cp-ch"><i class="ri-history-line"></i>Backups <span class="cp-pill mute">{{ count($backups) }}</span></div>
    @if(empty($backups))
        <div class="cp-note"><i class="ri-information-line"></i>No backups yet.</div>
    @else
    <div class="cp-wrap"><table class="cp-table">
        <thead><tr><th>File</th><th>Created</th><th>Size</th><th></th></tr></thead>
        <tbody>
        @foreach($backups as $b)
            <tr>
                <td class="cp-mono" style="word-break:break-all">{{ $b['fileName'] }}</td>
                <td style="white-space:nowrap">{{ $b['createdAt'] ? \Carbon\Carbon::parse($b['createdAt'])->format('Y-m-d H:i') : '' }}</td>
                <td style="white-space:nowrap">{{ \Modules\Servers\CPanelPro\UsageAlerts::size((int) round(((float) $b['fileSize']) / 1048576)) }}</td>
                <td style="text-align:right;white-space:nowrap">
                    <form method="POST" action="{{ $r('backups.restore') }}" style="margin:0;display:inline" onsubmit="return pnConfirm(event, @js('Restore this backup? The site\'s current files and database are replaced.'))">@csrf
                        <input type="hidden" name="file" value="{{ $b['fileName'] }}"><button class="cp-act" type="submit"><i class="ri-arrow-go-back-line"></i>Restore</button></form>
                    <form method="POST" action="{{ $r('backups.delete') }}" style="margin:0;display:inline" onsubmit="return pnConfirm(event, @js('Delete this backup?'))">@csrf
                        <input type="hidden" name="files[]" value="{{ $b['fileName'] }}"><button class="cp-act danger" type="submit"><i class="ri-delete-bin-line"></i></button></form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>
