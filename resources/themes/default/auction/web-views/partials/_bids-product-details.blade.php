
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
                <div class="bid-product-details__wrapper d-flex gap-3">
                    <div class="bid-product-details-body">
                        <div class="card border-0 shadow-sm p-xxl-20px p-3 mb-20">
                            <div class="row g-3">
                                <div class="col-sm-4">
                                    <a href="#0" class="bids-details-thumb d-center position-relative z-1 rounded overflow-hidden">
                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/thumb.png') }}" alt="" class="w-100 h-100">
                                        <span class="view-icon w-45px z-1 h-45px min-w-45px position-absolute d-center rounded-circle bg-white text-primary">
                                            <i class="fi fi-rr-eye"></i>
                                        </span>
                                        <div class="overlay-20 position-absolute top-0 inset-inline-start-0"></div>
                                    </a>
                                </div>
                                <div class="col-sm-8">
                                    <div class="">
                                        <div class="fs-12 title-semidark fw-medium mb-1">Auction ID#1000034</div>
                                        <h2 class="mb-12px fs-16 line--limit-2 title-clr fw-semibold">
                                            {{ translate('LG C2 42 (106cm) 4K Smart OLED evo TV | WebOS | Cinema HDR') }}
                                        </h2>
                                        <div class="d-flex flex-column gap-2 auction-details-right-content">
                                            <div class="d-flex align-items-center gap-10px">
                                                <div class="minmax-xs-100px fs-14 title-semidark">
                                                    {{ translate('Shipping Fee') }}
                                                </div>
                                                <span>:</span>
                                                <div class="info-option2">
                                                    <h4 class="fs-14 m-0 title-clr fw-semibold">$10.00</h4>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-start gap-10px">
                                                <div class="minmax-xs-100px fs-14 title-semidark">
                                                    {{ translate('Return Policy') }}
                                                </div>
                                                <span>:</span>
                                                <div class="info-option2">
                                                    <h4 class="fs-14 m-0 title-clr text-break fw-semibold line--limit-2" data-bs-toggle="tooltip" data-bs-title="Return accepted for 7 days">Return accepted for 7 days</h4>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card border-0 shadow-sm p-xl-4 p-3">
                            <ul class="nav nav-pills mb-20 d-flex align-items-center justify-content-center gap-1" id="pills-tab" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="description_cus-tab" data-bs-toggle="pill" data-bs-target="#bids_descriptions" type="button" role="tab" aria-controls="bids_descriptions" aria-selected="true">
                                        Live Biding
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
                                            <h4 class="fs-18 fw-semibold mb-0">Live Bidding Activity</h4>
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
                                                                    <span class="fs-16 title-semidark text-capitalize">Order Placement Closed at <strong class="title-clr">13 Feb 2026, 10:00 PM</strong></span>
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
                            <div class="countdown-card countdown-card2 p-15px card border-0 shadow-sm rounded">
                                <div class="text-center">
                                    <div class="fs-16 title-clr mb-10px">Auction Ends In</div>
                                    <span class="cz-countdown flash-deal-countdown bg-light rounded p-12px d-flex justify-content-center align-items-center gap-10px" data-countdown="2026-09-05T23:59:00">
                                        <span class="cz-countdown-days d-flex align-items-end">
                                            <span class="cz-countdown-value m-value d-center text-danger fw-bold fs-18 rounded"></span>
                                            <span class="cz-countdown-text text-nowrap fs-16 fw-bold text-danger text-lowercase">{{ translate('d')}}</span>
                                        </span>
                                        <span class="cz-countdown-value fs-18 text-danger fw-bold">:</span>
                                        <span class="cz-countdown-hours d-flex align-items-end">
                                            <span class="cz-countdown-value m-value d-center text-danger fw-bold fs-18 20 rounded"></span>
                                            <span class="cz-countdown-text text-nowrap fs-16 fw-bold text-danger text-lowercase">{{ translate('h')}}</span>
                                        </span>
                                        <span class="cz-countdown-value fs-18 text-danger fw-bold">:</span>
                                        <span class="cz-countdown-minutes d-flex align-items-end">
                                            <span class="cz-countdown-value m-value d-center text-danger fw-bold fs-18 rounded"></span>
                                            <span class="cz-countdown-text text-nowrap fs-16 fw-bold text-danger text-lowercase">{{ translate('m')}}</span>
                                        </span>
                                        <span class="cz-countdown-value fs-18 text-danger fw-bold">:</span>
                                        <span class="cz-countdown-seconds d-flex align-items-end">
                                            <span class="cz-countdown-value m-value d-center text-danger fw-bold fs-18 rounded"></span>
                                            <span class="cz-countdown-text text-nowrap fs-16 fw-bold text-danger text-lowercase">{{ translate('s')}}</span>
                                        </span>
                                    </span>
                                </div>
                            </div>
                            <div class="countdown-card p-15px card border-0 shadow-sm rounded">
                                <div class="text-center">
                                    <div class="mb-20">
                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/congrt-bid.png') }}" alt="" class="w-70 h-70 rounded-circle mb-20 mx-auto border">
                                        <h4 class="fs-20 fw-semibold text-success title-clr mb-10px">Great News! 🎉</h4>
                                        <p class="fs-14 title-semidark mb-0 text-center">
                                            {{ translate('The previous winner missed the payment deadline. You are now the winning bidder! Complete your payment to claim your product.') }}
                                        </p>
                                    </div>
                                    <div class="mb-20">
                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/congrt-bid.png') }}" alt="" class="w-70 h-70 rounded-circle mb-20 mx-auto border">
                                        <h4 class="fs-20 fw-semibold text-success title-clr mb-10px">Congratulations! 🎉</h4>
                                        <p class="fs-14 title-semidark mb-0 text-center">
                                            You’re the winning bidder! Complete your payment to claim your product.
                                        </p>
                                    </div>
                                    <div class="light-box rounded p-xl-3 p-2">
                                        <span class="cz-countdown mb-10px flash-deal-countdown d-flex justify-content-center align-items-center gap-10px" data-countdown="2026-09-05T23:59:00">
                                            <span class="cz-countdown-days">
                                                <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                                                <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('days')}}</span>
                                            </span>
                                            <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                                            <span class="cz-countdown-hours">
                                                <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                                                <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('hours')}}</span>
                                            </span>
                                            <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                                            <span class="cz-countdown-minutes">
                                                <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                                                <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('minutes')}}</span>
                                            </span>
                                            <span class="cz-countdown-value pb-4 fs-16 text-primary fw-bold">:</span>
                                            <span class="cz-countdown-seconds">
                                                <span class="cz-countdown-value m-value d-center text-white fw-bold fs-20 rounded"></span>
                                                <span class="cz-countdown-text text-nowrap fs-12 fw-bold title-clr">{{ translate('seconds')}}</span>
                                            </span>
                                        </span>
                                        <p class="fs-14 title-semidark mb-0 text-center">
                                            Claim your product before time runs out. Click <strong>‘Claim Product’</strong> to complete payment.
                                        </p>
                                    </div>
                                    <div class="mt-20 max-w-180px w-100 mx-auto">
                                        <button type="button" class="btn btn-primary">
                                            Claim Product
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="p-15px card border-0 shadow-sm rounded">
                                <button type="button" class="btn bg-danger bg-opacity-10 w-100 fs-16 fw-semibold text-danger">
                                    Auction is Expired
                                </button>
                            </div>
                            <div class="p-15px card border-0 shadow-sm rounded">
                                <button type="button" class="btn bg-danger bg-opacity-10 w-100 fs-16 fw-semibold text-danger">
                                    Oops! Time’s Up
                                    <span class="title-semidark fs-14 fw-normal d-block mt-1">You missed the time limit to place your order.</span>
                                </button>
                            </div>
                            <div class="p-15px card border-0 shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr">Auction Info</div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-100px fs-14 title-semidark">
                                                {{ translate('Starting Price') }}
                                            </div>
                                            <span>:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-16 m-0 title-clr fw-semibold">$35.00</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-100px fs-14 title-semidark">
                                                {{ translate('Highest Bid') }}
                                            </div>
                                            <span>:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-16 m-0 title-clr fw-semibold">$40.00</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-100px fs-14 title-semidark">
                                                {{ translate('My Bid') }}
                                            </div>
                                            <span>:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-16 m-0 title-clr fw-semibold">$35.00 </h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-100px fs-14 title-semidark">
                                                {{ translate('Total Viewed') }}
                                            </div>
                                            <span>:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-16 m-0 title-clr fw-semibold">50</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-100px fs-14 title-semidark">
                                                {{ translate('Total Bid') }}
                                            </div>
                                            <span>:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-16 m-0 title-clr fw-semibold">20</h4>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 flex-xl-nowrap flex-wrap d-flex gap-12px">
                                    <button type="button" class="btn px-3 btn-secondary fs-14">
                                        Withdraw Bid
                                    </button>
                                    <button type="button" class="btn px-3 btn-primary fs-14"  data-bs-toggle="tooltip" data-bs-title="Cancel your most recent bid (available only if allowed
                                        by auction rules).">
                                        Raise Bid ($43.00)
                                    </button>
                                </div>
                            </div>
                            <div class="p-15px card border-0 shadow-sm rounded">
                                <div class="fs-14 fw-semibold title-clr">Product Info</div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-100px fs-14 title-semidark">
                                                {{ translate('Category') }}
                                            </div>
                                            <span>:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-16 m-0 title-clr fw-semibold">Gadget</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-100px fs-14 title-semidark">
                                                {{ translate('Product Type') }}
                                            </div>
                                            <span>:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-16 m-0 title-clr fw-semibold">Physical</h4>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 justify-content-between w-100 border-bottom py-12px">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="minmax-xs-100px fs-14 title-semidark">
                                                {{ translate('Item condition') }}
                                            </div>
                                            <span>:</span>
                                        </div>
                                        <div class="info-option2">
                                            <h4 class="fs-16 m-0 title-clr fw-semibold">New Condition</h4>
                                        </div>
                                    </div>
                                </div>
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
