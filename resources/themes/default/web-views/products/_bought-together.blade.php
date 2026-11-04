{{-- "Frequently bought together": the companions for this product, either the ones the merchant
     picked on the product form or — when they picked none — the products customers actually order
     alongside it. Each card adds straight to the cart through the storefront's own flow, so this
     panel behaves exactly like every other add-to-cart on the site. --}}
@php
    $__companions = app(\App\Services\Storefront\BoughtTogetherService::class)->for($product);
@endphp

@if ($__companions->isNotEmpty())
    <section class="bt-panel" aria-labelledby="bought-together-title">
        <header class="bt-panel__head">
            <span class="bt-panel__icon"><i class="fi fi-sr-sparkles"></i></span>
            <span>
                <h3 id="bought-together-title">{{ translate('frequently_bought_together') }}</h3>
                <p>{{ translate('recommended_products') }}</p>
            </span>
        </header>

        <div class="bt-panel__rail">
            @foreach ($__companions as $companion)
                @php
                    $__price = getProductPriceByType(product: $companion, type: 'discounted_unit_price', result: 'string');
                    $__soldOut = $companion->product_type === 'physical' && $companion->current_stock <= 0;
                    $__needsChoice = count(json_decode($companion->colors ?? '[]') ?: []) > 0
                        || count(json_decode($companion->choice_options ?? '[]') ?: []) > 0;
                @endphp
                <article class="bt-item product-cart-option-container">
                    <a class="bt-item__thumb" href="{{ route('product', $companion->slug) }}">
                        <img src="{{ getStorageImages(path: $companion->thumbnail_full_url, type: 'product') }}"
                             alt="{{ $companion->name }}" loading="lazy">
                    </a>
                    <div class="bt-item__body">
                        @if ($companion->brand?->name)
                            <span class="bt-item__brand">{{ $companion->brand->name }}</span>
                        @endif
                        <a class="bt-item__name" href="{{ route('product', $companion->slug) }}">{{ $companion->name }}</a>
                        <span class="bt-item__price">{{ $__price }}</span>
                    </div>

                    @if ($__soldOut)
                        <span class="bt-item__add is-disabled" aria-hidden="true"><i class="fi fi-rr-cross-small"></i></span>
                    @elseif ($__needsChoice)
                        <button type="button" class="bt-item__add action-product-quick-view"
                                data-product-id="{{ $companion->id }}"
                                aria-label="{{ translate('choose_options') }}" title="{{ translate('choose_options') }}">
                            <i class="fi fi-rr-shopping-bag-add"></i>
                        </button>
                    @else
                        <form class="addToCartDynamicForm d-none">
                            @csrf
                            <input type="hidden" name="id" value="{{ $companion->id }}">
                            <input type="hidden" name="quantity" value="{{ max(1, (int) $companion->minimum_order_qty) }}">
                        </form>
                        <button type="button" class="bt-item__add product-add-to-cart-button"
                                aria-label="{{ translate('add_to_cart') }}" title="{{ translate('add_to_cart') }}">
                            <i class="fi fi-rr-shopping-bag-add"></i>
                        </button>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif
