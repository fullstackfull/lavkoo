@extends("auction.layouts.auction-app")

@section('title', $auctionProduct['name'])

@push('css_or_js')
    @include(VIEW_FILE_NAMES['product_seo_meta_content_partials'], ['metaContentData' => $auctionProduct?->seoInfo, 'productDetails' => $auctionProduct])
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/product-details.css') }}"/>
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/plyr/plyr.css') }}"/>
@endpush

@section('content')
    <div class="container">
        <div class="row g-3 mb-3">
            <div class="col-xxl-8 col-lg-8">
                <div class="h-100 d-flex flex-column gap-4 pb-2">
                    <div class="card border-0 shadow-sm p-xl-4 p-3 flex-grow-0">
                        <div class="row g-xxl-4 g-3">
                            <div class="col-lg-5 col-md-4">
                                @include("auction.web-views.product.details._product-details-gallery", [
                                    'imageSources' => $imageSources,
                                    'productDetailUrl' => $productDetailUrl,
                                ])
                            </div>

                            <div class="col-lg-7 col-md-8" id="product-cart-option-container">
                                @include(VIEW_FILE_NAMES['frontend_auction_product_cart_option_container'], [
                                    'auctionProduct' => $auctionProduct
                                ])
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm p-xl-4 p-3">
                        <ul class="nav nav-pills mb-20 d-flex align-items-center justify-content-center gap-1"
                            id="pills-tab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="pills-home-tab" data-bs-toggle="pill"
                                        data-bs-target="#pills-home" type="button" role="tab" aria-controls="pills-home"
                                        aria-selected="true">
                                    {{ translate('Overview') }}
                                </button>
                            </li>

                            @if(($auctionProduct?->video_provider === 'youtube_link' && $auctionProduct?->youtube_embed_url != null && str_contains($auctionProduct?->youtube_embed_url, "youtube.com/embed/")) ||
                            $auctionProduct?->video_provider === 'custom_video' && $auctionProduct?->custom_video_url
                            )
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="pills-video-tab" data-bs-toggle="pill"
                                            data-bs-target="#pills-video" type="button" role="tab" aria-controls="pills-video"
                                            aria-selected="true">
                                        {{ translate('Video') }}
                                    </button>
                                </li>
                            @endif

                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="pills-profile-tab" data-bs-toggle="pill"
                                        data-bs-target="#pills-profile" type="button" role="tab"
                                        aria-controls="pills-profile" aria-selected="false">
                                    {{ translate('Live Bidding') }}
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content" id="pills-tabContent">
                            <div class="tab-pane fade show active" id="pills-home" role="tabpanel"
                                 aria-labelledby="pills-home-tab" tabindex="0">
                                <div class="details-content-wrap show-more--content overflow-hidden">
                                    <div class="row g-3">
                                        @if ($auctionProduct['details'])
                                            <div class="col-12">
                                                <h4 class="fs-16 fw-semibold mb-12px">
                                                    {{ translate('Detail Description') }}
                                                </h4>
                                                <div class="fs-14 pragraph-clr m-0 p-details-description" style="max-height: 525px">
                                                    {!! $auctionProduct['details'] !!}
                                                </div>
                                            </div>
                                        @endif
                                    </div>

                                </div>
                                <div class="text-center mt-3">
                                    <button type="button" class="product-details-see-more see-more-details btn px-3 btn-outline-primary py-2">
                                        {{ translate('See More') }}
                                    </button>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="pills-video" role="tabpanel"
                                 aria-labelledby="pills-video-tab" tabindex="0">
                                @if($auctionProduct?->video_provider === 'youtube_link' && $auctionProduct?->youtube_embed_url != null && str_contains($auctionProduct?->youtube_embed_url, "youtube.com/embed/"))
                                    <div class="col-12 mb-4 d-center">
                                        <iframe width="480" height="270" src="{{ $auctionProduct?->youtube_embed_url }}"
                                                title="{{ translate('YouTube video player') }}" frameborder="0"
                                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                                referrerpolicy="strict-origin-when-cross-origin"
                                                allowfullscreen></iframe>
                                    </div>
                                @elseif($auctionProduct?->video_provider === 'custom_video' && $auctionProduct?->custom_video_url)
                                    <div>
                                        <video class="js-auction-product-player" playsinline controls data-poster="{{ getStorageImages(path: $auctionProduct?->seo?->image_full_url, type: 'product') }}">
                                            <source src="{{ getStorageImages(path: $auctionProduct->custom_video_url_full_url, type: 'product') }}" type="video/mp4">
                                        </video>
                                    </div>
                                @endif
                            </div>

                            <div class="tab-pane fade" id="pills-profile" role="tabpanel"
                                 aria-labelledby="pills-profile-tab" tabindex="0">
                                <div class="live-bidding-here">
                                    <div
                                        class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-20">
                                        <h4 class="fs-18 fw-semibold mb-0">{{ translate('Live Bidding Activity') }}</h4>
                                        @if(auth('customer')->check() && auth('customer')->id() != $auctionProduct?->owner_id && $auctionProduct->total_bids > 0 && $auctionProduct?->myBid)
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
                                                    <button
                                                        class="accordion-button shadow-none p-0 bg-transparent outline-0 border-0"
                                                        type="button" data-bs-toggle="collapse"
                                                        data-bs-target="#auctiionInsightCollapse" aria-expanded="true"
                                                        aria-controls="auctiionInsightCollapse">
                                                        <div
                                                            class="d-flex align-items-center gap-1 fs-16 fw-bold title-clr">
                                                            <img width="16" height="16"
                                                                 src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/auction-insights.svg') }}"
                                                                 alt="">
                                                            {{ translate('Auction Insights') }}
                                                        </div>
                                                    </button>
                                                </h2>
                                                <div id="auctiionInsightCollapse"
                                                     class="accordion-collapse collapse show"
                                                     data-bs-parent="#accordionExample">
                                                    <div class="accordion-body p-0" id="insight-container">
                                                        @include(VIEW_FILE_NAMES['frontend_auction_insight_content'], ['bidAnalytics' => $bidAnalytics, 'auctionProduct' => $auctionProduct])
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>


                    </div>
                </div>
            </div>

            <div class="col-xxl-4 col-lg-4">
                <div class="auction-details-right-sidebar">
                    <div class="d-flex flex-column gap-15px">

                        @include("auction.web-views.product.details._product-details-duration", [
                                    'currentStatus' => $currentStatus,
                                    'countdownTarget' => $countdownTarget,
                                    'progressWidth' => $progressWidth,
                                    'isOwner' => $isOwner,
                                ])

                        @if(!empty(getWebConfig(name: 'auction_commitments')))
                            <div class="p-15px card bs-border shadow-sm rounded">
                                <div class="d-flex flex-column gap-15px">
                                    @foreach(getWebConfig(name: 'auction_commitments') as $commitment)
                                        @if(($commitment['status'] ?? 0) == 1 && !empty($commitment['title']))
                                            <div class="d-flex align-items-center gap-2 fs-13 title-clr">
                                                <img loading="lazy" alt="{{ $commitment['title'] }}" width="20" height="20"
                                                     src="{{ getStorageImages(path: $commitment['image_full_url'] ?? null, type: 'source', source: 'public/assets/front-end/img/'.$commitment['item'].'.png') }}">
                                                {{ $commitment['title'] }}
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @if($currentStatus === 'unsold')
                            @php
                                $unsoldMinBidAmount = ($auctionProduct->current_highest_bid_amount > 0)
                                    ? $auctionProduct->current_highest_bid_amount + $auctionProduct->minimum_increment_amount
                                    : $auctionProduct->starting_price;

                                $unsoldTaxRates = collect($auctionProduct->taxVats ?? [])
                                    ->pluck('tax_rate')
                                    ->filter()
                                    ->map(fn($r) => rtrim(rtrim(number_format((float)$r, 2, '.', ''), '0'), '.') . '%')
                                    ->values();

                                $unsoldTaxRateText = $unsoldTaxRates->isNotEmpty()
                                    ? $unsoldTaxRates->implode(', ')
                                    : translate('N/A');
                            @endphp

                            <div class="p-15px card bs-border shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr mb-3">{{ translate('Bidding Info') }}</div>
                                <div class="d-flex flex-column gap-15px">
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Highest Bid') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ webCurrencyConverter(amount: $auctionProduct->current_highest_bid_amount ?: 0) }}
                                        </h4>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Total Bid') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ formatCompactNumber($auctionProduct->total_bids) }}
                                        </h4>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Total View') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ formatCompactNumber($auctionProduct->total_views) }}
                                        </h4>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Fee Provide') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ formatCompactNumber($auctionProduct->participants_count ?? 0) }}
                                        </h4>
                                    </div>
                                </div>
                            </div>

                            <div class="p-15px card bs-border shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr mb-3">{{ translate('Pricing Info') }}</div>
                                <div class="d-flex flex-column gap-15px">
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Starting Price') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ webCurrencyConverter(amount: $auctionProduct->starting_price) }}
                                        </h4>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Min Increment') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ webCurrencyConverter(amount: $auctionProduct->minimum_increment_amount) }}
                                        </h4>
                                    </div>
                                    @if(!is_null($auctionProduct?->maximum_decrement_amount))
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Max Decrement') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ webCurrencyConverter(amount: $auctionProduct->maximum_decrement_amount) }}
                                        </h4>
                                    </div>
                                    @endif
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Min Bid Amount') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ webCurrencyConverter(amount: $unsoldMinBidAmount) }}
                                        </h4>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-sm-120px fs-14 title-semidark">{{ translate('Tax Rate') }}</div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <h4 class="fs-14 m-0 title-clr fw-semibold">
                                            {{ $unsoldTaxRateText }}
                                        </h4>
                                    </div>
                                </div>
                            </div>

                            <div class="p-15px card bs-border shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr mb-3">{{ translate('Auction Timeline') }}</div>
                                <div class="d-flex flex-column gap-15px">
                                    <div class="d-flex flex-column gap-1">
                                        <div class="fs-14 title-semidark">{{ translate('Auction Created At') }}</div>
                                        <div class="d-flex justify-content-start">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold d-flex ltr">
                                                {{ $auctionProduct->created_at?->format('d M Y, h:i A') ?? translate('N/A') }}
                                            </h4>
                                        </div>
                                    </div>
                                    <div class="d-flex flex-column gap-1">
                                        <div class="fs-14 title-semidark">{{ translate('Auction Approved At') }}</div>
                                        <div class="d-flex justify-content-start">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold d-flex ltr">
                                                {{ $auctionProduct->approved_at?->format('d M Y, h:i A') ?? translate('N/A') }}
                                            </h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

<div class="card bs-border shadow-sm">
                            <div class="card-body">
                                @php
                                    $ownerType  = $auctionProduct->owner_type;
                                    $ownerLabel = translate('Auction Author');
                                    $authorAuctionsUrl = route('auction.products.author', ['author_type' => $auctionProduct->owner_type, 'author_id' => $auctionProduct->owner_id]);
                                    $canChatWithOwner = $ownerType === 'admin' || ($ownerType === 'seller' && $auctionProduct->seller);
                                    if ($ownerType === 'seller' && isset($auctionProduct->seller->shop)) {
                                        $ownerName  = $auctionProduct->seller->shop->name;
                                        $ownerImage = getStorageImages(path: $auctionProduct->seller->shop->image_full_url, type: 'shop');
                                        $ownerUrl   = $authorAuctionsUrl;
                                    } elseif ($ownerType === 'admin') {
                                        $ownerName  = getInHouseShopConfig(key: 'name');
                                        $ownerImage = getStorageImages(path: getInHouseShopConfig(key: 'image_full_url'), type: 'shop');
                                        $ownerUrl   = $authorAuctionsUrl;
                                    } else {
                                        $ownerName  = $auctionProduct->customer?->name ?? translate('Unknown');
                                        $ownerImage = getStorageImages(path: $auctionProduct->customer?->image_full_url, type:'avatar');
                                        $ownerUrl   = $authorAuctionsUrl;
                                    }
                                @endphp

                                <div class="d-flex gap-3 align-items-center justify-content-between">
                                    <div class="d-flex gap-10px align-items-center">
                                        @if($ownerUrl)
                                            <a href="{{ $ownerUrl }}" class="rounded w-50px h-50px min-w-50px overflow-hidden d-block rounded-circle flex-shrink-0">
                                                <img src="{{ $ownerImage }}" alt="{{ $ownerName }}" class="w-100 h-100 object-cover">
                                            </a>
                                        @else
                                            <div class="rounded w-50px h-50px min-w-50px overflow-hidden rounded-circle flex-shrink-0">
                                                <img src="{{ $ownerImage }}" alt="{{ $ownerName }}" class="w-100 h-100 object-cover">
                                            </div>
                                        @endif
                                        <div>
                                            @if($ownerUrl)
                                                <a href="{{ $ownerUrl }}" class="text-decoration-none">
                                                    <h6 class="mb-1 fs-14 text-capitalize fw-semibold title-clr line--limit-1">{{ $ownerName }}</h6>
                                                </a>
                                            @else
                                                <h6 class="mb-1 fs-14 text-capitalize fw-semibold title-clr line--limit-1">{{ $ownerName }}</h6>
                                            @endif
                                            <p class="m-0 fs-13 title-semidark">{{ $ownerLabel }}</p>
                                        </div>
                                    </div>

                                    @if($canChatWithOwner)
                                        @if(auth('customer')->check())
                                            <button type="button"
                                                    class="btn btn-primary py-2 fs-12 d-flex align-items-center gap-2 px-3"
                                                    data-bs-toggle="modal" data-bs-target="#chatting_modal">
                                                <i class="fi fi-rr-comment fs-14"></i>
                                            </button>
                                        @else
                                            <a href="{{ route('customer.auth.login') }}"
                                               class="btn btn-primary py-2 fs-12 d-flex align-items-center gap-2 px-3">
                                                <i class="fi fi-rr-comment fs-14"></i>
                                            </a>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if($canChatWithOwner)
                            @include('auction.web-views.product.details._chatting', [
                                'seller'     => $auctionProduct->seller ?? null,
                                'user_type'  => $ownerType === 'seller' ? 'seller' : 'admin',
                            ])
                        @endif

                        @if(!$isOwner && $isParticipant && $auctionProduct?->myParticipation && $auctionProduct?->auction_current_status == 'live' && $auctionProduct?->myParticipation?->entry_fee_payment_method == 'offline_payment' && $auctionProduct?->myParticipation?->entry_fee_paid_status == 'unpaid')
                            <?php
                                $participation      = $auctionProduct->myParticipation;
                                $transaction        = $participation->auctionTransaction;
                                $rawPaymentInfo     = $transaction?->payment_info;
                                $paymentInfo        = $rawPaymentInfo
                                    ? (is_array($rawPaymentInfo) ? $rawPaymentInfo : json_decode($rawPaymentInfo, true))
                                    : [];
                                $methodId           = $paymentInfo['method_id'] ?? null;
                                $offlineMethod      = $methodId ? $offline_payment_methods->firstWhere('id', $methodId) : null;
                                $methodFields = $offlineMethod
                                    ? (is_array($offlineMethod->method_fields)
                                        ? $offlineMethod->method_fields
                                        : json_decode($offlineMethod->method_fields, true))
                                    : [];
                                $paymentStatus = $participation->entry_fee_payment_status ?? 'pending';
                            ?>

                            <div class="card bs-border shadow-sm">
                                <div class="card-body p-15px d-flex flex-column gap-3">

                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <h6 class="fs-14 fw-semibold title-clr mb-0">
                                            {{ translate('Participate Payment Details') }}
                                        </h6>
                                        @if($paymentStatus === 'pending')
                                            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-10px py-1 fs-12 fw-medium">
                                                {{ translate('Pending') }}
                                            </span>
                                        @elseif($paymentStatus === 'verified')
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-10px py-1 fs-12 fw-medium">
                                                {{ translate('Verified') }}
                                            </span>
                                        @elseif($paymentStatus === 'denied')
                                            <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-10px py-1 fs-12 fw-medium">
                                                {{ translate('Denied') }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="fs-13 title-semidark">{{ translate('Auction Payment Participate fee') }}</span>
                                        <strong class="fs-14 fw-semibold title-clr">
                                            {{ webCurrencyConverter(amount: $entryFeeAmount) }}
                                        </strong>
                                    </div>

                                    <div class="offline-payment-details-wrap d-flex flex-column gap-3">
                                        @if($paymentStatus === 'denied' && !empty($participation?->entry_fee_denied_note))
                                            <div class="bg-danger bg-opacity-10 rounded p-3">
                                                <h6 class="fs-13 fw-semibold title-clr mb-3">
                                                    {{ translate('Denied Note') }}
                                                </h6>
                                                <div class="d-flex flex-column gap-2">
                                                    <p>{{ $participation?->entry_fee_denied_note }}</p>
                                                </div>
                                            </div>
                                        @endif

                                        @if($paymentStatus === 'denied')
                                            <div class="bg-info bg-opacity-10 rounded p-3 d-flex align-items-center gap-2">
                                                <i class="fi fi-sr-info text-info"></i>
                                                <span class="fs-13 title-clr">
                                                    {{ translate('If_you_have_any_further_queries_please') }}
                                                    <a href="{{ route('account-tickets') }}" class="text-info fw-semibold text-underline">{{ translate('create_a_ticket') }}</a>
                                                </span>
                                            </div>
                                        @endif

                                        @if($offlineMethod && !empty($methodFields))
                                            <div class="light-box rounded p-3">
                                                <h6 class="fs-13 fw-semibold title-clr mb-3">
                                                    {{ $offlineMethod['method_name'] }} {{ translate('Details') }}
                                                </h6>
                                                <div class="d-flex flex-column gap-2">
                                                    @foreach($methodFields as $info)
                                                        <div class="d-flex align-items-start gap-2">
                                                            <span class="fs-13 title-semidark minmax-sm-110px flex-shrink-0">
                                                                {{ translate(ucwords(str_replace('_', ' ', $info['input_name'] ?? ''))) }}
                                                            </span>
                                                            <span class="title-semidark fs-13 flex-shrink-0">:</span>
                                                            <span class="fs-13 title-clr fw-medium">{{ $info['input_data'] ?? '' }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif

                                        @if(!empty($paymentInfo))
                                            <div class="light-box rounded p-3">
                                                <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                                    <h6 class="fs-13 fw-semibold title-clr mb-0">{{ translate('My Submitted info') }}</h6>
                                                    @if($paymentStatus === 'pending')
                                                        <button type="button"
                                                                class="btn btn-outline-primary p-0 d-flex align-items-center justify-content-center rounded js-edit-offline-payment-btn"
                                                                style="width:28px;height:28px;min-width:28px;"
                                                                data-method-id="{{ $methodId }}"
                                                                data-amount="{{ $entryFeeAmount }}"
                                                                data-payment-info='{!! json_encode($paymentInfo, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) !!}'>
                                                            <i class="fi fi-rr-pencil fs-12"></i>
                                                        </button>
                                                    @endif
                                                </div>
                                                <div class="d-flex flex-column gap-2">
                                                    @foreach($paymentInfo as $key => $value)
                                                        @if(in_array($key, ['method_id', 'payment_note', 'method_informations']) || is_array($value)) @continue @endif
                                                        <div class="d-flex align-items-start gap-2">
                                                            <span class="fs-13 title-semidark minmax-sm-110px flex-shrink-0">
                                                                {{ translate(ucwords(str_replace('_', ' ', $key))) }}
                                                            </span>
                                                            <span class="title-semidark fs-13 flex-shrink-0">:</span>
                                                            <span class="fs-13 title-clr fw-medium">{{ filled($value) ? $value : translate('N/A') }}</span>
                                                        </div>
                                                    @endforeach
                                                    @foreach($paymentInfo['method_informations'] ?? [] as $fieldKey => $fieldValue)
                                                        <div class="d-flex align-items-start gap-2">
                                                            <span class="fs-13 title-semidark minmax-sm-110px flex-shrink-0">
                                                                {{ translate(ucwords(str_replace('_', ' ', $fieldKey))) }}
                                                            </span>
                                                            <span class="title-semidark fs-13 flex-shrink-0">:</span>
                                                            <span class="fs-13 title-clr fw-medium">{{ filled($fieldValue) ? $fieldValue : translate('N/A') }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @if(!empty($paymentInfo['payment_note']))
                                                    <div class="mt-2 fs-13 title-semidark">
                                                        {{ translate('Payment Note') }} :
                                                        <span class="title-clr fw-medium">{{ $paymentInfo['payment_note'] }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        @endif

                                    </div>
                                    <div class="text-center">
                                        <button type="button"
                                                class="btn btn-link text-primary fs-13 p-0 js-offline-payment-toggle"
                                                data-more="{{ translate('See More') }}"
                                                data-less="{{ translate('See Less') }}">
                                            {{ translate('See Less') }}
                                        </button>
                                    </div>

                                </div>
                            </div>

                        @elseif($authorAuctions->isNotEmpty())
                            <div class="pt-1">
                                <div class="d-flex justify-content-between align-items-center gap-2 mb-3 pe-xl-3">
                                    <h5 class="m-0 text-capitalize fw-bold fs-16 d-flex align-items-center gap-1">
                                        <span class="line--limit-1">{{ translate('Auctions from this author') }}</span>
                                    </h5>
                                    <div class="text-end">
                                        <a class="d-flex align-items-center text-nowrap gap-1 text-capitalize text-decoration-none fs-14 view-all-text text-primary"
                                           href="{{ $authorAuctionsUrl }}">
                                            {{ translate('view_all') }}
                                            <i class="fi fi-rr-angle-right fs-12"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="d-flex flex-column gap-xxl-20px gap-3">
                                    @foreach($authorAuctions as $item)
                                        @php
                                            $itemStatus    = $item->auction_current_status;
                                            $itemThumb     = getStorageImages(path: $item->thumbnail_full_url, type: 'product');
                                            $itemUrl       = route('auction.product-details', $item->slug);
                                            $badgeClass    = match($itemStatus) {
                                                'live'     => 'bg-danger',
                                                'upcoming' => 'bg-success',
                                                default    => 'bg-secondary',
                                            };
                                            $badgeLabel    = translate(ucfirst($itemStatus ?? 'ended'));
                                            $timerEnd      = $itemStatus === 'upcoming'
                                                ? $item->start_time?->format('Y-m-d H:i:s')
                                                : $item->end_time?->format('Y-m-d H:i:s');
                                        @endphp
                                        <div class="item">
                                            <div class="ending-soon-card bg-white border-0 p-10px rounded position-relative">
                                                <span
                                                    class="badge {{ $badgeClass }} rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1">
                                                    {{ $badgeLabel }}
                                                </span>
                                                <div class="d-flex gap-10px align-items-center">
                                                    <div
                                                        class="rounded w-100px h-100px min-w-100px overflow-hidden text-center border position-relative">
                                                        <a href="{{ $itemUrl }}" class="m-thumbnail d-block h-100">
                                                            <img src="{{ $itemThumb }}" alt="{{ $item->name }}"
                                                                 class="w-100 h-100 object-cover">
                                                        </a>
                                                        @if($itemStatus === 'live' && $timerEnd)
                                                            <div
                                                                class="d-flex justify-content-between w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                                <div class="flex-aligns gap-1">
                                                                    <i class="fi fi-rr-time-oclock fs-10 text-danger"></i>
                                                                    <div class="d-flex gap-1 countdown"
                                                                         data-end="{{ $timerEnd }}">
                                                                        <div
                                                                            class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                            <span
                                                                                class="time hours fw-semibold text-danger fs-10">00</span>
                                                                            <div class="small text-danger fs-10">h</div>
                                                                        </div>
                                                                        <div
                                                                            class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                            <span
                                                                                class="time minutes fw-semibold text-danger fs-10">00</span>
                                                                            <div class="small text-danger fs-10">m</div>
                                                                        </div>
                                                                        <div
                                                                            class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                            <span
                                                                                class="time seconds fw-semibold text-danger fs-10">00</span>
                                                                            <div class="small text-danger fs-12">s</div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif
                                                        @if($timerEnd)
                                                        <div class="d-flex justify-content-between w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
                                                            <div class="flex-aligns gap-1">
                                                                <i class="fi fi-rr-time-oclock fs-10 text-danger"></i>
                                                                <div class="d-flex gap-1 countdown"
                                                                    data-end="{{ $timerEnd }}">
                                                                    <div
                                                                        class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                        <span
                                                                            class="time hours fw-semibold text-danger fs-10">00</span>
                                                                        <div class="small text-danger fs-10">h
                                                                        </div>
                                                                    </div>
                                                                    <div
                                                                        class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                        <span
                                                                            class="time minutes fw-semibold text-danger fs-10">00</span>
                                                                        <div class="small text-danger fs-10">m
                                                                        </div>
                                                                    </div>
                                                                    <div
                                                                        class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                        <span
                                                                            class="time seconds fw-semibold text-danger fs-10">00</span>
                                                                        <div class="small text-danger fs-10">s
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        @endif
                                                    </div>
                                                    <div class="w-100">
                                                        <h6 class="mb-10px">
                                                            <a href="{{ $itemUrl }}"
                                                               class="text-decoration-none fs-13 text-capitalize fw-semibold title-clr line--limit-1">
                                                                {{ $item->name }}
                                                            </a>
                                                        </h6>
                                                        @if($itemStatus === 'upcoming')
                                                            <div class="d-flex justify-content-between mb-10px align-items-center">
                                                                <span
                                                                    class="fs-12 title-semidark">{{ translate('Start Price') }}</span>
                                                                <strong
                                                                    class="text-primary fs-15px fw-bold">{{ webCurrencyConverter(amount: $item->starting_price) }}</strong>
                                                            </div>
                                                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                                <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                                    <span
                                                                        class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                                                            {{ formatCompactNumber($item->total_views) }}
                                                                        </span>
                                                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                        <img
                                                                            src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}"
                                                                            alt="" class="svg">
                                                                        {{ formatCompactNumber($item->total_bids) }}
                                                                    </span>
                                                                </div>
                                                                @if($item->myParticipation)
                                                                    <button type="button" disabled
                                                                            class="btn bg-primary bg-opacity-10 text-primary rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                        {{ translate('Participated') }}
                                                                    </button>
                                                                @elseif(auth('customer')->check())
                                                                    <a href="{{ $itemUrl }}?open_participate=1"
                                                                       class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                        {{ translate('Participate') }}
                                                                    </a>
                                                                @else
                                                                    <a href="{{ route('customer.auth.login') }}"
                                                                       class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                        {{ translate('Participate') }}
                                                                    </a>
                                                                @endif
                                                            </div>
                                                        @else
                                                            @if($item?->current_highest_bid_amount > 0)
                                                                <div class="d-flex justify-content-between mb-12px align-items-center">
                                                                <span class="fs-12 title-semidark">
                                                                    {{ translate('Highest Bid') }}
                                                                </span>
                                                                    <strong class="text-primary fs-15px fw-bold">
                                                                        {{ webCurrencyConverter(amount: $item->current_highest_bid_amount) }}
                                                                    </strong>
                                                                </div>
                                                            @else
                                                                <div class="d-flex justify-content-between mb-12px align-items-center">
                                                                <span class="fs-12 title-semidark">
                                                                    {{ translate('Start Price') }}
                                                                </span>
                                                                    <strong class="text-primary fs-15px fw-bold">
                                                                        {{ webCurrencyConverter(amount: $item->starting_price) }}
                                                                    </strong>
                                                                </div>
                                                            @endif

                                                            <div
                                                                class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                                <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                                    <span
                                                                        class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                        <i class="fi fi-rr-eye text-light-gray"></i>
                                                                        {{ formatCompactNumber($item->total_views) }}
                                                                    </span>
                                                                    <span
                                                                        class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                        <img
                                                                            src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}"
                                                                            alt="" class="svg">
                                                                        {{ formatCompactNumber($item->total_bids) }}
                                                                    </span>
                                                                </div>
                                                                @if($itemStatus === 'live')
                                                                    @if(auth('customer')->check())
                                                                        <a href="{{ $itemUrl }}?open_bid=1"
                                                                           class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                            <img
                                                                                src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}"
                                                                                alt="" class="svg">
                                                                            {{ $item->myBid ? translate('Rise Bid') : translate('Bid') }}
                                                                        </a>
                                                                    @else
                                                                        <a href="{{ route('customer.auth.login') }}"
                                                                           class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                            {{ translate('Bid') }}
                                                                        </a>
                                                                    @endif
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

        </div>

        @include("auction.web-views.partials._similar-auctions-card", ['similarAuctions' => $similarAuctions, 'auctionCategory' => $auctionProduct->category])

        @if(getWebConfig(name: 'auction_entry_fee_amount_status') && getWebConfig(name: 'auction_entry_fee_amount_value') > 0)
            @include("auction.web-views.product._participate-entry-info")
        @endif

        @include("auction.web-views.product.details._place-bid-modal", ['auctionProduct' => $auctionProduct])

        <div class="modal fade" id="participation_confirm" tabindex="-1" aria-labelledby="participation_confirmLabel"
             aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content p-3 rounded-4">
                    <div class="modal-header pt-0 border-0 justify-content-center">
                        <button type="button"
                                class="position-absolute top-0 inset-inline-end-0 m-2 btn p-0 text-light-gray bg-secondary rounded-circle d-center w-35px h-35px min-w-35px"
                                data-bs-dismiss="modal" aria-label="Close">
                            <i class="fi fi-rr-cross-small"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="box text-center">
                            <img alt="" class="mb-20"
                                src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/check-big.svg') }}">
                            <h3 class="fw-bold fs-16 title-clr mb-2">
                                {{ translate('Participation Confirmed!') }}
                            </h3>
                            <p class="fs-14 pragraph-clr2 mb-0 px-sm-3">
                                {{ translate('You have successfully joined this auction.') }}
                                {{ translate('You will be notified as soon as it goes live.') }}
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer justify-content-center border-0">
                        <div class="max-w-200px mx-auto w-100">
                            <a href="{{ auth('customer')->check() && auth('customer')->id() == $auctionProduct?->owner_id && $auctionProduct?->owner_type == 'customer' ? route('auction.profile-view.author-product-details', ['slug' => $auctionProduct?->slug]) : route('auction.profile-view.product-details', ['slug' => $auctionProduct?->slug]) }}"
                               class="w-100 btn btn-primary"> {{ translate('View Details') }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <span id="all-msg-container" data-afterextend="{{translate('see_less')}}" data-seemore="{{translate('see_more')}}"></span>
@endsection

@push('script')
    <script src="{{ theme_asset(path: 'public/assets/front-end/auction/js/auction-product-details.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/backend/libs/plyr/plyr.js') }}"></script>
    <script>
        (function () {
            if (typeof Plyr === 'undefined') return;
            document.querySelectorAll('.js-auction-product-player').forEach(function (el) {
                new Plyr(el, {
                    iconUrl: '{{ dynamicAsset(path: 'public/assets/backend/libs/plyr/plyr.svg') }}'
                });
            });
        })();
    </script>

    <script>
        (function () {
            // See Less / See More toggle for offline payment details card
            document.querySelectorAll('.js-offline-payment-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const wrap = this.closest('.card-body')?.querySelector('.offline-payment-details-wrap');
                    if (!wrap) return;
                    const isHidden = wrap.classList.toggle('d-none');
                    this.textContent = isHidden
                        ? (this.dataset.more || '{{ translate('See More') }}')
                        : (this.dataset.less || '{{ translate('See Less') }}');
                });
            });

            // Edit offline payment — load method fields, pre-fill existing values, open modal
            function setOfflineFieldValue(wrap, name, value) {
                if (value === null || value === undefined) return false;
                const field = wrap.querySelector('[name="' + name + '"]');
                if (!field) return false;
                field.value = value;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
                return true;
            }

            function prefillOfflinePayment(wrap, paymentInfo) {
                Object.entries(paymentInfo).forEach(function ([key, value]) {
                    // Hidden method_id / method_name are already rendered server-side.
                    if (key === 'method_id' || key === 'method_name') return;

                    // Dynamic fields submitted as method_informations[<input>].
                    if (key === 'method_informations' && value && typeof value === 'object') {
                        Object.entries(value).forEach(function ([fieldKey, fieldValue]) {
                            setOfflineFieldValue(wrap, 'method_informations[' + fieldKey + ']', fieldValue);
                        });
                        return;
                    }

                    // Payment note submitted as offline_payment[payment_note].
                    if (key === 'payment_note') {
                        setOfflineFieldValue(wrap, 'offline_payment[payment_note]', value);
                        return;
                    }

                    // Fallback: nested objects → bracket notation, scalars → direct or offline_payment[].
                    if (value && typeof value === 'object') {
                        Object.entries(value).forEach(function ([fieldKey, fieldValue]) {
                            setOfflineFieldValue(wrap, key + '[' + fieldKey + ']', fieldValue);
                        });
                    } else if (!setOfflineFieldValue(wrap, key, value)) {
                        setOfflineFieldValue(wrap, 'offline_payment[' + key + ']', value);
                    }
                });
            }

            document.querySelectorAll('.js-edit-offline-payment-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const methodId    = this.dataset.methodId;
                    const amount      = this.dataset.amount;
                    const routeUrl    = document.getElementById('route-pay-offline-method-list')?.dataset?.url;
                    const offlineModal = document.getElementById('modal-auction-offline-payment');
                    const fieldWrap   = document.getElementById('auction_offline_payment_field');
                    const goBackBtn   = document.getElementById('auction-offline-go-back');
                    if (!routeUrl || !methodId || !offlineModal || !fieldWrap) return;

                    // Opened from My Submitted Info → Edit: no previous step, hide Go Back.
                    if (goBackBtn) goBackBtn.classList.add('d-none');

                    let paymentInfo = {};
                    try { paymentInfo = JSON.parse(this.dataset.paymentInfo || '{}'); } catch (e) {}

                    fieldWrap.innerHTML = '';
                    $.ajax({
                        url: routeUrl + '?method_id=' + methodId + '&edit_due_amount=' + encodeURIComponent(amount || 0),
                        type: 'GET',
                        success: function (response) {
                            fieldWrap.innerHTML = response?.methodHtml || '';
                            prefillOfflinePayment(fieldWrap, paymentInfo);
                            new bootstrap.Modal(offlineModal).show();
                        }
                    });
                });
            });
        })();
    </script>

    @if($isOwner)
        {{-- Hidden form used by the Cancel Auction SweetAlert flow (owner only) --}}
        <form id="js-cancel-auction-form" method="POST"
              action="{{ route('auction.cancel') }}" class="d-none">
            @csrf
            <input type="hidden" name="auction_product_id" id="js-cancel-auction-product-id" value="">
        </form>

        <script>
            (function () {
                document.querySelectorAll('.js-cancel-auction-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const auctionId = this.dataset.auctionId;
                        Swal.fire({
                            title: '{{ translate('are_you_sure') }}?',
                            text: '{{ translate('Do you want to cancel this auction? This action cannot be undone.') }}',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#d33',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: '{{ translate('Yes, Cancel Auction') }}',
                            cancelButtonText: '{{ translate('No') }}',
                            reverseButtons: true,
                        }).then(function (result) {
                            if (result.isConfirmed) {
                                document.getElementById('js-cancel-auction-product-id').value = auctionId;
                                document.getElementById('js-cancel-auction-form').submit();
                            }
                        });
                    });
                });
            })();
        </script>

        <form id="js-delete-auction-form" method="POST"
              action="{{ route('auction.delete') }}" class="d-none">
            @csrf
            <input type="hidden" name="auction_product_id" id="js-delete-auction-product-id" value="">
        </form>

        <script>
            (function () {
                document.querySelectorAll('.js-delete-auction-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const auctionId = this.dataset.auctionId;
                        Swal.fire({
                            title: '{{ translate('Delete_Auction') }}',
                            text: '{{ translate('This_will_permanently_delete_the_auction_and_all_associated_data._This_action_cannot_be_undone.') }}',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#d33',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: '{{ translate('Yes,_Delete') }}',
                            cancelButtonText: '{{ translate('Cancel') }}',
                            reverseButtons: true,
                        }).then(function (result) {
                            if (result.isConfirmed) {
                                document.getElementById('js-delete-auction-product-id').value = auctionId;
                                document.getElementById('js-delete-auction-form').submit();
                            }
                        });
                    });
                });
            })();
        </script>
    @endif
@endpush


