<a href="{{ route('client.services.show', $service) }}" class="cp-back"><i class="ri-arrow-left-line"></i>{{ $service->domain ?: __('client.services.title') }}</a>
<div class="cp-head">
    <div class="cp-head-ic" style="background:linear-gradient(135deg,{{ $c1 }},{{ $c2 }});box-shadow:0 8px 18px -6px {{ $c1 }}99"><i class="{{ $icon }}"></i></div>
    <div><h1>{{ $title }}</h1><div class="sub">{{ $sub }}</div></div>
    {{ $slot ?? '' }}
</div>
