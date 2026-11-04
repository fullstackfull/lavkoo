@props(['title' => null, 'padded' => true])
<div {{ $attributes->merge(['class' => 'k-card']) }}>
    @if ($title || isset($actions))
        <div class="k-card__head">
            @if ($title)<h3 class="k-card__title">{{ $title }}</h3>@endif
            @isset($actions)<div class="k-row">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="{{ $padded ? 'k-card__body' : '' }}">{{ $slot }}</div>
    @isset($footer)<div class="k-card__foot">{{ $footer }}</div>@endisset
</div>
