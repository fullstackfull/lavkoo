<?php
    // Shared owner-perspective layout for the three pre-active auction states:
    //   - pending  → awaiting admin approval
    //   - rejected → admin denied (optionally with rejected_note)
    //   - canceled → owner canceled before activation
    //
    // Card structure is identical across all three states; only the sidebar
    // note card and the header action buttons vary. Bid/view counts intentionally
    // omitted — those become meaningful only from UPCOMING state onward.
?>
<div>
    <div class="bid-product-details__wrapper d-flex gap-3">
        <div class="bid-product-details-body">
            @include('auction.web-views.user-profile.partials._product-details._product-header-card', [
                'mode'             => 'inactive',
                'inactiveActions'  => $inactiveStateActions,
            ])

            <div class="card border-0 shadow-sm p-xl-4 p-3">
                @php
                    $hasVideo = ($auctionProduct?->video_provider === 'youtube_link'
                        && $auctionProduct?->youtube_embed_url != null
                        && str_contains($auctionProduct?->youtube_embed_url, "youtube.com/embed/"))
                        || ($auctionProduct?->video_provider === 'custom_video' && $auctionProduct?->custom_video_url);
                @endphp
                <ul class="nav nav-pills mb-20 d-flex align-items-center justify-content-center gap-1">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" type="button" data-bs-target="#description_overview">
                            {{ translate('Overview') }}
                        </button>
                    </li>
                    @if($hasVideo)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="inactive-profile-video-tab" data-bs-toggle="pill"
                                data-bs-target="#inactive-profile-video" type="button" role="tab"
                                aria-controls="inactive-profile-video" aria-selected="false">
                            {{ translate('Video') }}
                        </button>
                    </li>
                    @endif
                </ul>

                <div class="tab-content" id="pills-tabContent">
                    <div class="tab-pane fade show active" id="description_overview">
                        @include('auction.web-views.user-profile.partials._product-details._description-content')
                    </div>
                    @if($hasVideo)
                    <div class="tab-pane fade" id="inactive-profile-video" role="tabpanel"
                         aria-labelledby="inactive-profile-video-tab" tabindex="0">
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

        <div class="bids-product-details-right">
            <div class="d-flex flex-column gap-15px">
                @if($inactiveStateShowStatusSelect)
                    @include('auction.web-views.user-profile.partials._product-details._auction-status-select-card', ['titleWeight' => 'bold'])
                @endif

                @if($inactiveStateShowDeniedNote)
                    <div class="p-15px card border-0 shadow-sm rounded">
                        <div class="fs-14 fw-bold title-clr text-center mb-15">{{ translate('Denied Note') }}</div>
                        <div class="bg-danger bg-opacity-10 rounded w-100 py-2 px-3 fs-14 fw-normal text-danger">
                            {{ $auctionProduct->rejected_note }}
                        </div>
                    </div>
                @endif

                @if(!empty($inactiveStateNote))
                    @include('auction.web-views.user-profile.partials._product-details._status-note-card', [
                        'variant' => $inactiveStateNote['variant'],
                        'title'   => $inactiveStateNote['title'],
                        'message' => $inactiveStateNote['message'],
                    ])
                @endif

                @include('auction.web-views.user-profile.partials._product-details._auction-duration-card', [
                    'titleWeight' => 'semibold',
                    'labelMin'    => 'minmax-xs-100px',
                    'startDate'   => $pendingStartDate,
                    'endDate'     => $pendingEndDate,
                ])

                @include('auction.web-views.user-profile.partials._product-details._pricing-info-card', [
                    'titleWeight'  => 'semibold',
                    'labelMin'     => 'minmax-xs-100px',
                    'showShipping' => true,
                    'taxMode'      => 'always',
                ])

                <div class="p-15px card border-0 shadow-sm rounded">
                    <div class="fs-14 fw-semibold title-clr mb-3">{{ translate('Auction Timeline') }}</div>
                    <div class="d-flex flex-column gap-15px">
                        <div class="d-flex flex-column gap-1 align-items-start justify-content-between w-100">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="fs-14 title-semidark text-nowrap">{{ translate('Auction Created At') }}</div>
                            </div>
                            <div class="info-option2">
                                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $pendingCreatedAt }}</h4>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-1 align-items-start justify-content-between w-100">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="fs-14 title-semidark text-nowrap">{{ translate('Auction Modified At') }}</div>
                            </div>
                            <div class="info-option2">
                                <h4 class="fs-14 m-0 title-clr fw-semibold">{{ $pendingUpdatedAt }}</h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
