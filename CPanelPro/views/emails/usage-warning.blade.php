<x-mail::message>
# {{ $usage['resource'] }} is {{ $usage['percent'] }}% full

Hello {{ $service->client?->first_name ?: 'there' }},

Your hosting for **{{ $service->domain ?: $service->product?->name }}** has used **{{ $usage['used'] }}** of its **{{ $usage['limit'] }}** {{ strtolower($usage['resource']) }} limit.

@if($usage['resource'] === 'Disk space')
When the disk is full, emails can bounce and your website may stop saving uploads. Delete old backups, emails or files, or upgrade your plan for more space.
@else
When the monthly bandwidth runs out, your website may be paused until the next month. Upgrade your plan if your traffic keeps growing.
@endif

<x-mail::button :url="$url">
View my hosting
</x-mail::button>

Thanks,<br>
{{ $companyName }}
</x-mail::message>
