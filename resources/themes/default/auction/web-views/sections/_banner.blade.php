@php($auctionHomePageSetup = getWebConfig(name: 'auction_home_page_setup'))
<div class="auction-banner-section rounded-10 overflow-hidden position-relative mb-40" data-bg-img="{{ getStorageImages(path: getWebConfig(name: 'auction_home_bg_image'), type: 'backend-placeholder') ?? '' }}">
    <div class="auction-banner-content max-w-920px mx-auto text-center">
        <h1 class="fw-bold text-white">
            {{ $auctionHomePageSetup['title'] ?? translate('The Ultimate Online Auction Experience') }}
        </h1>
        <p class="fw-semibold text-white mb-0">
            {{ $auctionHomePageSetup['subtitle'] ?? translate('Discover live auctions, place real-time bids, and secure the best deals effortlessly.') }}
        </p>
    </div>
</div>
