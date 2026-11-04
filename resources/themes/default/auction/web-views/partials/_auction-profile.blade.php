
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
                @include("auction.web-views.partials._auction-sale-reports")

                @include("auction.web-views.partials._auction-lists")

                @include("auction.web-views.partials._auction-pending-product")

                <h5 class="fs-18 fw-semibold title-clr text-capitalize mb-12px">{{ translate('My Bids') }}</h5>
                <div class="">
                    <ul class="nav nav-rounded p-0 mb-3 d-flex flex-wrap align-items-center justify-content-start gap-xxl-20px gap-3" id="pills-tab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fs-14 active" id="participated-tab" data-bs-toggle="pill" data-bs-target="#participated_action" type="button" role="tab" aria-controls="participated_action" aria-selected="true">
                                Participated <span class="text-light-gray">(20)</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="myBid-tab1" data-bs-toggle="pill" data-bs-target="#myBid_auction1" type="button" role="tab" aria-controls="myBid_auction1" aria-selected="false">
                                Live <span class="text-light-gray">(09)</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="myBid-tab2" data-bs-toggle="pill" data-bs-target="#myBid_auction2" type="button" role="tab" aria-controls="myBid_auction2" aria-selected="false">
                                Won <span class="text-light-gray">(09)</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="myBid-tab3" data-bs-toggle="pill" data-bs-target="#myBid_auction3" type="button" role="tab" aria-controls="myBid_auction3" aria-selected="false">
                                Claimed <span class="text-light-gray">(09)</span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="myBid-tab4" data-bs-toggle="pill" data-bs-target="#myBid_auction4" type="button" role="tab" aria-controls="myBid_auction4" aria-selected="false">
                                Lost <span class="text-light-gray">(09)</span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content" id="pills-tabContent">
                        <div class="tab-pane fade show active" id="participated_action" role="tabpanel" aria-labelledby="participated-tab" tabindex="0">
                            <div class="mb-3">
                                <h5 class="fw-semibold fs-16 title-clr mb-1">{{ translate('Participated Auctions') }}</h5>
                                <p class="m-0 fs-14 title-semidark">{{ translate('Here you can view the auctions you have participated for bidding') }}</p>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1">
                                                Upcoming
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Start Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Kindly wait for the auction to go live to start bidding.">
                                                            <button disabled class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-white z-1">
                                                Live
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Start Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-white z-1">
                                                Live
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Start Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1">
                                                Upcoming
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Start Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Kindly wait for the auction to go live to start bidding.">
                                                            <button disabled class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1">
                                                Upcoming
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Start Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Kindly wait for the auction to go live to start bidding.">
                                                            <button disabled class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-white z-1">
                                                Live
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Start Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="myBid_auction1" role="tabpanel" aria-labelledby="myBid-tab1" tabindex="0">
                            <div class="mb-3">
                                <h5 class="fw-semibold fs-16 title-clr mb-1">{{ translate('Live Bids') }}</h5>
                                <p class="m-0 fs-14 title-semidark">{{ translate('Here you can view auction you’re currently bidding on and still active.') }}</p>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-white z-1" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Someone placed a higher bid. Raise your bid to go to the leading position">
                                                Outbid
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Raise Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Someone placed a higher bid. Raise your bid to go to the leading position">
                                                Leading
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Raise Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-white z-1" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Someone placed a higher bid. Raise your bid to go to the leading position">
                                                Outbid
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Raise Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Someone placed a higher bid. Raise your bid to go to the leading position">
                                                Leading
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Raise Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Someone placed a higher bid. Raise your bid to go to the leading position">
                                                Leading
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Raise Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-1 px-10px py-5px fs-13 text-white z-1" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Someone placed a higher bid. Raise your bid to go to the leading position">
                                                Outbid
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                Raise Bid
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Raise Bid <i class="fi fi-sr-auction text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Withdraw Bid
                                                                <img width="16" height="16" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/logout-up.svg') }}" alt="">
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="myBid_auction2" role="tabpanel" aria-labelledby="myBid-tab2" tabindex="0">
                            <div class="mb-3">
                                <h5 class="fw-semibold fs-16 title-clr mb-1">{{ translate('Won Auction') }}</h5>
                                <p class="m-0 fs-14 title-semidark">{{ translate('Here view auction you’ve won and those are waiting for payment or processing.') }}</p>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Starting Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Claim Product
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Starting Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Claim Product
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Starting Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Claim Product
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Starting Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Claim Product
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <span class="text-warning fw-semibold fs-13">Time Out</span>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Starting Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Claim Product
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Starting Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Claim Product
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <div class="flex-aligns gap-1">
                                                            <i class="fi fi-rr-time-oclock fs-13 text-danger"></i>
                                                            <div class="d-flex justify-content-center gap-1 countdown" data-end="2026-09-25 23:59:59">
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time hours fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">h</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time minutes fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">m</div>
                                                                </div>
                                                                <div class="time-box flex-aligns gap-1px text-center fw-semibold">
                                                                    <span class="time seconds fw-semibold text-danger fs-14">00</span>
                                                                    <div class="small text-danger fs-14">s</div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Starting Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Claim Product
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                    <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                                        <span class="text-warning fw-semibold fs-13">Time Out</span>
                                                    </div>
                                                </div>
                                                <div class="w-100">
                                                    <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Starting Price</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Claim Product
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="myBid_auction3" role="tabpanel" aria-labelledby="myBid-tab3" tabindex="0">
                            <div class="mb-3">
                                <h5 class="fw-semibold fs-16 title-clr mb-1">{{ translate('Claimed Items') }}</h5>
                                <p class="m-0 fs-14 title-semidark">{{ translate('Here view auction you’ve won and successfully completed payment for.') }}</p>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Claimed at</span>
                                                        <span class="fs-12 title-semidark">08 jan 2025, 10:20 PM</span>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn bg-primary text-primary bg-opacity-10 rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Track Order
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Claimed at</span>
                                                        <span class="fs-12 title-semidark">08 jan 2025, 10:20 PM</span>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn bg-primary text-primary bg-opacity-10 rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Track Order
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Claimed at</span>
                                                        <span class="fs-12 title-semidark">08 jan 2025, 10:20 PM</span>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn bg-primary text-primary bg-opacity-10 rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Track Order
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Claimed at</span>
                                                        <span class="fs-12 title-semidark">08 jan 2025, 10:20 PM</span>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                        <div class="">
                                                            <button class="btn bg-primary text-primary bg-opacity-10 rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                                               Track Order
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Someone placed a higher bid. Raise your bid to go to the leading position">
                                                Delivered
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Claimed at</span>
                                                        <span class="fs-12 title-semidark">08 jan 2025, 10:20 PM</span>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 w-max-content m-1 px-10px py-5px fs-13 text-white z-1" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Someone placed a higher bid. Raise your bid to go to the leading position">
                                                Delivered
                                            </span>
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">Claimed at</span>
                                                        <span class="fs-12 title-semidark">08 jan 2025, 10:20 PM</span>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Final Bid Price</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                Claim Product <i class="fi fi-sr-basket-shopping-minus text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="myBid_auction4" role="tabpanel" aria-labelledby="myBid-tab4" tabindex="0">
                            <div class="mb-3">
                                <h5 class="fw-semibold fs-16 title-clr mb-1">{{ translate('Lost Auction') }}</h5>
                                <p class="m-0 fs-14 title-semidark">{{ translate('Here view auctions where another bidder placed a higher final bid.') }}</p>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My Bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My Bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My Bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My Bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My Bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="item">
                                        <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                                            <div class="d-flex gap-10px align-items-center">
                                                <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                                    <a href="#" class="m-thumbnail d-block">
                                                        <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                                    </a>
                                                </div>
                                                <div class="w-100">
                                                    <h6 class="mb-10px pe-xl-3 pe-1">
                                                        <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                                            Diamond Quartz Watch Steel Watches for Men
                                                        </a>
                                                    </h6>
                                                    <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                                        <span class="fs-12 title-semidark">Highest Bid</span>
                                                        <strong class="text-primary fs-14 fw-semibold">$44.00</strong>
                                                    </div>
                                                    <div class="d-flex gap-10px justify-content-start mb-10px align-items-center">
                                                        <span class="fs-12 title-semidark">My Bid</span>
                                                        <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                                    </div>
                                                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                                        <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <i class="fi fi-rr-eye text-light-gray"></i>
                                                                50
                                                            </span>
                                                            <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                                                20
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="position-absolute inset-inline-end-0 top-0 m-xxl-3 m-xl-2 m-1">
                                                <div class="btn-group dropstart">
                                                    <button type="button" class="btn p-0 bg-transparent outline-0 border-0 shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fi fi-sr-menu-dots-vertical text-primary"></i>
                                                    </button>
                                                    <ul class="dropdown-menu shadow-sm">
                                                        <li>
                                                            <a class="dropdown-item py-2 d-flex align-items-center justify-content-between gap-1" href="#">
                                                                View Details <i class="fi fi-sr-eye text-primary"></i>
                                                            </a>
                                                        </li>
                                                    </ul>
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
