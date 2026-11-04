{{-- Buy the set: the picked products, what the set costs after the bundle
     discount, and one button that adds every one of them to the cart. --}}

    <div class="ml-bundle ml-reveal">
        <div class="ml-bundle__head">
            @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
            <h2>{{ $s['title'] ?: translate('buy_the_set') }}</h2>
            @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
        </div>
        <div class="ml-bundle__items">
            @foreach ($set['products'] as $product)
                {{-- One form per product, shaped exactly like the card's so the bundle
                     button can reuse the storefront's own cart endpoint. --}}
                <form class="ml-bundle__form d-none">
                    @csrf
                    <input type="hidden" name="id" value="{{ $product->id }}">
                    <input type="hidden" name="quantity" value="{{ max(1, (int) $product->minimum_order_qty) }}">
                </form>
                <a class="ml-bundle__item" href="{{ route('product', $product->slug) }}">
                    <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}"
                         alt="{{ $product->name }}" loading="lazy">
                    <span>{{ Str::limit($product->name, 34) }}</span>
                </a>
                @if (!$loop->last)<span class="ml-bundle__plus">+</span>@endif
            @endforeach
        </div>
        <div class="ml-bundle__foot">
            <div class="ml-bundle__price">
                <b>{{ webCurrencyConverter(amount: $set['discounted']) }}</b>
                @if ($set['saved'] > 0)
                    <del>{{ webCurrencyConverter(amount: $set['total']) }}</del>
                    <span class="ml-off">-{{ $set['percent'] }}%</span>
                @endif
            </div>
            <button type="button" class="ml-btn ml-btn-gold" data-ml-bundle
                    data-busy="{{ translate('adding') }}...">
                {{ $s['button_text'] ?: translate('add_the_set_to_cart') }}
            </button>
        </div>
    </div>
