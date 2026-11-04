{{-- Today's deal: the product the merchant set in Promotion -> Deal of the day,
     counting down to midnight because that is when the deal actually ends. --}}

@php
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
    // split: copy on one side, the product card on the other.
    // banner: one wide band, the clock centred — reads as an announcement.
    // card:   a single compact panel for a page that already has three deals.
    $dotdStyle = $s['style'] ?? 'split';
@endphp
    <div class="ml-dotd ml-dotd--{{ $dotdStyle }} ml-reveal">
        <div class="ml-dotd__copy">
            <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('deal_of_the_day') }}</span>
            <h2>{{ $s['title'] ?: ($dotd['deal']->title ?: $dotd['product']->name) }}</h2>
            @if ($s['countdown'] ?? true)
                <div class="ml-flash__count" data-ml-countdown="{{ now()->endOfDay()->getTimestamp() }}">
                    <div class="ml-time"><b data-unit="hours">00</b><small>{{ translate('hours') }}</small></div>
                    <div class="ml-time"><b data-unit="minutes">00</b><small>{{ translate('minutes') }}</small></div>
                    <div class="ml-time"><b data-unit="seconds">00</b><small>{{ translate('seconds') }}</small></div>
                </div>
            @endif
            <a class="ml-btn ml-btn-gold" href="{{ route('product', $dotd['product']->slug) }}">{{ translate('shop_the_deal') }}</a>
        </div>
        <div class="ml-dotd__card">
            @include('theme-sections.partials.product-card', ['product' => $dotd['product'], 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
        </div>
    </div>
