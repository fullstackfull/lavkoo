{{-- The Seller Center's screens, reachable from the classic panel's own sidebar.

     One panel, two shells while the waves land: this is the bridge between them. The list is not
     written out here — it comes from the same navigation registry the Seller Center's own rail
     reads, which drops any destination whose screen has not shipped and any the signed-in person
     may not open. A screen added in a later wave appears here without this file changing, and a
     screen that does not exist can never be linked from it. --}}
@php
    use App\Http\Middleware\SellerApiAuthMiddleware;
    use App\Http\Middleware\SellerCenterContext;
    use App\Services\Marketplace\SellerPrincipal;
    use App\Services\SellerCenter\Navigation;

    /* The classic panel's pages do not run the Seller Center's context middleware, so the principal
       is resolved the same way that middleware resolves it rather than a second way. */
    $principal = request()->attributes->get(SellerApiAuthMiddleware::PRINCIPAL);
    $principal = $principal instanceof SellerPrincipal ? $principal : app(SellerCenterContext::class)->principal();

    $sellerCenterGroups = Navigation::for($principal);

    /* Only the screens the Seller Center itself owns. A link back to a page of the classic panel
       would be a link to the page the seller is already looking at.

       Flattened and de-duplicated by destination: the registry deliberately lists some screens
       under two groups — Opportunities belongs to operations and to growth — and a flat list has
       no groups to tell them apart, so the same link twice would just look like a mistake. */
    $sellerCenterItems = collect($sellerCenterGroups)
        ->flatMap(fn (array $group) => $group['items'])
        ->reject(fn (array $item) => $item['legacy'] ?? false)
        ->unique('href')
        ->values();
@endphp

@if ($sellerCenterItems->isNotEmpty())
    <div class="v2-ctx-group">
        <div class="v2-ctx-group-head"><span>{{ translate('seller_center') }}</span></div>
        @foreach ($sellerCenterItems as $item)
            <a class="v2-nav-item {{ request()->fullUrlIs($item['href'] . '*') ? 'v2-is-active' : '' }}"
               data-item="sc-{{ $item['key'] }}" href="{{ $item['href'] }}">
                <span class="v2-nav-btn"><span class="v2-nav-label">{{ translate($item['label']) }}</span></span>
                <div class="v2-nav-right">
                    @if ($item['badgeValue'])
                        <span data-v2-tag="primary">{{ $item['badgeValue'] }}</span>
                    @endif
                    <button class="v2-pin-btn" type="button" data-pin="sc-{{ $item['key'] }}" aria-label="{{ translate('pin') }}"></button>
                </div>
            </a>
        @endforeach
    </div>
@endif
