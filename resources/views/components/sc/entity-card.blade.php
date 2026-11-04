{{-- The mobile rendering of a table row: cards, never a shrunken table (handoff 05 A9). --}}
@props(['title', 'href' => null, 'figure' => null, 'meta' => null])
<article {{ $attributes->merge(['class' => 'sc-entity-card']) }}>
    <div class="sc-entity-card__top">
        <div class="sc-entity-card__title">
            @if ($href)<a href="{{ $href }}" style="color:inherit">{{ $title }}</a>@else{{ $title }}@endif
        </div>
        @if ($figure)<div class="sc-entity-card__figure">{{ $figure }}</div>@endif
    </div>
    @if ($meta)<div class="sc-entity-card__meta">{{ $meta }}</div>@endif
    {{ $slot }}
    @isset($actions)<div class="sc-entity-card__actions">{{ $actions }}</div>@endisset
</article>
