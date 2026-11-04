<div class="bid-product-details-body">
    @include('auction.web-views.user-profile.partials._product-details._product-header-card', ['mode' => 'claimed'])

    {{-- Auction Info summary card: bidder/winner (purchase_complete) only.
         Owner-claimed view gets richer stats in the right sidebar, so it would be redundant here. --}}
    @if($isPurchaseComplete)
        <div class="card bs-border p-xxl-20px p-3 rounded overflow-hidden shadow-sm mb-20">
            <div class="cards-title fs-16 mb-15 fw-semibold">{{ translate('Auction Info') }}</div>
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="bg-light rounded p-12px d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-12px">
                            <img width="30" height="30" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/total-view-user.png') }}" alt="">
                            <span class="title-semidark fs-14">{{ translate('Total Viewed') }}</span>
                        </div>
                        <h4 class="m-0 fs-16 fw-semibold">{{ formatCompactNumber($auctionProduct->total_views) }}</h4>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="bg-light rounded p-12px d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center gap-12px">
                            <img width="30" height="30" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/total-bid-icon.png') }}" alt="">
                            <span class="title-semidark fs-14">{{ translate('Total Bid') }}</span>
                        </div>
                        <h4 class="m-0 fs-16 fw-semibold">{{ formatCompactNumber($auctionProduct->total_bids) }}</h4>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @php
        $hasVideo = ($auctionProduct?->video_provider === 'youtube_link'
            && $auctionProduct?->youtube_embed_url != null
            && str_contains($auctionProduct?->youtube_embed_url, "youtube.com/embed/"))
            || ($auctionProduct?->video_provider === 'custom_video' && $auctionProduct?->custom_video_url);
    @endphp
    <div class="card border-0 shadow-sm p-xl-4 p-3">
        <ul class="nav nav-pills mb-20 d-flex align-items-center justify-content-center" id="claimed-pills-tab" role="tablist">
            <li class="nav-item flex-grow-1 d-flex align-items-center justify-content-sm-end" role="presentation">
                <button class="nav-link px-2 px-sm-3 fs-12-mobile" id="claimed-desc-tab" data-bs-toggle="pill" data-bs-target="#claimed-bids-description" type="button" role="tab" aria-controls="claimed-bids-description" aria-selected="false">
                    {{ translate('Product Description') }}
                </button>
            </li>
            <li class="nav-item flex-grow-1 d-flex align-items-center justify-content-between gap-2" role="presentation">
                <button class="nav-link px-2 px-sm-3 fs-12-mobile active" id="claimed-history-tab" data-bs-toggle="pill" data-bs-target="#claimed-bids-history" type="button" role="tab" aria-controls="claimed-bids-history" aria-selected="true">
                    {{ translate('Bidding History') }}
                </button>
                <div class="ms-sm-auto accordion" id="claimedBiddingHistoryAccordion">
                    <div class="accordion-item bg-transparent border-0">
                        <h2 class="accordion-header p-0 bg-transparent outline-0 border-0">
                            <button class="accordion-button shadow-none p-0 bg-transparent outline-0 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#claimed-bidding-details-collapse" aria-expanded="true" aria-controls="claimed-bidding-details-collapse">
                            </button>
                        </h2>
                    </div>
                </div>
            </li>
            @if($hasVideo)
            <li class="nav-item" role="presentation">
                <button class="nav-link px-2 px-sm-3 fs-12-mobile" id="claimed-profile-video-tab" data-bs-toggle="pill"
                        data-bs-target="#claimed-profile-video" type="button" role="tab"
                        aria-controls="claimed-profile-video" aria-selected="false">
                    {{ translate('Video') }}
                </button>
            </li>
            @endif
        </ul>
        <div class="tab-content" id="claimed-pills-tabContent">
            <div class="tab-pane fade" id="claimed-bids-description" role="tabpanel" aria-labelledby="claimed-desc-tab" tabindex="0">
                @include('auction.web-views.user-profile.partials._product-details._description-content')
            </div>
            <div class="tab-pane fade show active" id="claimed-bids-history" role="tabpanel" aria-labelledby="claimed-history-tab" tabindex="0">
                <div class="live-bidding-here">
                    <div class="collapse show" id="claimed-bidding-details-collapse">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-20">
                            <h4 class="fs-18 fw-semibold mb-0">{{ translate('Bidding History') }}</h4>
                            @if(auth('customer')->check() && auth('customer')->id() != $auctionProduct?->owner_id)
                                <div class="form-check">
                                    <input class="form-check-input form-check-input_theme" type="checkbox" id="myBidClaimedOnly">
                                    <label class="form-check-label fs-13 text-secondary" for="myBidClaimedOnly">
                                        {{ translate('My Bids Only') }}
                                    </label>
                                </div>
                            @endif
                        </div>
                        <div class="bidding-owner-scroll pe-1">
                            <div class="bidding-owner d-flex flex-column gap-10px" id="claimed-bid-list-container"
                                 data-url="{{ route('auction.product.bid-list', $auctionProduct->id) }}"
                                 data-error-message="{{ translate('Failed to load bids.') }}">
                                @include(VIEW_FILE_NAMES['frontend_auction_bid_list'], ['bids' => $bids, 'auctionProduct' => $auctionProduct])
                            </div>
                        </div>
                    </div>
                    <div class="auction-insights pt-3">
                        <div class="accordion" id="claimedAccordion">
                            <div class="accordion-item p-3 rounded light-box border-0">
                                <h2 class="accordion-header mb-12px p-0 bg-transparent outline-0 border-0">
                                    <button class="accordion-button shadow-none p-0 bg-transparent outline-0 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#claimedInsightCollapse" aria-expanded="true" aria-controls="claimedInsightCollapse">
                                        <div class="d-flex align-items-center gap-1 fs-16 fw-bold title-clr">
                                            <img width="18" height="18" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/auction-insights.svg') }}" alt="">
                                            {{ translate('Auction Insights') }}
                                        </div>
                                    </button>
                                </h2>
                                <div id="claimedInsightCollapse" class="accordion-collapse collapse show" data-bs-parent="#claimedAccordion">
                                    <div class="accordion-body p-0" id="claimed-insight-container">
                                        @include(VIEW_FILE_NAMES['frontend_auction_insight_content'], ['bidAnalytics' => $bidAnalytics, 'auctionProduct' => $auctionProduct])
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @if($hasVideo)
            <div class="tab-pane fade" id="claimed-profile-video" role="tabpanel"
                 aria-labelledby="claimed-profile-video-tab" tabindex="0">
                @if($auctionProduct?->video_provider === 'youtube_link')
                    <div class="col-12 mb-4">
                        <iframe width="420" height="315" src="{{ $auctionProduct?->youtube_embed_url }}"
                                title="{{ translate('YouTube video player') }}" frameborder="0"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                    </div>
                @elseif($auctionProduct?->video_provider === 'custom_video')
                    <div>
                        <video class="js-auction-product-player" playsinline controls
                               data-poster="{{ getStorageImages(path: $auctionProduct?->seo?->image_full_url, type: 'product') }}">
                            <source src="{{ getStorageImages(path: $auctionProduct->custom_video_url_full_url, type: 'product') }}" type="video/mp4">
                        </video>
                    </div>
                @endif
            </div>
            @endif
        </div>
        @include('auction.web-views.user-profile.partials._product-details._seo-meta')
    </div>
</div>
