{{-- A section header without content is never rendered (handoff 04 §37). --}}
@props(['title', 'tone' => 'neutral', 'summary' => null])
<div {{ $attributes->merge(['class' => 'sc-section__head']) }}>
    <span class="sc-section__mark sc-section__mark--{{ $tone }}"></span>
    <span class="sc-section__title">{{ $title }}</span>
    @if ($summary)<span class="sc-section__summary">{{ $summary }}</span>@endif
    {{ $slot }}
</div>
