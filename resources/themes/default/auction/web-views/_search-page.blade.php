@extends("auction.layouts.auction-app")

@section('title', 'Search Page')

@section('content')
<div class="container">

@include("auction.web-views.partials._product-list-header")
<div>
    <div class="row g-3">
        <div class="col-lg-3">
            <div class="search-filer-sidebar rounded bg-white border d-lg-block d-none ">
                <h5 class="fs-16 fw-semibold title-clr py-12px px-10px border-bottom mb-20">{{ translate('Filter_By') }}</h5>
                <div class="p-10px pt-0">
                    <div class="d-flex flex-column gap-20px">
                        @include('auction.web-views.product.partials._filter-ending-time')
                        @include('auction.web-views.product.partials._filter-entry-fee')
                        @include('auction.web-views.product.partials._filter-product-price')
                        @include('auction.web-views.product.partials._filter-auction-istings')
                        @include('auction.web-views.product.partials._filter-product-categories', [
                            'productCategories' => $categories,
                            'dataFrom' => request('data_from'),
                        ])
                        @include('auction.web-views.product.partials._filter-product-brands', [
                            'productBrands' => $activeBrands,
                            'dataFrom' => request('data_from'),
                        ])
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="row g-sm-3 g-2">
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card1.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                    Bid
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card2.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                    Bid
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Upcoming
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card3.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div></div>
                                <button class="btn bg-primary text-primary bg-opacity-10 rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    Participated
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card4.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                    Bid
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card5.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    Participate
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card3.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                    Bid
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card5.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                    Bid
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Upcoming
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card2.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    Participate
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card4.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                    Bid
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card1.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                    Bid
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                 <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-success rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Upcoming
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card3.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div></div>
                                <button class="btn bg-primary text-primary bg-opacity-10 rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    Participated
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-lg-4 col-xl-3">
                    <div class="ending-soon-card card-icon-support bg-white border-0 p-10px shadow-sm rounded position-relative h-100">
                        <span class="badge bg-danger rounded-4px position-absolute top-0 inset-inline-start-0 m-2 px-10px py-5px fs-13 text-white z-2">
                            Live
                        </span>
                        <div class="m-thumbnail-wrap rounded overflow-hidden text-center border mb-10px position-relative">
                            <a href="#" class="m-thumbnail d-block">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/card-imgaes/trending-card4.png') }}" alt="" class="w-100 h-207px object-fit-cover">
                            </a>
                        <div class="icons-grop position-absolute d-flex align-items-center gap-xl-10px">
                                <a href="#0" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <i class="fi fi-rr-eye"></i>
                                </a>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                </button>
                                <button type="button" class="d-flex icon-primary align-items-center justify-content-center bg-white rounded-circle shadow-sm icon">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/card-badge.svg') }}" alt="" class="svg">
                                </button>
                        </div>
                            <div class="z-2 d-flex flex-sm-nowrap flex-wrap  gap-2px justify-content-between w-100 align-items-center light-box py-5px px-2 lh-sm position-absolute bottom-0 inset-inline-start-0">
                                <span class="fs-12 title-clr">Closes In</span>
                                <div class="flex-aligns gap-lg-2 gap-1">
                                    <i class="fi fi-rr-time-oclock fs-lg-14 text-danger"></i>
                                    <div class="d-flex gap-5px countdown" data-end="2026-09-25 23:59:59">
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time hours fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">h</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time minutes fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">m</div>
                                        </div>
                                        <div class="time-box flex-aligns gap-1px text-center fw-bold">
                                            <span class="time seconds fw-bold text-danger fs-14">00</span>
                                            <div class="small text-danger fs-14">s</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="">
                            <h6 class="mb-10px">
                                <a href="#" class="text-decoration-none fs-14 text-capitalize fw-semibold title-clr line--limit-1">
                                    Diamond Quartz Watch Steel Watches for Men
                                </a>
                            </h6>
                            <div class="d-flex justify-content-between mb-12px align-items-center">
                                <span class="fs-12 title-semidark">Highest Bid</span>
                                <strong class="text-primary fs-15px fw-bold">$44.00</strong>
                            </div>
                            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                                <div>
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
                                <button class="btn btn-primary svg-white rounded-pill btn-sm px-2 py-1 fw-bold d-flex align-items-center gap-1 fs-12 lh-sm">
                                    <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bid.svg') }}" alt="" class="svg">
                                    Bid
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
@endsection
