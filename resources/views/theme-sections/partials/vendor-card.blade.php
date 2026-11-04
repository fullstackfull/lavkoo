{{-- One shop card for the vendor sections.

     What a buyer weighs before walking into a store, in that order: its cover, its logo and name,
     what other buyers rate it, and how much it sells. Rating and product count come from the same
     ShopService the storefront's own /vendors page uses, so the two never disagree.

     Variants: `cards` (the default block), `compact` (a row, no cover), `storefront` (the cover
     card with the marketplace's own badge and a way in) and `logos` (a bare mark and a name). --}}
@php
    $__variant = $variant ?? 'cards';
    $__shopUrl = \Illuminate\Support\Facades\Route::has('vendor-shop') && $shop->slug
        ? route('vendor-shop', ['slug' => $shop->slug])
        : route('products', ['seller_id' => $shop->seller_id]);
    $__closed = ($shop->is_vacation_mode_now ?? false) || ($shop->temporary_close ?? false);
    $__stats = ($stats ?? true) && $__variant !== 'logos';
    $__cover = $__variant !== 'compact' && $__variant !== 'logos';
    // The marketplace's own store is the one shop the platform vouches for. Nothing else here
    // earns a badge: an approved seller is a seller who may trade, not a verified one.
    $__official = ($shop->author_type ?? null) === 'admin';
@endphp
<a class="ml-vendor is-{{ $__variant }}" href="{{ $__shopUrl }}">
    @if ($__cover)
        <span class="ml-vendor__cover">
            <img src="{{ getStorageImages(path: $shop->banner_full_url, type: 'shop-banner') }}"
                 alt="{{ $shop->name }}" loading="lazy">
        </span>
    @endif

    <span class="ml-vendor__body">
        <span class="ml-vendor__logo">
            <img src="{{ getStorageImages(path: $shop->image_full_url, type: 'shop') }}" alt="{{ $shop->name }}" loading="lazy">
        </span>
        <span class="ml-vendor__id">
            <b>{{ $shop->name }}</b>
            @if ($__official && $__variant === 'storefront')
                <span class="ml-vendor__badge"><i class="fa fa-check-circle"></i>{{ translate('official_store') }}</span>
            @endif
            @if ($__stats)
                <span class="ml-vendor__stats">
                    @if ($shop->average_rating > 0)
                        <span class="ml-vendor__rating"><i class="fa fa-star"></i>{{ number_format($shop->average_rating, 1) }}</span>
                    @endif
                    <span>{{ $shop->products_count }} {{ translate('products') }}</span>
                </span>
            @endif
        </span>
        @if ($__closed)
            <span class="ml-vendor__closed">{{ translate('closed_now') }}</span>
        @endif
    </span>

    @if ($__variant === 'storefront')
        <span class="ml-vendor__visit">{{ translate('visit_store') }}</span>
    @endif
</a>
