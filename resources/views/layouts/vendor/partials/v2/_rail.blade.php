@php
    $v2Seller = auth('seller')->user();
    $v2SellerPOS = getWebConfig('seller_pos');
    $v2HasPOS = ($v2SellerPOS == 1 && ($v2Seller['pos_status'] ?? 0) == 1);
    // Mirrors the ctxpanel gate in _side-bar.blade.php: when the vendor-auction
    // feature is off AND this vendor has no historical auctions, hide the
    // rail icon too so the rail and ctxpanel stay in sync.
    $v2AuctionAddonPublished = function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction');
    $v2AuctionFeatureEnabled = getWebConfig(name: 'auction_feature_status') == 1;
    $v2VendorHasAuctions = isset($sidebarAuctionVendorOwnCounts['all']) && $sidebarAuctionVendorOwnCounts['all'] > 0;
    $v2HasAuction = $v2AuctionAddonPublished && ($v2AuctionFeatureEnabled || $v2VendorHasAuctions);
@endphp

<aside class="v2-rail">
    <div class="v2-rail-items">
        {{-- HOME (Dashboard + POS) --}}
        <button class="v2-rail-btn" type="button" data-section="home" data-label="{{ translate('home') }}" aria-label="{{ translate('home') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1v-8.5Z"/>
            </svg>
        </button>

        {{-- CATALOG (Products + Product Reviews) — sits directly under Home,
             matching the admin v2 rail ordering. --}}
        <button class="v2-rail-btn" type="button" data-section="products" data-label="{{ translate('catalog') }}" aria-label="{{ translate('catalog') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z"/>
                <path d="M3 7.5 12 12l9-4.5M12 12v9"/>
            </svg>
        </button>

        {{-- ORDERS (Orders + Refund Requests) --}}
        <button class="v2-rail-btn" type="button" data-section="orders" data-label="{{ translate('orders') }}" aria-label="{{ translate('orders') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M6 8h12l-1 11.2a1 1 0 0 1-1 .8H8a1 1 0 0 1-1-.8L6 8Z"/>
                <path d="M9 8V6a3 3 0 0 1 6 0v2"/>
            </svg>
        </button>

        @if ($v2HasAuction)
            {{-- AUCTION --}}
            <button class="v2-rail-btn" type="button" data-section="auction" data-label="{{ translate('Auction_Management') }}" aria-label="{{ translate('Auction_Management') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m14 5 5 5-4 4-5-5 4-4Z"/>
                    <path d="m9 10-6 6 2 2 6-6"/>
                    <path d="M4 20h10"/>
                </svg>
            </button>
        @endif

        {{-- MARKETING (Coupons + Clearance Sale) — short label matching admin v2. --}}
        <button class="v2-rail-btn" type="button" data-section="marketing" data-label="{{ translate('marketing') }}" aria-label="{{ translate('marketing') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 13V9l13-5v16L3 15Z"/>
                <path d="M16 9v6"/>
                <path d="M7 15v2a2 2 0 0 0 4 0v-1"/>
            </svg>
        </button>

        {{-- REPORTS --}}
        <button class="v2-rail-btn" type="button" data-section="reports" data-label="{{ translate('reports') }}" aria-label="{{ translate('reports') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 20V11M11 20V5M17 20v-6"/>
                <path d="M3 20h18"/>
            </svg>
        </button>

        {{-- PEOPLE (Delivery Man + Support) — merged single rail entry,
             matching admin v2's people section pattern. --}}
        <button class="v2-rail-btn" type="button" data-section="people" data-label="{{ translate('people') }}" aria-label="{{ translate('people') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="9" cy="9" r="3"/>
                <path d="M3 20c0-3 2.5-5 6-5s6 2 6 5"/>
                <circle cx="17" cy="10" r="2.5"/>
                <path d="M15 20c.4-2.2 2-3.5 4-3.5s3.6 1.3 4 3.5"/>
            </svg>
        </button>

        {{-- SETTINGS (Shipping, Withdraws, Bank, Shop) — last rail entry,
             matching admin v2 ordering. --}}
        <button class="v2-rail-btn" type="button" data-section="settings" data-label="{{ translate('settings') }}" aria-label="{{ translate('settings') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M4 7h16M4 12h16M4 17h16"/>
                <circle cx="9"  cy="7"  r="2" fill="currentColor" stroke="none"/>
                <circle cx="15" cy="12" r="2" fill="currentColor" stroke="none"/>
                <circle cx="7"  cy="17" r="2" fill="currentColor" stroke="none"/>
            </svg>
        </button>
    </div>

    @php
        $v2SetupRail = function_exists('checkSetupGuideRequirements') ? checkSetupGuideRequirements(panel: 'vendor') : ['completePercent' => 100];
    @endphp
    @if (($v2SetupRail['completePercent'] ?? 100) < 100)
        <div class="v2-rail-foot">
            <button type="button" class="v2-rail-setup"
                    data-v2-setup-trigger
                    data-label="{{ translate('Setup_Guide') }} · {{ $v2SetupRail['completePercent'] ?? 0 }}%"
                    aria-label="{{ translate('Setup_Guide') }}">
                <img src="{{ dynamicAsset(path: 'public/assets/new/back-end/img/setup_guide.png') }}" alt="">
                <span class="v2-rail-setup-badge">{{ $v2SetupRail['completePercent'] ?? 0 }}%</span>
            </button>
        </div>
    @endif
</aside>
