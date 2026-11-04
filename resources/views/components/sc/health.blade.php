{{-- `Healthy` means nothing detected; it must read differently from `Unknown` (handoff 04 §32). --}}
@props(['label', 'state' => 'unknown', 'count' => null])
@php($resolved = \App\Services\SellerCenter\Status::of($state))
<div {{ $attributes->merge(['class' => 'sc-health']) }}>
    <x-sc.dot :tone="$resolved['tone']" />
    <span class="sc-health__label">{{ $label }}</span>
    <span class="sc-health__state" style="color:var(--st-{{ $resolved['tone'] === 'unknown' ? 'neutral' : $resolved['tone'] }})">{{ translate($resolved['key']) }}</span>
    <span class="sc-health__count">{{ $count === null || $count === 0 ? '—' : $count }}</span>
</div>
