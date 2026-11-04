@extends("auction.layouts.auction-app")

@section('title', 'Product Details')

@section('content')
<div class="container">

<div>
    <div class="d-lg-none d-block">
        <div class="w-100 bg-white shadow-sm rounded p-3 mb-3 justify-content-between d-flex gap-2 align-items-center">
            <h5 class="fs-18 fw-semibold title-clr text-capitalize m-0">{{ translate('Profile Info') }}</h5>
            <div>
                <button type="button" class="btn btn-primary border-0 px-12px" data-bs-toggle="offcanvas" data-bs-target="#profile_aside_btn">
                    <i class="fi fi-sr-apps"></i>
                </button>
            </div>
        </div>
    </div>
    <div class="">
        <div class="row g-3">
            <div class="col-lg-3 d-lg-block d-none">
                <div class="">
                    @include("auction.web-views.user-profile._profile-sidebar")
                </div>
            </div>
            <div class="col-lg-9">
                <div class="w-100 mb-20 justify-content-between flex-wrap d-flex gap-2 align-items-end">
                    <div>
                        <h3 class="fs-18 fw-semibold d-flex align-items-center gap-10px title-clr text-capitalize mb-1">
                            Auction ID#564564
                            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill">
                                Pending
                            </span>
                        </h3>
                        <span class="title-semidark fs-14">Claimed date : 08 jan 2025, 10:20 PM</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary gap-5px d-center">
                            <i class="fi fi-rr-file-download fs-13"></i> Invoice
                        </button>
                    </div>
                </div>
                <div class="bid-product-details__wrapper bid-product-details__wrapper2 d-flex gap-3">
                    <div class="bid-product-details-body">
                        <div class="card bs-border rounded overflow-hidden shadow-sm mb-20">
                            <div class="cards-title fs-14 fw-semibold light-box py-12px px-xxl-20 px-3">
                                Product Information
                            </div>
                            <div class="card-body p-xxl-20px p-3">
                                <div class="d-flex align-items-sm-center gap-2">
                                    <a href="#0" class="bids-details-thumb2 d-center position-relative z-1 rounded overflow-hidden">
                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/thumb.png') }}" alt="" class="w-100 h-100">
                                    </a>
                                    <div class="">
                                        <h2 class="mb-2 fs-13 line--limit-2 title-clr fw-semibold">
                                            {{ translate('LG C2 42 (106cm) 4K Smart OLED evo TV | WebOS | Cinema HDR') }}
                                        </h2>
                                        <div class="d-flex flex-column gap-2 auction-details-right-content">
                                            <div class="d-flex align-items-center gap-5px">
                                                <div class="minmax-xs-100px fs-14 title-semidark lh-sm">
                                                    {{ translate('Item condition') }}
                                                </div>
                                                <span class="title-semidark lh-1">:</span>
                                                <div class="info-option2">
                                                    <h4 class="fs-12 m-0 title-clr fw-normal">New Condition</h4>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-5px">
                                                <div class="minmax-xs-100px fs-14 title-semidark lh-sm">
                                                    {{ translate('Category') }}
                                                </div>
                                                <span class="title-semidark lh-1">:</span>
                                                <div class="info-option2">
                                                    <h4 class="fs-12 m-0 title-clr fw-normal">Gadget</h4>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-5px">
                                                <div class="minmax-xs-100px fs-14 title-semidark lh-sm">
                                                    {{ translate('Product Type') }}
                                                </div>
                                                <span class="title-semidark lh-1">:</span>
                                                <div class="info-option2">
                                                    <h4 class="fs-12 m-0 title-clr fw-normal">Physical</h4>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card bs-border p-xxl-20px p-3 rounded overflow-hidden shadow-sm mb-20">
                            <div class="cards-title fs-16 mb-15 fw-semibold">
                                Auction Info
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="bg-light rounded p-12px d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center gap-12px">
                                            <img width="30" height="30" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/total-view-user.png') }}" alt="">
                                            <span class="title-semidark fs-14">Total Viewed</span>
                                        </div>
                                        <h4 class="m-0 fs-16 fw-semibold">500</h4>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="bg-light rounded p-12px d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center gap-12px">
                                            <img width="30" height="30" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/total-bid-icon.png') }}" alt="">
                                            <span class="title-semidark fs-14">Total Bid</span>
                                        </div>
                                        <h4 class="m-0 fs-16 fw-semibold">100</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card border-0 shadow-sm p-xl-4 p-3">
                            <ul class="nav nav-pills mb-20 d-flex align-items-center flex-wrap justify-content-center gap-1" id="pills-tab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="description_cus-tab" data-bs-toggle="pill" data-bs-target="#bids_descriptions" type="button" role="tab" aria-controls="bids_descriptions" aria-selected="true">
                                        Bidding History
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="bids_cus-tab" data-bs-toggle="pill" data-bs-target="#bids_live_bidding" type="button" role="tab" aria-controls="bids_live_bidding" aria-selected="false">
                                        Product Description
                                    </button>
                                </li>
                            </ul>
                            <div class="tab-content" id="pills-tabContent">
                                <div class="tab-pane fade show active" id="bids_descriptions" role="tabpanel" aria-labelledby="description_cus-tab" tabindex="0">
                                    <div class="live-bidding-here">
                                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-20">
                                            <h4 class="fs-18 fw-semibold mb-0">Bidding History</h4>
                                            <div class="form-check">
                                                <input class="form-check-input form-check-input_theme" type="checkbox" value="" id="myBidonly0">
                                                <label class="form-check-label fs-13 text-secondary" for="myBidonly0">
                                                    My Bids Only
                                                </label>
                                            </div>
                                        </div>
                                        <div class="bidding-owner-scroll pe-1">
                                            <div class="bidding-owner d-flex flex-column gap-10px">
                                                <div class="leading-big-active d-flex align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    Just now
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                                                        </h6>
                                                        <div class="mt-2 d-flex align-items-center gap-1 text-nowrap fs-12 fw-bold text-success bg-white rounded-pill py-1 px-10">
                                                            <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/leading-badge.svg') }}" alt="">
                                                            Leading Bid
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="border-bottom"></div>
                                                <div class="bid-winner-active d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    Just now
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                                                        </h6>
                                                        <div class="mt-2 d-flex align-items-center gap-1 text-nowrap fs-12 fw-bold text-warning bg-white rounded-pill py-1 px-10">
                                                            <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/winning-badge.svg') }}" alt="">
                                                            Winner
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="border-bottom"></div>
                                                <div class="d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    1 minute ago
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                                                        </h6>
                                                    </div>
                                                </div>
                                                <div class="border-bottom"></div>
                                                <div class="d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    1 minute ago
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                                                        </h6>
                                                    </div>
                                                </div>
                                                <div class="border-bottom"></div>
                                                <div class="outbid-active d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    1 minute ago
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                                                        </h6>
                                                        <div class="mt-2 d-flex align-items-center justify-content-center gap-1 text-nowrap fs-12 fw-bold text-danger bg-danger bg-opacity-10 rounded-pill py-1 px-10">
                                                            Outbid
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="border-bottom"></div>
                                                <div class="d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    1 minute ago
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-down text-danger fs-16"></i>
                                                        </h6>
                                                    </div>
                                                </div>
                                                <div class="border-bottom"></div>
                                                <div class="d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    1 minute ago
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                                                        </h6>
                                                    </div>
                                                </div>
                                                <div class="border-bottom"></div>
                                                <div class="d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    1 minute ago
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                                                        </h6>
                                                    </div>
                                                </div>
                                                <div class="border-bottom"></div>
                                                <div class="d-flex w-100 align-items-center justify-content-between gap-4 p-10px">
                                                    <div class="d-flex align-items-center gap-20px max-w-230px">
                                                        <div class="serial w-40px h-40px min-w-40px d-center">
                                                            1.
                                                        </div>
                                                        <div class="d-flex align-items-center gap-10px">
                                                            <a href="#" class="w-50px h-50px rounded-circle d-block border">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card3.png') }}" alt="" class="w-100 h-100 object-cover rounded-circle">
                                                            </a>
                                                            <div class="">
                                                                <h6 class="mb-1">
                                                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1 min-w-150px">
                                                                        Alex Morgan
                                                                    </a>
                                                                </h6>
                                                                <p class="fs-12 title-semidark m-0">
                                                                    1 minute ago
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <h6 class="d-flex text-title fw-semibold gap-5px align-items-center fs-14">
                                                            $38.00 <i class="fi fi-sr-arrow-small-up text-success fs-16"></i>
                                                        </h6>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="auction-insights pt-3">
                                            <div class="accordion" id="accordionExample">
                                                <div class="accordion-item p-3 rounded light-box border-0">
                                                    <h2 class="accordion-header mb-12px p-0 bg-transparent outline-0 border-0">
                                                        <button class="accordion-button shadow-none p-0 bg-transparent outline-0 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#auctiionInsightCollapse" aria-expanded="true" aria-controls="auctiionInsightCollapse">
                                                            <div class="d-flex align-items-center gap-1 fs-16 fw-bold title-clr">
                                                                <img width="18" height="18" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/auction-insights.svg') }}" alt="">
                                                                Auction Insights
                                                            </div>
                                                        </button>
                                                    </h2>
                                                    <div id="auctiionInsightCollapse" class="accordion-collapse collapse show" data-bs-parent="#accordionExample">
                                                        <div class="accordion-body p-0">
                                                            <div class="d-flex flex-wrap gap-10px">
                                                                <div class="lh-sm d-flex align-items-center gap-1">
                                                                    <span class="fs-16 title-semidark text-capitalize">Auction Expired at <strong class="title-clr">12 Feb 2026, 10:00 PM</strong></span>
                                                                </div>
                                                                <div class="lh-sm d-flex align-items-center gap-1">
                                                                    <span class="fs-16 title-semidark text-capitalize">Claim Product Closed at <strong class="title-clr">13 Feb 2026, 10:00 PM</strong></span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane fade" id="bids_live_bidding" role="tabpanel" aria-labelledby="bids_cus-tab" tabindex="0">
                                    <div class="details-content-wrap show-more--content d-flex flex-column gap-15px">
                                        <div class="">
                                            <h4 class="fs-16 fw-semibold mb-12px">Detail Description</h4>
                                            <p class="fs-14 pragraph-clr m-0">
                                                Sport Lifestyle Shoe by  Bangladesh are designed to withstand the pressure of outdoor activities including Joggi ng and Light Running. The build quality of these shoes for men not only ensures the durability of the product, but also transmits vibe of confidence to the wearer. Best alternative of mens casual shoe Black Sneakers Blue Sneakers
                                            </p>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-lg-4 col-md-4 col-6">
                                                <div class="description-thumb position-relative h-100px w-100 bs-border rounded overflow-hidden">
                                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/description-thumb1.png') }}" alt="img" class="w-100 h-100 object-cover">
                                                </div>
                                            </div>
                                            <div class="col-lg-4 col-md-4 col-6">
                                                <div class="description-thumb d-center with-video position-relative h-100px w-100 bs-border rounded overflow-hidden">
                                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/description-thumb1.png') }}" alt="img" class="w-100 h-100 object-cover">
                                                    <a href="#0" class="video-icon position-absolute z-10 d-center w-40px h-40px min-w-40px rounded-circle">
                                                        <i class="fi fi-sr-play fs-14"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                        <p class="fs-14 pragraph-clr m-0">
                                            Sport Lifestyle Shoe by  Bangladesh are designed to with stand the pressure of outdoor activities including Joggi ng and Light Running. The build quality of these shoes for men not only ensures the durability of the product, but also transmits vibe of confidence to the wearer. Best alternative of mensers
                                        </p>
                                        <p class="fs-14 pragraph-clr m-0">
                                            Sport Lifestyle Shoe by  Bangladesh are designed to with stand the pressure of outdoor activities including Joggi ng and Light Running. The build quality of these shoes for men not only ensures the durability of the product, but also transmits vibe of confidence to the wearer. Best alternative of mensers
                                        </p>
                                        <p class="fs-14 pragraph-clr m-0">
                                            Sport Lifestyle Shoe by  Bangladesh are designed to with stand the pressure of outdoor activities including Joggi ng and Light Running. The build quality of these shoes for men not only ensures the durability of the product, but also transmits vibe of confidence to the wearer. Best alternative of mensers
                                        </p>
                                        <p class="fs-14 pragraph-clr m-0">
                                            Sport Lifestyle Shoe by  Bangladesh are designed to with stand the pressure of outdoor activities including Joggi ng and Light Running. The build quality of these shoes for men not only ensures the durability of the product, but also transmits vibe of confidence to the wearer. Best alternative of mensers
                                        </p>
                                        <p class="fs-14 pragraph-clr m-0">
                                            Sport Lifestyle Shoe by  Bangladesh are designed to with stand the pressure of outdoor activities including Joggi ng and Light Running. The build quality of these shoes for men not only ensures the durability of the product, but also transmits vibe of confidence to the wearer. Best alternative of mensers
                                        </p>
                                        <p class="fs-14 pragraph-clr m-0">
                                            Sport Lifestyle Shoe by  Bangladesh are designed to with stand the pressure of outdoor activities including Joggi ng and Light Running. The build quality of these shoes for men not only ensures the durability of the product, but also transmits vibe of confidence to the wearer. Best alternative of mensers
                                        </p>
                                        <p class="fs-14 pragraph-clr m-0">
                                            Sport Lifestyle Shoe by  Bangladesh are designed to with stand the pressure of outdoor activities including Joggi ng and Light Running. The build quality of these shoes for men not only ensures the durability of the product, but also transmits vibe of confidence to the wearer. Best alternative of mensers
                                        </p>
                                    </div>
                                    <div class="text-center mt-3">
                                        <button type="button" class="see-more-details btn px-3 py-2 btn-outline-primary">See More</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bids-product-details-right">
                        <div class="d-flex flex-column gap-15px">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex gap-3 align-items-center justify-content-between">
                                        <h6 class="mb-0 fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                            {{ translate('Track Order') }}
                                        </h6>
                                        <button type="button" class="btn btn-outline-primary py-2 fs-12 px-3">
                                            Track
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="p-15px card border-0 shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr mb-3">Payment Info</div>
                                <div class="d-flex flex-column gap-15px">
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Payment Status') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 text-success fw-semibold">Partially Paid</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Payment Method') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold">Wallet</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Paid Amount') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold"> $ 456.00</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Due Amount') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold">$ 50.00</h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="p-15px card border-0 shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr mb-3">Billing info</div>
                                <div class="d-flex flex-column gap-15px">
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Product Price ') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold">$500.00</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Shipping Fee') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold">$ 10.00</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Tax') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold">$ 50.00</h4>
                                        </div>
                                    </div>
                                    <div class="border-bottom"></div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Total') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold">$ 510.00</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Paid by Wallet') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold"> $ 456.00</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xl-130px fs-14 title-semidark">
                                                {{ translate('Due (COD)') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-semibold">$ 50.00</h4>
                                        </div>
                                    </div>
                                    <div class="fs-13 py-2 px-2 badge text-wrap text-start bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-1">
                                        <i class="fi fi-sr-info text-warning"></i>
                                       Return Policy : Return accepted for 7 days
                                    </div>
                                </div>
                            </div>
                            <div class="p-15px card border-0 shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr mb-3">Shipping address</div>
                                <div class="d-flex flex-column gap-15px">
                                    <div class="d-flex align-items-center gap-2 w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-60px fs-14 title-semidark">
                                                {{ translate('Name') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-normal">Robert Smith</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-60px fs-14 title-semidark">
                                                {{ translate('Phone') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-normal"> 01885555555</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-60px fs-14 title-semidark">
                                                {{ translate('City / Zip') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-normal">849928</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-start gap-2 w-100">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-60px fs-14 title-semidark">
                                                {{ translate('Address') }}
                                            </div>
                                            <span class="title-semidark">:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-14 m-0 title-clr fw-normal">Shah Ali Plaza, Mirpur 10 Rounda, Dhaka 1216, Bangladesh</h4>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="p-15px card border-0 shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr mb-3">Billing address</div>
                                <div class="bg-light rounded text-center fs-14 title-semidark p-12px">
                                    Same as shipping address
                                </div>
                            </div>
                            <div class="fs-13 py-2 px-2 badge text-wrap text-start bg-warning title-semidark fw-normal bg-opacity-10 d-flex align-items-center gap-5px">
                                <i class="fi fi-sr-info text-warning"></i>
                                Author will be responsible for this Auction product delivery
                            </div>
                            <div class="card border-0 shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex gap-3 align-items-center justify-content-between">
                                        <div class="d-flex gap-10px align-items-center">
                                            <div class="rounded w-50px h-50px min-w-50px overflow-hidden text-center rounded-circle">
                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/click-collect.png') }}" alt="" class="w-100 h-100 object-cover">
                                            </div>
                                            <div class="">
                                                <h6 class="mb-1 fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                    {{ translate('Click & Collection') }}
                                                </h6>
                                                <p class="m-0 fs-13 title-clr">{{ translate('Auction Author') }}</p>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-primary py-2 fs-12 d-flex align-items-center gap-2 px-3">
                                            <i class="fi fi-rr-comment fs-14"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="p-xxl-20px p-3 card border-0 shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr mb-3">Auction Timeline</div>
                                <div>
                                    <span class="fs-14 fw-normal title-semidark d-block mb-2">Auction Timeline</span>
                                    <div class="d-flex justify-content-start">
                                        <h4 class="fs-14 m-0 title-clr fw-semibold ltr">12 Aug, 2022, 12:45</h4>
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


<div class="offcanvas offcanvas-start" tabindex="-1" id="profile_aside_btn" aria-labelledby="profile_aside_btn">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">{{ translate('Profile Information') }}</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <h5 class="fs-16 fw-semibold title-clr py-12px px-10px border-bottom mb-20">{{ translate('Filter_By') }}</h5>
    @include("auction.web-views.user-profile._profile-sidebar")
  </div>
</div>
@endsection


