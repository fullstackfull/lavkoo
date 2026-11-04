{{-- Live countdown to the END DATE of a flash deal (Promotion -> Flash deals),
     plus the deal's own products so the strip sells and not only counts down.
     The merchant either picks a deal or leaves it on "whichever is running";
     with no deal at all the section renders nothing rather than a dead timer. --}}

@php
    $dealProducts = ($deal && ($s['products'] ?? true))
        ? $__resolver->flashDealProducts($deal['id'], (int) ($s['limit'] ?? 10))
        : collect();
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
    $dealRailId = 'ml-deal-' . ($__section['id'] ?? $loop->index);
    // strip: the gradient bar with the clock beside the copy.
    // banner: the deal's own artwork behind the clock, for a campaign that
    //         was designed rather than assembled.
    // grid:   no rail — every product in the deal at once, which is what a
    //         short deal with six products actually wants.
    $dealStyle = $s['style'] ?? 'strip';
@endphp
@if ($deal)
    <div class="ml-flash ml-flash--{{ $dealStyle }} ml-reveal"
         @if ($dealStyle === 'banner' && !empty($deal['banner'])) style="background-image:linear-gradient(120deg,rgba(20,8,46,.72),rgba(20,8,46,.35)),url('{{ $deal['banner'] }}');background-size:cover;background-position:center" @endif>
        <div class="ml-flash__copy">
            <h3>{{ $s['title'] ?: ($deal['title'] ?: translate('flash_deals')) }}</h3>
            <p>{{ $s['subtitle'] ?: translate('grab_the_best_offers_before_time_runs_out') }}</p>
        </div>
        @if (($s['countdown'] ?? true) && $deal['end_timestamp'])
            <div class="ml-flash__count" data-ml-countdown="{{ $deal['end_timestamp'] }}">
                <div class="ml-time"><b data-unit="days">00</b><small>{{ translate('days') }}</small></div>
                <div class="ml-time"><b data-unit="hours">00</b><small>{{ translate('hours') }}</small></div>
                <div class="ml-time"><b data-unit="minutes">00</b><small>{{ translate('minutes') }}</small></div>
                <div class="ml-time"><b data-unit="seconds">00</b><small>{{ translate('seconds') }}</small></div>
            </div>
        @endif
        @if ($deal['url'])
            <a href="{{ $deal['url'] }}" class="ml-btn ml-btn-light">{{ translate('shop_the_deal') }}</a>
        @endif
    </div>

    @if ($dealProducts->isNotEmpty())
        <div class="{{ $dealStyle === 'grid' ? 'ml-grid' : 'ml-rail' }} ml-reveal mt-3" id="{{ $dealRailId }}">
            @foreach ($dealProducts as $product)
                @if ($dealStyle === 'grid')
                    <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                    </div>
                @else
                    @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                @endif
            @endforeach
        </div>
    @endif
@endif
