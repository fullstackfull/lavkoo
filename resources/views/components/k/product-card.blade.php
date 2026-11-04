@props([
    'product',
    'variant' => 'inline',
])
{{--
    The storefront's grid product card.

    Four partials rendered a product card, and each carried its own copy of the
    ~40-line media block (discount badge, thumbnail, quick-view with the
    digital/physical tooltip, out-of-stock) and the star loop. That duplication
    is what this component removes.

    What it does not remove is the genuine variation between them: they are not
    one card with drift, they are four presentations. They disagree on heading
    level, on whether the rating sits above or below the title, on alignment —
    and "inline-plain" even computes the discounted price by hand instead of
    through getProductPriceByType(). Flattening that into one layout would
    silently restyle the category and brand pages, so each presentation is
    declared in the table below and rendered exactly as it was. The differences
    are now readable side by side instead of spread across four files, which is
    what let them drift in the first place.

    variant → replaced partial:
      inline        _inline-single-product            (home rows, related products)
      inline-plain  _inline-single-product-without-eye (brand/vendor grids: no quick-view)
      category      _category-single-product          (category page)
      filter        _filter-single-product            (search / filtered listings)

    (_product-card-1 and -2 are deliberately not folded in — those are the
    horizontal flash-deal layout, a genuinely different component.)
--}}
@if (isset($product))
    @php
        $presets = [
            'inline' => [
                'wrapper'      => 'product-single-hover style--card h-100',
                'imageLink'    => 'w-100 rounded',
                'imageClass'   => 'border border-black-50',
                'showAlt'      => true,
                'quickView'    => true,
                'badgeWrapped' => true,
                'details'      => 'single-product-details',
                'ratingFirst'  => false,
                'titleTag'     => 'h3',
                'titleClass'   => 'text-center mb-0 letter-spacing-0',
                'titleLink'    => 'fw-semibold',
                'priceWrapper' => 'justify-content-between text-center mb-1',
                'priceTag'     => 'h4',
                'priceClass'   => 'product-price text-center d-flex flex-wrap justify-content-center align-items-center gap-8 mb-0 letter-spacing-0',
                'delClass'     => 'category-single-product-price fs-14 font-weight-normal',
                'ratingClass'  => 'rating-show justify-content-between text-center mt-1',
                'legacyPrice'  => false,
            ],
            'inline-plain' => [
                'wrapper'      => 'product-single-hover style--card',
                'imageLink'    => 'w-100 rounded',
                'imageClass'   => '',
                'showAlt'      => false,
                'quickView'    => false,
                'badgeWrapped' => true,
                'details'      => 'single-product-details',
                'ratingFirst'  => true,
                'titleTag'     => 'h3',
                'titleClass'   => 'text-center mb-1 letter-spacing-0',
                'titleLink'    => '',
                'priceWrapper' => 'justify-content-between text-center',
                'priceTag'     => 'h4',
                'priceClass'   => 'product-price text-center d-flex flex-wrap justify-content-center align-items-center gap-8 mb-0 letter-spacing-0',
                'delClass'     => 'category-single-product-price fs-14 font-weight-normal',
                'ratingClass'  => 'rating-show justify-content-between text-center',
                // Predates getProductPriceByType(): shows the ->discount column and
                // subtracts manually. Kept as-is so no price moves on screen.
                'legacyPrice'  => true,
            ],
            'category' => [
                'wrapper'      => 'product-single-hover style--category shadow-none rounded',
                'imageLink'    => 'd-block rounded w-100 object-cover',
                'imageClass'   => 'border border-black-50',
                'showAlt'      => true,
                'quickView'    => true,
                'badgeWrapped' => true,
                'details'      => 'single-product-details ' . (Session::get('direction') === 'rtl' ? 'rtl' : ''),
                'ratingFirst'  => true,
                'titleTag'     => 'h3',
                'titleClass'   => 'mb-2 letter-spacing-0',
                'titleLink'    => 'text-capitalize fw-semibold',
                'priceWrapper' => 'mb-0',
                'priceTag'     => 'h4',
                'priceClass'   => 'product-price d-flex flex-wrap gap-8 align-items-center row-gap-0 mb-0 letter-spacing-0',
                'delClass'     => 'category-single-product-price fs-14 font-weight-normal',
                'ratingClass'  => 'rating-show justify-content-between mb-2',
                'legacyPrice'  => false,
            ],
            'filter' => [
                'wrapper'      => 'product-single-hover style--card h-100',
                'imageLink'    => 'w-100 rounded',
                'imageClass'   => 'border border-black-50',
                'showAlt'      => true,
                'quickView'    => true,
                'badgeWrapped' => false,
                'details'      => 'single-product-details',
                'ratingFirst'  => false,
                'titleTag'     => 'h4',
                'titleClass'   => 'text-center mb-1 lh-1 letter-spacing-0 fs-14 fw-semibold',
                'titleLink'    => '',
                'priceWrapper' => 'text-center mb-1',
                'priceTag'     => 'h5',
                'priceClass'   => 'product-price text-center d-flex flex-wrap justify-content-center align-items-baseline gap-1 mb-0 lh-1 letter-spacing-0',
                'delClass'     => 'category-single-product-price font-weight-normal fs-14',
                'ratingClass'  => 'rating-show justify-content-between text-center',
                'legacyPrice'  => false,
            ],
        ];

        $preset = $presets[$variant] ?? $presets['inline'];
        $overallRating = getOverallRating($product?->reviews);
        $discount = getProductPriceByType(product: $product, type: 'discount', result: 'value');
        $showDel = $preset['legacyPrice'] ? $product->discount > 0 : $discount > 0;
    @endphp

    <div class="{{ $preset['wrapper'] }}">
        <div class="overflow-hidden position-relative">
            <div class="inline_product clickable d-flex justify-content-center">
                @if ($discount > 0)
                    @if ($preset['badgeWrapped'])<div class="d-flex">@endif
                    <span class="for-discount-value p-1 pl-2 pr-2 font-bold fs-13">
                        <span class="direction-ltr d-block">
                            -{{ getProductPriceByType(product: $product, type: 'discount', result: 'string') }}
                        </span>
                    </span>
                    @if ($preset['badgeWrapped'])</div>@endif
                @else
                    <div class="d-flex justify-content-end">
                        <span class="for-discount-value-null"></span>
                    </div>
                @endif

                <div class="d-flex pb-0">
                    <a href="{{ route('product', $product->slug) }}" class="{{ $preset['imageLink'] }}">
                        <img alt="{{ $preset['showAlt'] ? $product['name'] : '' }}"
                             @if ($preset['imageClass'] !== '') class="{{ $preset['imageClass'] }}" @endif
                             src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}"
                             loading="lazy" width="300" height="300" decoding="async">
                    </a>
                </div>

                @if ($preset['quickView'])
                    @php
                        $typeLabel = $product->product_type == 'digital' ? translate('Digital_Product') : translate('Physical_Product');
                        $typeIcon = $product->product_type == 'digital' ? 'digital-product.svg' : 'physical-product.svg';
                    @endphp
                    <div class="quick-view">
                        <div class="d-none d-md-flex gap-2 align-items-center quick-view-tag">
                            <div class="bg-white btn-circle" style="--size: 26px" data-toggle="tooltip"
                                 title="{{ $typeLabel }}" data-placement="left">
                                <img class="h-16px aspect-1 svg"
                                     src="{{ theme_asset(path: 'public/assets/front-end/img/icons/' . $typeIcon) }}"
                                     alt="{{ $typeLabel }}">
                            </div>
                        </div>
                        <a class="btn-circle stopPropagation action-product-quick-view" href="javascript:"
                           data-product-id="{{ $product->id }}">
                            <i class="czi-eye align-middle"></i>
                        </a>
                    </div>
                @endif

                @if ($product->product_type == 'physical' && $product->current_stock <= 0)
                    <span class="out_fo_stock">{{ translate('out_of_stock') }}</span>
                @endif
            </div>

            <div class="{{ $preset['details'] }}">
                @if ($preset['ratingFirst'])
                    @include('components.k.product-card-rating', ['class' => $preset['ratingClass']])
                @endif

                <{{ $preset['titleTag'] }} class="{{ $preset['titleClass'] }}">
                    <a href="{{ route('product', $product->slug) }}"
                       @if ($preset['titleLink'] !== '') class="{{ $preset['titleLink'] }}" @endif>
                        {{ $product['name'] }}
                    </a>
                </{{ $preset['titleTag'] }}>

                <div class="{{ $preset['priceWrapper'] }}">
                    <{{ $preset['priceTag'] }} class="{{ $preset['priceClass'] }}">
                        @if ($showDel)
                            <del class="{{ $preset['delClass'] }}">
                                {{ webCurrencyConverter(amount: $product->unit_price) }}
                            </del>
                        @endif
                        <span class="text-accent text-dark fs-15">
                            @if ($preset['legacyPrice'])
                                {{ webCurrencyConverter(amount:
                                    $product->unit_price - getProductDiscount(product: $product, price: $product->unit_price)
                                ) }}
                            @else
                                {{ getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'string') }}
                            @endif
                        </span>
                    </{{ $preset['priceTag'] }}>
                </div>

                @unless ($preset['ratingFirst'])
                    @include('components.k.product-card-rating', ['class' => $preset['ratingClass']])
                @endunless
            </div>
        </div>
    </div>
@endif
