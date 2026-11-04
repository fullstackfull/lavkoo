{{-- Two presentations of the same row: a horizontal rail with arrow controls
     (the mockup's shelf) or a plain responsive grid — the merchant picks in
     the builder's "display style". --}}

@php
    // The shell already resolved this list for the readiness gate; resolving again here would
    // double every slider's catalogue query. The fallback covers any caller that renders this
    // partial outside the shell.
    $products = collect($sliderProducts ?? $__resolver->products($s)->all());
    // Five presentations of one row of products, because the right one
    // depends on what is being sold: a rail for browsing, a grid for
    // comparing, a peeking carousel that says "there is more", a spotlight
    // when one product carries the section, and a list when the detail
    // beside the name is what decides the sale — pack size, dosage, brand.
    $railStyle = $s['style'] ?? 'rail';
    $isRail = in_array($railStyle, ['rail', 'carousel'], true);
    $railId = 'ml-rail-' . ($__section['id'] ?? $loop->index);
    $railAutoplay = $isRail && ($s['autoplay'] ?? false);
    $railInterval = max(2000, (int) ($s['interval'] ?? 4000));
    $showDots = $isRail && ($s['pagination'] ?? false);
    $cardCart = (bool) ($s['add_to_cart'] ?? true);
@endphp
@if ($products->isNotEmpty())
    <div class="ml-sec-head ml-reveal">
        <div>
            <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('curated_for_you') }}</span>
            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
            @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
        </div>
        <div class="d-flex align-items-center gap-3">
            @if ($isRail && ($s['arrows'] ?? true))
                <div class="ml-rail-btns">
                    <button type="button" class="ml-rail-btn" data-ml-rail="{{ $railId }}" data-dir="-1" aria-label="{{ translate('previous') }}">&#8249;</button>
                    <button type="button" class="ml-rail-btn" data-ml-rail="{{ $railId }}" data-dir="1" aria-label="{{ translate('next') }}">&#8250;</button>
                </div>
            @endif
            @if (($s['view_all'] ?? true) && $viewAllUrl)
                <a class="ml-viewall" href="{{ $viewAllUrl }}">{{ translate('view_all') }}</a>
            @endif
        </div>
    </div>

    @if ($railStyle === 'spotlight')
        {{-- The first product is the section; the rest are what else is in
             the family. Two grids rather than one so the hero keeps its
             size on a narrow screen instead of collapsing to a card. --}}
        <div class="ml-spotlight">
            <div class="ml-spotlight__hero ml-reveal">
                @include('theme-sections.partials.product-card', ['product' => $products->first(), 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
            </div>
            <div class="ml-spotlight__rest">
                @foreach ($products->slice(1) as $product)
                    <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                        @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($railStyle === 'list')
        {{-- One product per row, name and price on one line. For a pharmacy
             this is the format that actually compares: pack size and brand
             are readable instead of clipped under a square thumbnail. --}}
        <div class="ml-plist">
            @foreach ($products as $product)
                @php $listPrice = getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'value'); @endphp
                <a class="ml-plist__row ml-reveal" data-delay="{{ $loop->index % 6 }}"
                   href="{{ route('product', ['slug' => $product->slug]) }}">
                    <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}" alt="{{ $product->name }}" loading="lazy">
                    <span class="ml-plist__body">
                        <b>{{ Str::limit($product->name, 70) }}</b>
                        @if (!empty($product->brand?->name))<small>{{ $product->brand->name }}</small>@endif
                    </span>
                    <span class="ml-plist__price">{{ webCurrencyConverter(amount: $listPrice) }}</span>
                </a>
            @endforeach
        </div>
    @elseif ($isRail)
        <div class="ml-rail ml-reveal {{ $railStyle === 'carousel' ? 'is-peek' : '' }}" id="{{ $railId }}"
             @if ($railAutoplay) data-ml-rail-auto="{{ $railInterval }}" @endif>
            @foreach ($products as $product)
                @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
            @endforeach
        </div>
        @if ($showDots)
            <div class="ml-rail-dots" data-ml-rail-dots="{{ $railId }}"></div>
        @endif
    @elseif ($railStyle === 'grid')
        <div class="ml-grid">
            @foreach ($products as $product)
                <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                    @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                </div>
            @endforeach
        </div>
    @else
        {{-- A style that is no longer offered lands here rather than on a
             blank row: the grid is the arrangement that needs nothing. --}}
        <div class="ml-grid">
            @foreach ($products as $product)
                <div class="ml-reveal" data-delay="{{ $loop->index % 6 }}">
                    @include('theme-sections.partials.product-card', ['product' => $product, 'addToCart' => $cardCart, 'wishlisted' => $__wishlisted])
                </div>
            @endforeach
        </div>
    @endif
@endif
