<div class="mb-20 d-flex align-items-center gap-1 flex-wrap justify-content-between">
    <h5 class="fs-18 fw-semibold title-clr text-capitalize mb-0">{{ translate('Auction List') }}</h5>
    <button type="button" class="btn btn-primary d-flex align-items-center justify-content-center gap-2 py-2 px-3">
        <i class="fi fi-sr-add"></i> Create Auction
    </button>
</div>
<ul class="nav p-0 nav-rounded mb-3 d-flex flex-wrap align-items-center justify-content-start gap-xxl-20px gap-3" id="pills-tab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link fs-14 active" id="auction_list-tab" data-bs-toggle="pill" data-bs-target="#auction_list_action" type="button" role="tab" aria-controls="auction_list_action" aria-selected="true">
            Upcoming <span class="text-light-gray">(20)</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="auction_list-tab1" data-bs-toggle="pill" data-bs-target="#auction_list_auction1" type="button" role="tab" aria-controls="auction_list_auction1" aria-selected="false">
            Live <span class="text-light-gray">(09)</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="auction_list-tab2" data-bs-toggle="pill" data-bs-target="#auction_list_auction2" type="button" role="tab" aria-controls="auction_list_auction2" aria-selected="false">
            Delivered <span class="text-light-gray">(09)</span>
        </button>
    </li>
</ul>
<div class="tab-content" id="pills-tabContent">
    <div class="tab-pane fade show active" id="auction_list_action" role="tabpanel" aria-labelledby="auction_list-tab" tabindex="0">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="item">
                    <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                        <div class="d-flex gap-10px align-items-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                    <img src="{{ dynamicAsset(path: 'public/assets/ont-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
    <div class="tab-pane fade" id="auction_list_auction1" role="tabpanel" aria-labelledby="auction_list-tab1" tabindex="0">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="item">
                    <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                        <div class="d-flex gap-10px align-items-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/nt-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Current Bid</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Current Bid</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Current Bid</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Current Bid</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Current Bid</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Current Bid</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Current Bid</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Current Bid</span>
                                    <strong class="text-primary fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                    </div>
                                    <div class="">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                            <span class="title-semidark fs-12">Participants</span> 20
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
    <div class="tab-pane fade" id="auction_list_auction2" role="tabpanel" aria-labelledby="auction_list-tab2" tabindex="0">
        <div class="row justify-content-center">
            <div class="col-lg-11">
                <div class="mb-20 card bs-border shadow-sm p-xxl-20px p-3">
                    <div class="d-flex align-items-center w-100 gap-2 flex-wrap justify-content-between">
                        <div class="d-flex align-items-center gap-xxl-20px gap-2">
                            <img width="40" height="40" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/balance-icon.png') }}" alt="">
                            <div>
                                <h3 class="fs-24 fw-semibold text-danger text-capitalize mb-1">{{ translate('1,248') }}</h3>
                                <h5 class="fs-14 fw-medium title-semidark d-flex align-items-center gap-1 text-capitalize mb-0">
                                    {{ translate('Balance Unadjusted') }}
                                    <span data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="This balance includes unsettled payable and receivable amounts.">
                                        <i class="fi fi-sr-info text-light-gray fs-12"></i>
                                    </span>
                                </h5>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary fs-15 py-2">
                            Adjust Now
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="item">
                    <div class="ending-soon-card bids-items-card bg-white border-0 p-xxl-3 p-2 rounded position-relative">
                        <div class="d-flex gap-10px align-items-xl-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                    <span class="fs-12 title-semidark">Final Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$450.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                        <div class="">
                                            <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                <span class="title-semidark fs-12">Fee provide</span> 20
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                            Amount to Pay Admin
                                        </span>
                                    </div>
                                    <div class="">
                                        <button type="button" class="btn btn-primary py-1 px-2 fs-12 rounded-pill">
                                            Pay Now
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                        <div class="d-flex gap-10px align-items-xl-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                    <span class="fs-12 title-semidark">Final Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$450.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                        <div class="">
                                            <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                <span class="title-semidark fs-12">Fee provide</span> 20
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                            Amount to Pay Admin
                                        </span>
                                    </div>
                                    <div class="">
                                        <button type="button" class="btn btn-primary py-1 px-2 fs-12 rounded-pill">
                                            Pay Now
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                        <div class="d-flex gap-10px align-items-xl-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                    <span class="fs-12 title-semidark">Final Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$450.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                        <div class="">
                                            <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                <span class="title-semidark fs-12">Fee provide</span> 20
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                            Amount to Pay Admin
                                        </span>
                                    </div>
                                    <div class="">
                                        <button type="button" class="btn btn-primary py-1 px-2 fs-12 rounded-pill">
                                            Withdraw Money
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                        <div class="d-flex gap-10px align-items-xl-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                    <span class="fs-12 title-semidark">Final Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$450.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                        <div class="">
                                            <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                <span class="title-semidark fs-12">Fee provide</span> 20
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                            Amount to Pay Admin
                                        </span>
                                    </div>
                                    <div class="">
                                        <button type="button" class="btn btn-primary py-1 px-2 fs-12 rounded-pill">
                                            Pay Now
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                        <div class="d-flex gap-10px align-items-xl-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                    <span class="fs-12 title-semidark">Final Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$450.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                        <div class="">
                                            <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                <span class="title-semidark fs-12">Fee provide</span> 20
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                            Amount to Pay Admin
                                        </span>
                                    </div>
                                    <div class="">
                                        <button type="button" class="btn btn-primary py-1 px-2 fs-12 rounded-pill">
                                            Withdraw Money
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                        <div class="d-flex gap-10px align-items-xl-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                    <span class="fs-12 title-semidark">Final Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$450.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                        <div class="">
                                            <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                <span class="title-semidark fs-12">Fee provide</span> 20
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                            Amount to Pay Admin
                                        </span>
                                    </div>
                                    <div class="">
                                        <button type="button" class="btn btn-primary py-1 px-2 fs-12 rounded-pill">
                                            Pay Now
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                        <div class="d-flex gap-10px align-items-xl-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                    <span class="fs-12 title-semidark">Final Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$450.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                        <div class="">
                                            <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                <span class="title-semidark fs-12">Fee provide</span> 20
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                            Amount to Pay Admin
                                        </span>
                                    </div>
                                    <div class="">
                                        <button type="button" class="btn btn-primary py-1 px-2 fs-12 rounded-pill">
                                            Withdraw Money
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
                        <div class="d-flex gap-10px align-items-xl-center">
                            <div class="rounded items-thumb overflow-hidden text-center bs-border position-relative">
                                <a href="#" class="m-thumbnail d-block">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/ending-soon-card1.png') }}" alt="" class="w-100 h-100">
                                </a>
                                <div class="d-flex justify-content-center w-100 align-items-center light-box py-5px px-1 lh-sm position-absolute bottom-0 start-0">
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
                                <div class="d-flex gap-10px justify-content-start mb-1 align-items-center">
                                    <span class="fs-12 title-semidark">Final Price</span>
                                    <strong class="text-primary fs-14 fw-semibold">$450.00</strong>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-1">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-clr fs-12">
                                            <i class="fi fi-rr-eye text-light-gray"></i>
                                            50
                                        </span>
                                        <div class="">
                                            <span class="d-flex align-items-center gap-1 title-clr fs-14">
                                                <span class="title-semidark fs-12">Fee provide</span> 20
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                    <div class="d-flex gap-xxl-20px gap-2 small text-muted">
                                        <span class="d-flex align-items-center gap-1 title-semidark fs-12">
                                            Amount to Pay Admin
                                        </span>
                                    </div>
                                    <div class="">
                                        <button type="button" class="btn btn-primary py-1 px-2 fs-12 rounded-pill">
                                            Pay Now
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
                                            Edit Details <i class="fi fi-sr-pen-circle text-primary"></i>
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
