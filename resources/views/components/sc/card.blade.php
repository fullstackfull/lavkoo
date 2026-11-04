@props(['title' => null, 'label' => null, 'context' => null, 'side' => false, 'flush' => false])
<section {{ $attributes->merge(['class' => 'sc-card' . ($side ? ' sc-card--side' : '') . ($flush ? ' sc-card--flush' : '')]) }}>
    @if ($title || ($label && isset($actions)) || isset($actions))
        <header class="sc-card__head">
            <div style="min-width:0">
                @if ($title)<h5 class="sc-card__title">{{ $title }}</h5>@endif
                @if ($label && !$title)<div class="sc-card__label">{{ $label }}</div>@endif
                @if ($context)<div class="sc-card__context">{{ $context }}</div>@endif
            </div>
            <div class="sc-spacer"></div>
            @isset($actions){{ $actions }}@endisset
        </header>
    @endif
    <div class="sc-card__body">
        @if ($label && !isset($actions) && !$title)<div class="sc-card__label" style="margin-bottom:8px">{{ $label }}</div>@endif
        {{ $slot }}
    </div>
</section>
