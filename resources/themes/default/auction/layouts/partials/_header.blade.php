<header class="header mb-3">
    <div class="header-top py-1 bg-light">
        <div class="container">
            <div class="d-flex align-items-center gap-1 justify-content-between">
                <a href="tel: {{ $web_config['phone'] }}" class="d-flex gap-2 fs-14 align-items-center direction-ltr">
                    <i class="fi fi-rr-phone-call text-primary"></i>
                    {{ $web_config['phone'] }}
                </a>
                <ul class="nav align-items-center gap-lg-4 gap-sm-3 gap-2">
                    @php($currency_model = getWebConfig(name: 'currency_model'))
                    @if($currency_model == 'multi_currency')
                    <li>
                        <div class="language-dropdown">
                            <button class="border-0 fs-14 fw-normal bg-transparent dropdown-toggle" data-bs-toggle="dropdown">
                                {{ session('currency_code') }} {{ session('currency_symbol') }}
                            </button>
                            <ul class="dropdown-menu">
                                @foreach (\App\Models\Currency::where('status', 1)->get() as $key => $currency)
                                    <li class="py-2 cursor--pointer get-currency-change-function border-0"
                                        data-code="{{$currency['code']}}">
                                        {{ $currency->name }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </li>
                    @endif

                    <li>
                        <div class="language-dropdown text-capitalize">
                            <button class="border-0 fs-14 d-flex align-items-center gap-1 fw-normal bg-transparent dropdown-toggle text-capitalize" data-bs-toggle="dropdown">
                                @foreach($web_config['language'] as $data)
                                    @if($data['code'] == getDefaultLanguage())
                                            <?php
                                            $langFlagCode = $data['code'];
                                            if (\Illuminate\Support\Str::contains($data['code'], '-')) {
                                                $countryCodeArr = explode('-', $data['code']);
                                                $langFlagCode = $countryCodeArr[0];
                                            }
                                            ?>
                                        <img width="18"
                                             src="{{ theme_asset(path: 'public/assets/front-end/img/flags/'.strtolower($langFlagCode).'.png') }}"
                                             alt="{{ $data['name'] }}">
                                        {{ $data['name'] }}
                                    @endif
                                @endforeach
                            </button>
                            <ul class="dropdown-menu">
                                @foreach($web_config['language'] as $key =>$data)
                                    @if($data['status'] == 1)
                                        <?php
                                            $langFlagCode = $data['code'];
                                            if (\Illuminate\Support\Str::contains($data['code'], '-')) {
                                                $countryCodeArr = explode('-', $data['code']);
                                                $langFlagCode = $countryCodeArr[0];
                                            }
                                        ?>

                                        <li class="change-language border-0" data-action="{{ route('change-language') }}" data-language-code="{{ $data['code'] }}">
                                            <a class="d-flex gap-2 align-items-center" href="javascript:">
                                                <img width="18" src="{{ theme_asset(path: 'public/assets/front-end/img/flags/'.strtolower($langFlagCode).'.png') }}"
                                                     alt="{{ $data['name'] }}"/>
                                                {{ $data['name'] }}
                                            </a>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="mobile-head navbar-sticky">
        <div class="header-middle border-bottom bg-white navbar-light">
            <div class="container">
                <div class="d-flex align-items-center justify-content-start justify-content-xl-between gap-2">
                    <button class="navbar-toggler menu-btn" type="button">
                        <span class="fi fi-rr-menu-burger fs-24 d-flex"></span>
                    </button>
                    <div class="logo-area d-flex align-items-center gap-2">
                        <a class="logo system-logo-element d-block flex-shrink-0" href="{{ Request::is('auction*') ? route('auction.index') : route('home') }}">
                            <img src="{{ getStorageImages(path: $web_config['web_logo'], type: 'logo') }}"
                                 alt="{{ $web_config['company_name'] }}" class="w-100 object-contain">
                        </a>

                        @if(getWebConfig(name: 'auction_feature_status') && Request::is('auction*'))
                            <span class="badge text-white bg-warning-dark rounded-pill fs-10 fw-semibold mx-1 ">
                                {{ translate('Auction') }}
                            </span>
                        @endif
                    </div>

                    <div class="search-box d-xl-block d-none px-3 flex-grow-1">
                        <form action="{{ route('auction.products') }}" method="GET" class="auction-search-form position-relative">
                            <div class="d-flex bg-white rounded align-items-center input-group-focus-primary">
                                <input type="search" name="search"
                                       class="form-control outline-0 border auction-search-input"
                                       autocomplete="off"
                                       data-given-value=""
                                       placeholder="{{ translate('Search_by_auction_name') }}"
                                       value="{{ request('search') }}">
                                <button type="submit" class="btn btn-primary border-0 h-45px px-3 {{ Session::get('direction') === "rtl" ? 'rounded-end-0' : 'rounded-start-0' }} ">
                                    <i class="fi fi-rr-search"></i>
                                </button>
                            </div>
                            <div class="card auction-search-card border-0" style="display:none; position:absolute; width:100%; z-index:999; top:100%;">
                                <div class="card-body p-0">
                                    <div class="auction-search-result-box px-3 overflow-x-hidden overflow-y-auto" style="max-height:400px;"></div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="input-group-overlay search-form-mobile text-align-direction">
                        <form action="" type="submit" class="search_form">
                            <div class="d-flex align-items-center gap-2">
                                <input class="form-control appended-form-control search-bar-input" type="search"
                                    autocomplete="off" data-given-value=""
                                    placeholder="{{ translate('Search_by_auction_name') }}"
                                    name="name" value="{{ request('name') }}">

                                <span class="close-search-form-mobile fs-14 font-semibold text-muted text-nowrap" type="submit">
                                    {{ translate('cancel') }}
                                </span>
                            </div>
                        </form>
                    </div>

                    <div class="header-middle-btn d-flex align-items-center gap-2 gap-md-3 {{ Session::get('direction') === "rtl" ? 'me-auto' : 'ms-auto' }}">
                        <div class="d-xl-none d-block">
                            <button class="btn p-0 position-relative outline-0 border-0 rounded-circle d-center fs-15 text-primary btn-cmn open-search-form-mobile" type="button">
                                <i class="fi fi-rr-search"></i>
                            </button>
                        </div>
                        <a class="navbar-tool flex-shrink-0 navbar-stuck-toggler" href="javascript:">
                            <div class="navbar-tool-icon-box">
                                <i class="navbar-tool-icon fi fi-rr-menu-burger mt-1 open-icon"></i>
                                <i class="navbar-tool-icon fi fi-rr-cross mt-1 close-icon"></i>
                            </div>
                        </a>
                        @if(auth('customer')->check())
                            <div class="d-md-block d-none">
                                <a href="{{ route('auction.saved-products-list') }}" class="btn p-0 position-relative outline-0 border-0 rounded-circle d-center fs-15 text-primary btn-cmn">
                                    <i class="fi fi-rr-bookmark fs-14"></i>
                                    @if(($savedAuctionCount ?? 0) > 0)
                                        <span class="status">{{ $savedAuctionCount > 99 ? '99+' : $savedAuctionCount }}</span>
                                    @endif
                                </a>
                            </div>
                        @else
                            <div class="d-md-block d-none">
                                <a class="btn p-0 position-relative outline-0 border-0 rounded-circle d-center fs-15 text-primary btn-cmn" href="{{ route('customer.auth.login') }}">
                                    <i class="fi fi-rr-bookmark fs-14"></i>
                                </a>
                            </div>
                        @endif

                        <div class="dropdown dropdown-center">
                            @if(auth('customer')->check())
                                <button class="d-flex gap-1 align-items-center p-0 m-0 border-0 bg-transparent" type="button" data-bs-toggle="dropdown" aria-expanded="true">
                                    <div class="btn p-0 position-relative outline-0 border-0 rounded-circle d-center fs-15 text-primary btn-cmn">
                                        <img class="img-profile rounded-circle object-fit-cover w-100 ratio-1-1" alt=""
                                            src="{{ getStorageImages(path: auth('customer')->user()->image_full_url, type: 'avatar') }}">
                                    </div>
                                    <div class="user-info navbar-tool-text">
                                        <small class="d-flex gap-1 fs-12">
                                            {{ translate('Hello') }}, <span class="max-w-90px line--limit-1">{{ auth('customer')->user()->f_name }}</span>
                                        </small>
                                        <div class="fs-16 has-arrow">{{ translate('Dashboard') }}</div>
                                    </div>
                                </button>
                            @else
                            <button class="btn p-0 position-relative outline-0 border-0 rounded-circle d-center fs-15 text-primary btn-cmn" type="button" data-bs-toggle="dropdown" aria-expanded="true">
                                <i class="fi fi-sr-user"></i>
                            </button>
                            @endif

                            @if(auth('customer')->check())
                                <ul class="dropdown-menu">
                                    <li class="py-1 px-2">
                                        <a href="{{ route('user-account') }}" class="dropdown-item d-flex align-items-center gap-10px">
                                            <i class="fi fi-rr-circle-user"></i> {{ translate('My_Profile') }}
                                        </a>
                                    </li>
                                    <li class="py-1 px-2">
                                        <a href="{{ route('auction.bids.list') }}" class="dropdown-item d-flex align-items-center gap-10px">
                                            <i class="fi fi-rr-auction"></i> {{ translate('My_Bids') }}
                                        </a>
                                    </li>
                                    <li class="py-1 px-2">
                                        <a href="{{ route('customer.auth.logout') }}" class="dropdown-item d-flex align-items-center gap-10px">
                                            <i class="fi fi-rr-sign-out-alt"></i> {{ translate('Logout')}}
                                        </a>
                                    </li>
                                </ul>
                            @else
                                <ul class="dropdown-menu">
                                    <li class="py-1 px-2">
                                        <a href="{{ route('customer.auth.login') }}" class="dropdown-item d-flex align-items-center gap-10px">
                                            <i class="fi fi-rr-sign-out-alt"></i> {{ translate('Sign In') }}
                                        </a>
                                    </li>
                                    <li class="py-1 px-2">
                                        <a href="{{ route('customer.auth.sign-up') }}" class="dropdown-item d-flex align-items-center gap-10px">
                                            <i class="fi fi-rr-circle-user"></i> {{ translate('Sign Up') }}
                                        </a>
                                    </li>
                                </ul>
                            @endif
                        </div>

                        @if(auth('customer')->check())
                            <div class="d-md-block d-none">
                                <button class="btn p-0 position-relative outline-0 border-0 rounded-circle d-center fs-15 text-primary btn-cmn" type="button" aria-expanded="true" data-bs-toggle="offcanvas" data-bs-target="#notification-offcanvas">
                                    <i class="fi fi-sr-bell"></i>
                                    @if(($unreadNotificationCount == 0) > 0)
                                        <span class="status position-absolute">{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>
                                    @endif
                                </button>
                            </div>

                            <div class="d-md-flex d-none align-items-center gap-2">
                                <a class="btn p-0 position-relative outline-0 border-0 rounded-circle d-center fs-15 text-primary btn-cmn" href="{{ route('auction.bids.list') }}">
                                    <i class="fi fi-sr-auction"></i>
                                </a>
                            </div>
                        @else
                            <div class="d-md-block d-none">
                                <a class="btn p-0 position-relative outline-0 border-0 rounded-circle d-center fs-15 text-primary btn-cmn" href="{{ route('customer.auth.login') }}" type="button" aria-expanded="true" data-bs-toggle="offcanvas" data-bs-target="#notification-offcanvas">
                                    <i class="fi fi-sr-bell"></i>
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="header-main py-0 navbar-stuck-menu">
            <div class="container">
                <aside class="aside d-flex flex-column justify-content-between h-100 d-xl-none">
                    <div>
                        <div class="aside-close d-flex justify-content-start p-3 pb-2">
                            <i class="fi fi-sr-cross fs-16"></i>
                        </div>
                        <div class="aside-body" data-trigger="scrollbar">
                            <ul class="main-nav nav">
                                <li class="active open">
                                    <a href="{{ Request::is('auction*') ? route('auction.index') : route('home') }}">
                                        {{ translate('Home') }}
                                    </a>
                                </li>
                                <li class="has-sub-item">
                                    <a href="{{ (getWebConfig(name: 'auction_feature_status') && Request::is('auction*')) ? route('auction.category-products') : route('categories') }}">
                                        {{ translate('Categories') }}
                                    </a>
                                    @php($headerCategories = \App\Utils\CategoryManager::getCategoriesWithCountingAndPriorityWiseSorting(dataLimit: 11))
                                    <ul class="sub_menu">
                                        @foreach($headerCategories as $category)
                                            <li>
                                                <a href="javascript:">
                                                    <span class="get-view-by-onclick" data-link="{{ route('auction.category-products', ['slug' => $category['slug']]) }}">
                                                        {{ $category['name'] }}
                                                    </span>
                                                </a>
                                            </li>
                                        @endforeach
                                        <li>
                                            <a href="{{ (getWebConfig(name: 'auction_feature_status') && Request::is('auction*')) ? route('auction.category-products') : route('categories') }}" class="btn-link text-primary">
                                                {{ translate('View_all') }}
                                            </a>
                                        </li>
                                    </ul>
                                </li>

                                @if(getWebConfig(name: 'product_brand'))
                                <li class="has-sub-item">
                                    <a href="javascript:">{{ translate('Brands') }}</a>
                                    <ul class="sub_menu">
                                        @php($mobileBrandIndex = 0)
                                        @foreach(\Modules\Auction\app\Utils\AuctionProductManager::getAuctionActiveBrandWithCounting() as $brand)
                                            @php($mobileBrandIndex++)
                                            @if($mobileBrandIndex < 10 && !empty($brand['slug']))
                                                <li>
                                                    <a href="{{ route('auction.brands-products', ['slug' => $brand['slug']]) }}">
                                                        {{ $brand['name'] }}
                                                    </a>
                                                </li>
                                            @endif
                                        @endforeach
                                        <li>
                                            <a href="{{ route('auction.brands-products') }}" class="btn-link text-primary">
                                                {{ translate('View all') }}
                                            </a>
                                        </li>
                                    </ul>
                                </li>
                                @endif

                                <li>
                                    <a href="{{ route('auction.ending-soon-products') }}" class="d-flex lh-lg align-items-center no-follow-link gap-2 text-capitalize fs-16 fw-normal title-clr">
                                        <img width="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/ending-soon.png') }}" alt="img" class="object-contain">
                                        {{ translate('Ending Soon') }}
                                    </a>
                                </li>
                                <li>
                                    <a href="{{ route('auction.trending-products') }}" class="d-flex lh-lg align-items-center no-follow-link gap-2 text-capitalize fs-16 fw-normal title-clr">
                                        <img width="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/trend-fire.png') }}" alt="img" class="object-contain">
                                        {{ translate('Trending') }}
                                    </a>
                                </li>

                                <li>
                                    <a href="{{ route('home') }}" class="d-inline-flex py-1 px-2 border rounded lh-lg align-items-center no-follow-link gap-2 text-capitalize fs-14 fw-normal title-clr">
                                        <img width="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bag-forshopping.png') }}" alt="img"> {{ translate('Back to E-commerce') }}
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    @if(auth('customer')->check())
                        <div class="d-flex justify-content-start mt-auto gap-2 bg-white shadow-sm border-top p-3">
                            <a href="{{route('customer.auth.logout')}}" class="text-primary fs-13 fw-bold">
                                {{ translate('logout')}}
                            </a>
                        </div>
                    @endif
                    @if(!auth('customer')->check())
                    <div class="d-flex justify-content-center mt-auto gap-2 bg-white shadow-sm border-top p-3">
                        <a href="{{ route('customer.auth.login') }}" class="btn w-100 bg-light back-to-ecommerce d-flex align-items-center gap-2">
                           <i class="fi fi-sr-user"></i> {{ translate('Log In') }}
                        </a>
                        <a href="{{ route('customer.auth.sign-up') }}" class="btn w-100 bg-light back-to-ecommerce d-flex align-items-center gap-2">
                           <i class="fi fi-sr-sign-in-alt"></i> {{ translate('Sign Up') }}
                        </a>
                    </div>
                    @endif
                </aside>
                <div class="aside-overlay"></div>

                <div class="d-flex justify-content-between gap-3 align-items-center position-relative">
                    <div class="d-flex align-items-center gap-3">
                        <div class="dropdown d-none d-xl-block">
                            <button class="btn d-flex align-items-center justify-content-between py-2 px-3 bg-white rounded fw-semibold text-primary dropdown-toggle select-category-button text-capitalize"
                                type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <img width="21" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/grid-category.svg') }}" alt="img" class="object-contain">
                                {{ translate('Categories') }}
                            </button>
                            <ul class="dropdown-menu w-100 dropdown--menu m-0">
                                @foreach(\Modules\Auction\app\Utils\AuctionProductManager::getAuctionCategoriesForHeaderDropdown() as $category)
                                    <li>
                                        <a href="{{ route('auction.category-products', ['slug' => $category['slug']]) }}">
                                            {{ $category['name'] }}
                                        </a>
                                    </li>
                                @endforeach
                                <li>
                                    <a href="{{ route('auction.category-products') }}" class="btn-link text-primary">
                                        {{ translate('View all') }}
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <div class="nav-wrapper">
                            <ul class="nav main-menu gap-2 list-style-none align-items-center d-none d-xl-flex flex-nowrap">
                                <li class="active has-sub-item text-capitalize no-follow-link fs-16 fw-normal text-white">
                                    <a href="{{ Request::is('auction*') ? route('auction.index') : route('home') }}">
                                        {{ translate('Home') }}
                                    </a>
                                </li>

                                @if(getWebConfig(name: 'product_brand'))
                                <li class="has-sub-item has-dropdown-arrow">
                                    <span class="cursor-pointer text-capitalize no-follow-link d-flex align-items-center gap-5px fs-16 fw-normal text-white">
                                        {{ translate('All Brands') }}
                                    </span>
                                    <ul class="sub-menu list-inline">
                                        @php($brandIndex=0)
                                        @foreach(\Modules\Auction\app\Utils\AuctionProductManager::getAuctionActiveBrandWithCounting() as $brand)
                                            @php($brandIndex++)
                                            @if($brandIndex < 10 && !empty($brand['slug']))
                                                <li>
                                                    <a class="text-capitalize" href="{{ route('auction.brands-products', ['slug' => $brand['slug']]) }}">
                                                        {{ $brand['name'] }}
                                                    </a>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                </li>
                                @endif

                                <li class="has-sub-item">
                                    <a href="{{ route('auction.ending-soon-products') }}" class="d-flex lh-lg align-items-center no-follow-link gap-2 text-capitalize fs-16 fw-normal text-white">
                                        <img width="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/ending-soon.png') }}" alt="img" class="object-contain">
                                        {{ translate('Ending_Soon') }}
                                    </a>
                                </li>
                                <li class="has-sub-item">
                                    <a href="{{ route('auction.trending-products') }}" class="d-flex lh-lg align-items-center no-follow-link gap-2 text-capitalize fs-16 fw-normal text-white">
                                        <img width="20" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/trend-fire.png') }}" alt="img" class="object-contain">
                                        {{ translate('Trending') }}
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <ul class="list-unstyled list-separator mb-0 d-none d-xl-block">
                        <li>
                            <a href="{{ route('home') }}" class="btn bg-white px-sm-3 px-2 py-2 back-to-ecommerce fs-14 fw-semibold text-primary d-flex align-items-center gap-2">
                                <img width="18" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/bag-forshopping.png') }}" alt="img"> {{ translate('Back to E-commerce') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</header>


@if(isset($auctionRecentView) && count($auctionRecentView) > 0)
<div class="offcanvas light-box {{ Session::get('direction') === "rtl" ? 'offcanvas-start' : 'offcanvas-end' }}" tabindex="-1" id="recently_view-offcanvas"
    aria-labelledby="recently_view-offcanvasLabel">
    <div class="offcanvas-header p-20">
        <h5 class="offcanvas-title fs-18 fw-semibold line--limit-1 m-0">
            {{ translate('Recently Viewed Auctions') }}
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body pt-0 p-20">
        <div class="d-flex flex-column gap-xxl-20px gap-3">
            @foreach($auctionRecentView as $auctionProduct)
                <div class="">
                    @include('auction.web-views.partials._auction-recent-view-horizontal', ['auctionProduct' => $auctionProduct])
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif

<div class="offcanvas w-md-500 offcanvas-end" tabindex="-1" id="notification-offcanvas"
    aria-labelledby="notification-offcanvasLabel">
    <div class="offcanvas-header light-box p-20">
        <h5 class="offcanvas-title fs-18 fw-semibold line--limit-1 m-0">{{ translate('Notification') }}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-20" id="auction-notification-body">
        @auth('customer')
            @if(($groupedAuctionNotifications ?? collect())->isNotEmpty())
                <div class="d-flex flex-column gap-xxl-20px gap-3" id="auction-notifications-list">
                    @foreach($groupedAuctionNotifications as $groupLabel => $notifications)
                        <div>
                            <span class="title-semidark mb-10px fs-13 d-block">{{ $groupLabel }}</span>
                            <div class="d-flex flex-column gap-10px">
                                @foreach($notifications as $notification)
                                    <div class="notification-item {{ $notification->is_read ? '' : 'notification-active' }} p-xxl-3 p-2 rounded d-flex align-items-center gap-10px auction-notification-item"
                                        style="cursor:pointer;"
                                        data-notification="{{ json_encode($notification->notif_data) }}">
                                        <div class="w-50px min-w-50px h-50px border rounded-circle overflow-hidden">
                                            <img src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/congrt-bid.png') }}"
                                                alt="" class="object-cover w-100 h-100">
                                        </div>
                                        <div class="w-100">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <h3 class="fw-normal fs-14 title-clr line--limit-1">
                                                    {{ $notification->notif_label }}
                                                </h3>
                                                <div class="d-flex align-items-center gap-1 flex-wrap">
                                                    <span class="{{ $notification->is_read ? 'text-light-gray' : 'text-primary' }} fs-12 d-block auction-notif-time">
                                                        {{ $notification->created_at->diffForHumans() }}
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-1">
                                                <p class="mb-0 line--limit-1 fs-14 title-semidark pe-1 me-4">
                                                    {{ $notification->message }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-5" id="auction-notifications-empty">
                    <i class="fi fi-sr-bell-ring fs-40 text-light-gray mb-3 d-block"></i>
                    <p class="fs-14 title-semidark mb-0">{{ translate('No notifications yet') }}</p>
                </div>
            @endif
        @else
            <div class="text-center py-5">
                <p class="fs-14 title-semidark mb-0">{{ translate('Please login to see notifications') }}</p>
            </div>
        @endauth
    </div>
</div>


<div class="modal fade" id="notification_view" tabindex="-1" aria-labelledby="notification_viewLabel"
    aria-hidden="true" data-bs-backdrop="static">
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
                <div class="mb-4 text-center">
                    <img id="auction-notif-icon"
                        src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/congrt-bid.png') }}" alt=""
                        class="w-70 h-70 rounded-circle mb-20 mx-auto border">
                    <h4 id="auction-notif-title" class="fs-20 fw-semibold title-clr mb-10px"></h4>
                    <p id="auction-notif-body" class="fs-14 title-semidark mb-0 text-center"></p>
                </div>
                <div id="auction-notif-product-box" class="box light-box p-12px rounded d-flex align-items-center gap-10px d-none">
                    <div class="w-50px min-w-50px h-50px border rounded overflow-hidden">
                        <img id="auction-notif-product-img"
                            src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/congrt-bid.png') }}"
                            alt="" class="object-cover w-100 h-100">
                    </div>
                    <div>
                        <h3 id="auction-notif-product-name" class="fw-normal fs-16 title-clr mb-2 line--limit-1"></h3>
                    </div>
                </div>
            </div>
            <div id="auction-notif-footer" class="modal-footer justify-content-center border-0 d-none">
                <div class="max-w-200px mx-auto w-100">
                    <a id="auction-notif-cta" href="#" class="w-100 btn btn-primary d-block text-white"></a>
                </div>
            </div>
        </div>
    </div>
</div>

@include('auction.web-views.partials._notification-item-template')

@include("auction.web-views.partials._image-modal")

<span id="auction-notif-meta"
    data-product-url="{{ route('auction.profile-view.product-details', ['slug' => 'ROUTE_PLACEHOLDER']) }}"
    data-user-id="{{ auth('customer')->id() ?? '' }}"
    data-today-label="{{ translate('Today') }}"
    data-just-now-label="{{ translate('Just now') }}"
></span>

@push('script')
<script>
window.auctionNotifTitles = {!! json_encode($notifTypeLabels ?? []) !!};

(function () {
    'use strict';

     $(document).ready(function () {

        $(".close-search-form-mobile").on("click", function () {
            $(".search-form-mobile").removeClass("active");
        });

        $(".open-search-form-mobile").on("click", function () {
            $(".search-form-mobile").addClass("active");
        });

    });

    // ── Modal ────────────────────────────────────────────────────────────────

    function openModal(notif) {
        document.getElementById('auction-notif-title').textContent = notif.title   || '';
        document.getElementById('auction-notif-body').textContent  = notif.message || '';

        var productBox = document.getElementById('auction-notif-product-box');
        if (notif.product_name) {
            document.getElementById('auction-notif-product-name').textContent = notif.product_name;
            if (notif.product_image) {
                document.getElementById('auction-notif-product-img').src = notif.product_image;
            }
            productBox.classList.remove('d-none');
        } else {
            productBox.classList.add('d-none');
        }

        var footer = document.getElementById('auction-notif-footer');
        var ctaBtn = document.getElementById('auction-notif-cta');

        if (notif.product_url) {
            ctaBtn.textContent = '{{ translate('View Details') }}';
            ctaBtn.href        = notif.product_url;
            footer.classList.remove('d-none');
        } else {
            footer.classList.add('d-none');
        }

        var modalEl = document.getElementById('notification_view');
        if (modalEl && typeof bootstrap !== 'undefined') {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    }

    // ── Sidebar push ─────────────────────────────────────────────────────────

    // Clone the Blade-rendered <template> partial — no HTML in JS
    function buildSidebarItem(notif) {
        var tpl   = document.getElementById('auction-notif-item-template');
        var clone = tpl.content.cloneNode(true).firstElementChild;
        clone.setAttribute('data-notification', JSON.stringify(notif));
        clone.querySelector('.js-notif-title').textContent   = notif.title   || '';
        clone.querySelector('.js-notif-time').textContent    = notif.time    || '';
        clone.querySelector('.js-notif-message').textContent = notif.message || '';
        return clone;
    }

    function pushToSidebar(notif) {
        var meta       = document.getElementById('auction-notif-meta');
        var todayLabel = meta ? (meta.dataset.todayLabel || 'Today') : 'Today';

        var emptyState = document.getElementById('auction-notifications-empty');
        if (emptyState) emptyState.remove();

        var list = document.getElementById('auction-notifications-list');
        if (!list) {
            list = document.createElement('div');
            list.id        = 'auction-notifications-list';
            list.className = 'd-flex flex-column gap-xxl-20px gap-3';
            var body = document.getElementById('auction-notification-body');
            if (body) body.prepend(list);
        }

        // Find or create the "Today" group without innerHTML
        var todayItems = null;
        list.querySelectorAll(':scope > div').forEach(function (group) {
            var label = group.querySelector(':scope > span.title-semidark');
            if (label && label.textContent.trim() === todayLabel) {
                todayItems = group.querySelector('.d-flex.flex-column.gap-10px');
            }
        });

        if (!todayItems) {
            var labelSpan = document.createElement('span');
            labelSpan.className   = 'title-semidark mb-10px fs-13 d-block';
            labelSpan.textContent = todayLabel;

            todayItems = document.createElement('div');
            todayItems.className = 'd-flex flex-column gap-10px';

            var groupDiv = document.createElement('div');
            groupDiv.appendChild(labelSpan);
            groupDiv.appendChild(todayItems);
            list.prepend(groupDiv);
        }

        todayItems.prepend(buildSidebarItem(notif));

        // Increment unread badge (or create one if absent)
        var bellBtn = document.querySelector('button[data-bs-target="#notification-offcanvas"]');
        if (bellBtn) {
            var badge = bellBtn.querySelector('.badge.bg-danger');
            if (badge) {
                var current = parseInt(badge.textContent, 10) || 0;
                badge.textContent = current >= 99 ? '99+' : String(current + 1);
            } else {
                var newBadge = document.createElement('span');
                newBadge.className = 'status position-absolute';
                newBadge.textContent = '1';
                bellBtn.appendChild(newBadge);
            }
        }
    }

    // ── Sidebar click → open modal ───────────────────────────────────────────

    document.addEventListener('click', function (e) {
        var item = e.target.closest('.auction-notification-item');
        if (!item) return;

        var rawData = item.getAttribute('data-notification');
        if (!rawData) return;

        var notif;
        try { notif = JSON.parse(rawData); } catch (err) { return; }

        if (item.classList.contains('notification-active')) {
            item.classList.remove('notification-active');
            var timeSpan = item.querySelector('.auction-notif-time');
            if (timeSpan) timeSpan.classList.replace('text-primary', 'text-light-gray');

            // Decrement badge
            var bellBtn = document.querySelector('button[data-bs-target="#notification-offcanvas"]');
            if (bellBtn) {
                var badge = bellBtn.querySelector('.badge.bg-danger');
                if (badge) {
                    var count = parseInt(badge.textContent, 10) || 1;
                    if (count <= 1) { badge.remove(); } else { badge.textContent = String(count - 1); }
                }
            }

            // Persist read state if DB-backed notification
            if (notif.id) {
                var meta = document.getElementById('auction-notif-meta');
                var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
                fetch('{{ route('auction.notifications.read', ['id' => '__ID__']) }}'.replace('__ID__', notif.id), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                }).catch(function () {});
            }
        }

        openModal(notif);
    });

    // ── Public API — consumed by auction-notification-listener.js ────────────

    window.auctionNotificationUI = {
        openModal:     openModal,
        push:          pushToSidebar,
    };

})();

// Close the Categories dropdown when the page scrolls — the menu stays anchored
// to the toggle, so a scroll leaves it visually detached without this.
(function () {
    var categoryToggle = document.querySelector('.select-category-button');
    if (!categoryToggle || typeof bootstrap === 'undefined') return;
    window.addEventListener('scroll', function () {
        if (categoryToggle.getAttribute('aria-expanded') === 'true') {
            bootstrap.Dropdown.getOrCreateInstance(categoryToggle).hide();
        }
    }, { passive: true });
})();
</script>
@endpush
