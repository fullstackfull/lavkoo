{{-- A status is always an icon AND a word. Colour alone never carries meaning (handoff 06 §1). --}}
@props(['status' => null, 'severity' => null, 'label' => null, 'glyph' => null, 'tone' => null])
@php
    $resolved = $severity !== null
        ? \App\Services\SellerCenter\Status::severity($severity)
        : \App\Services\SellerCenter\Status::of($status);
    $tone = $tone ?? $resolved['tone'];
    $glyph = $glyph ?? $resolved['glyph'];
    $text = $label ?? translate($resolved['key']);
@endphp
<span {{ $attributes->merge(['class' => 'sc-badge sc-badge--' . $tone]) }}>
    <x-sc.icon :name="$glyph" :size="12" />
    <span>{{ $slot->isEmpty() ? $text : $slot }}</span>
</span>
