<div class="bid-product-details-body">
    @include('auction.web-views.user-profile.partials._product-details._product-header-card', ['mode' => 'live'])

    <div class="card border-0 shadow-sm p-xl-4 p-3">
        <?php
            $isUpcomingStatus = $auctionProduct?->auction_current_status === \Modules\Auction\app\Enums\AuctionStatus::UPCOMING;
            $hasVideo = ($auctionProduct?->video_provider === 'youtube_link'
                && $auctionProduct?->youtube_embed_url != null
                && str_contains($auctionProduct?->youtube_embed_url, "youtube.com/embed/"))
                || ($auctionProduct?->video_provider === 'custom_video' && $auctionProduct?->custom_video_url);
        ?>
        <ul class="nav nav-pills mb-20 d-flex align-items-center justify-content-center gap-1" id="pills-tab" role="tablist">
            @if(!$isUpcomingStatus)
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="description_cus-tab" data-bs-toggle="pill" data-bs-target="#bids_descriptions" type="button" role="tab" aria-controls="bids_descriptions" aria-selected="true">
                        {{ translate('Live Biding') }}
                    </button>
                </li>
            @endif
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $isUpcomingStatus ? 'active' : '' }}" id="bids_cus-tab" data-bs-toggle="pill" data-bs-target="#bids_live_bidding" type="button" role="tab" aria-controls="bids_live_bidding" aria-selected="{{ $isUpcomingStatus ? 'true' : 'false' }}">
                    {{ translate('Product Description') }}
                </button>
            </li>
            @if($hasVideo)
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="profile-video-tab" data-bs-toggle="pill"
                        data-bs-target="#profile-video" type="button" role="tab"
                        aria-controls="profile-video" aria-selected="false">
                    {{ translate('Video') }}
                </button>
            </li>
            @endif
        </ul>
        <div class="tab-content" id="pills-tabContent">
            @if(!$isUpcomingStatus)
                <div class="tab-pane fade show active" id="bids_descriptions" role="tabpanel" aria-labelledby="description_cus-tab" tabindex="0">
                    <div class="live-bidding-here">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-20">
                            <h4 class="fs-18 fw-semibold mb-0">{{ translate('Live Bidding Activity') }}</h4>
                            @if(auth('customer')->check() && auth('customer')->id() != $auctionProduct?->owner_id)
                                <div class="form-check">
                                    <input class="form-check-input form-check-input_theme"
                                           type="checkbox" id="myBidonly">
                                    <label class="form-check-label fs-13 text-secondary" for="myBidonly">
                                        {{ translate('My Bids Only') }}
                                    </label>
                                </div>
                            @endif
                        </div>

                        <div class="bidding-owner-scroll pe-1">
                            <div class="bidding-owner d-flex flex-column gap-10px" id="bid-list-container"
                                 data-url="{{ route('auction.product.bid-list', $auctionProduct->id) }}"
                                 data-card-url="{{ route('auction.product.card-html', $auctionProduct->id) }}"
                                 data-auction-id="{{ $auctionProduct->id }}"
                                 data-error-message="{{ translate('Failed to load bids.') }}">
                                @include(VIEW_FILE_NAMES['frontend_auction_bid_list'], ['bids' => $bids, 'auctionProduct' => $auctionProduct])
                            </div>
                        </div>

                        <div class="auction-insights pt-3">
                            <div class="accordion" id="accordionExample">
                                <div class="accordion-item p-3 rounded light-box border-0">
                                    <h2 class="accordion-header mb-12px p-0 bg-transparent outline-0 border-0">
                                        <button class="accordion-button shadow-none p-0 bg-transparent outline-0 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#auctiionInsightCollapse" aria-expanded="true" aria-controls="auctiionInsightCollapse">
                                            <div class="d-flex align-items-center gap-1 fs-16 fw-bold title-clr">
                                                <img width="18" height="18" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/auction-insights.svg') }}" alt="">
                                                {{ translate('Auction Insights') }}
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="auctiionInsightCollapse" class="accordion-collapse collapse show" data-bs-parent="#accordionExample">
                                        <div class="accordion-body p-0" id="insight-container">
                                            @include(VIEW_FILE_NAMES['frontend_auction_insight_content'], ['bidAnalytics' => $bidAnalytics, 'auctionProduct' => $auctionProduct])
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
            <div class="tab-pane fade {{ $isUpcomingStatus ? 'show active' : '' }}" id="bids_live_bidding" role="tabpanel" aria-labelledby="bids_cus-tab" tabindex="0">
                @include('auction.web-views.user-profile.partials._product-details._description-content', ['centerButton' => true])
            </div>
            @if($hasVideo)
            <div class="tab-pane fade" id="profile-video" role="tabpanel"
                 aria-labelledby="profile-video-tab" tabindex="0">
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
