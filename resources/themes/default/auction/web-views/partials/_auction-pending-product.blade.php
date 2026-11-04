<div class="mb-20 d-flex align-items-center gap-1 flex-wrap justify-content-between">
    <h5 class="fs-18 fw-semibold title-clr text-capitalize mb-0">{{ translate('Auction Pending Products') }}</h5>
    <button type="button" class="btn btn-primary d-flex align-items-center justify-content-center gap-2 py-2 px-3">
        <i class="fi fi-sr-add"></i> Create Auction
    </button>
</div>
<ul class="nav nav-rounded p-0 mb-3 d-flex flex-wrap align-items-center justify-content-start gap-xxl-20px gap-3" id="pills-tab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link fs-14 active" id="pending_product-tab" data-bs-toggle="pill" data-bs-target="#pending_product_action" type="button" role="tab" aria-controls="pending_product_action" aria-selected="true">
            Pending (11) <span class="text-light-gray">(20)</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="rejected-tab1" data-bs-toggle="pill" data-bs-target="#rejected_auction1" type="button" role="tab" aria-controls="rejected_auction1" aria-selected="false">
            Rejected <span class="text-light-gray">(09)</span>
        </button>
    </li>
</ul>
<div class="tab-content" id="pills-tabContent">
    <div class="tab-pane fade show active" id="pending_product_action" role="tabpanel" aria-labelledby="pending_product-tab" tabindex="0">
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
                                <div class="fs-12 title-semidark mb-1">Auction ID#1000034</div>
                                <h6 class="mb-10px pe-xl-3 pe-1">
                                    <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                        Diamond Quartz Watch Steel Watches for Men
                                    </a>
                                </h6>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Start Price</span>
                                    <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                    <span class="fs-12 title-semidark">Category</span>
                                    <strong class="title-clr fs-14 fw-semibold">Bag</strong>
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
                                    <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                    <span class="fs-12 title-semidark">Category</span>
                                    <strong class="title-clr fs-14 fw-semibold">Bag</strong>
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
                                    <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                    <span class="fs-12 title-semidark">Category</span>
                                    <strong class="title-clr fs-14 fw-semibold">Bag</strong>
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
                                    <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                    <span class="fs-12 title-semidark">Category</span>
                                    <strong class="title-clr fs-14 fw-semibold">Bag</strong>
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
                                    <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                    <span class="fs-12 title-semidark">Category</span>
                                    <strong class="title-clr fs-14 fw-semibold">Bag</strong>
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
                                    <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                    <span class="fs-12 title-semidark">Category</span>
                                    <strong class="title-clr fs-14 fw-semibold">Bag</strong>
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
                                    <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                    <span class="fs-12 title-semidark">Category</span>
                                    <strong class="title-clr fs-14 fw-semibold">Bag</strong>
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
                                    <strong class="title-clr fs-14 fw-semibold">$34.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-2 align-items-center">
                                    <span class="fs-12 title-semidark">Min Increment</span>
                                    <strong class="title-clr fs-14 fw-semibold">$5.00</strong>
                                </div>
                                <div class="d-flex gap-10px justify-content-start mb-0 align-items-center">
                                    <span class="fs-12 title-semidark">Category</span>
                                    <strong class="title-clr fs-14 fw-semibold">Bag</strong>
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
    <div class="tab-pane fade" id="rejected_auction1" role="tabpanel" aria-labelledby="rejected-tab1" tabindex="0">
        No Data
    </div>
</div>
