{{-- One themed product card, shared by the rail and grid presentations of product_slider, the
     flash-deal strip and the category showcase.

     Everything on it is real store data: the price and discount come from the catalogue's own
     helper (so a themed home charges what the rest of the shop charges), the stars are the
     product's approved reviews, and the heart and cart buttons reuse the storefront's own wishlist
     and cart flows — `.product-action-add-wishlist` and the `.addToCartDynamicForm` +
     `.product-add-to-cart-button` pair that custom.js already binds. A product that needs a choice
     (colour, size, any variation) opens the quick view instead of guessing a variant. --}}
@php
    $__addToCart = $addToCart ?? false;
    $__wishlisted = in_array($product->id, $wishlisted ?? [], true);

    $__unitPrice = (float) $product->unit_price;
    $__price = getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'value');
    $__saved = max(0, $__unitPrice - (float) $__price);
    // The percentage is computed from the two prices rather than read off the discount column, so
    // a FLAT discount also reads as "-15%" instead of showing a currency amount as a badge.
    $__off = $__unitPrice > 0 && $__saved > 0 ? (int) round($__saved / $__unitPrice * 100) : 0;

    $__rating = getOverallRating($product->reviews);
    $__reviewCount = count($product->reviews ?? []);

    $__soldOut = $product->product_type === 'physical' && $product->current_stock <= 0;
    $__needsChoice = count(json_decode($product->colors ?? '[]') ?: []) > 0
        || count(json_decode($product->choice_options ?? '[]') ?: []) > 0;
@endphp
<article class="ml-card product-cart-option-container">
    <div class="ml-card__media">
        @if ($__off > 0)
            <span class="ml-off">-{{ $__off }}%</span>
        @endif
        @if ($__soldOut)
            <span class="ml-soldout">{{ translate('out_of_stock') }}</span>
        @endif

        <button type="button" class="ml-fav product-action-add-wishlist {{ $__wishlisted ? 'is-on' : '' }}"
                data-product-id="{{ $product->id }}"
                aria-label="{{ translate('add_to_wishlist') }}" title="{{ translate('add_to_wishlist') }}">
            <i class="fa {{ $__wishlisted ? 'fa-heart' : 'fa-heart-o' }} wishlist_icon_{{ $product->id }}"></i>
        </button>

        <a href="{{ route('product', $product->slug) }}" class="ml-card__thumb" tabindex="-1" aria-hidden="true">
            <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}"
                 alt="{{ $product->name }}" loading="lazy">
        </a>
    </div>

    <div class="ml-card__body">
        {{-- The brand sits under the image in a quiet weight: it identifies the product without
             competing with its name. Nothing is drawn for a product that carries no brand — a
             placeholder line would read as a brand called "all products". --}}
        @if ($product->brand?->name)
            <span class="ml-brandline">{{ $product->brand->name }}</span>
        @endif

        <a href="{{ route('product', $product->slug) }}" class="ml-name">{{ $product->name }}</a>

        @if ($__rating[0] > 0)
            {{-- Half stars, like the rest of the storefront: a 4.6 average that draws five full
                 stars overstates the product. --}}
            <span class="ml-stars" aria-label="{{ $__rating[0] }} / 5">
                @for ($star = 1; $star <= 5; $star++)
                    @php
                        $__starClass = $star <= floor($__rating[0])
                            ? 'fa-star'
                            : ($star - $__rating[0] < 1 ? 'fa-star-half-o' : 'fa-star-o');
                    @endphp
                    <i class="fa {{ $__starClass }}"></i>
                @endfor
                <small>({{ $__reviewCount }})</small>
            </span>
        @endif

        <div class="ml-price">
            <b>{{ webCurrencyConverter(amount: $__price) }}</b>
            @if ($__saved > 0)
                <del>{{ webCurrencyConverter(amount: $__unitPrice) }}</del>
            @endif
        </div>

        @if ($__addToCart)
            @if ($__soldOut)
                <button type="button" class="ml-cart-btn" disabled>{{ translate('out_of_stock') }}</button>
            @elseif ($__needsChoice)
                <button type="button" class="ml-cart-btn action-product-quick-view" data-product-id="{{ $product->id }}">
                    {{ translate('choose_options') }}
                </button>
            @else
                <form class="addToCartDynamicForm d-none">
                    @csrf
                    <input type="hidden" name="id" value="{{ $product->id }}">
                    <input type="hidden" name="quantity" value="{{ max(1, (int) $product->minimum_order_qty) }}">
                </form>
                <button type="button" class="ml-cart-btn product-add-to-cart-button">
                    <i class="fi fi-rr-shopping-cart"></i>
                    {{ translate('add_to_cart') }}
                </button>
            @endif
        @endif
    </div>
</article>
