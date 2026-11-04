{{-- Themed announcement bar(s) from the Theme Builder's "header" page. Additive: the built-in
     header below always renders; a broken section logs and the bar is simply absent. --}}
@php
    try {
        echo view('theme-sections.header')->render();
    } catch (\Throwable $themeHeaderError) {
        report($themeHeaderError);
    }
@endphp
@php($announcement=getWebConfig(name: 'announcement'))

@if (isset($announcement) && $announcement['status']==1)
    <div class="text-center position-relative px-4 py-1 d--none" id="announcement"
         style="background-color: {{ $announcement['color'] }};color:{{$announcement['text_color']}}">
        <span>{{ $announcement['announcement'] }} </span>
        <button type="button" class="__close-announcement web-announcement-slideUp"
                aria-label="{{ translate('close') }}">&times;</button>
    </div>
@endif
{{-- The "rtl" class is the live direction hook for header CSS — not dead code. --}}
<header class="rtl __inline-10">
    <div class="topbar py-1 bg-light">
        <div class="container">
            <div class="d-flex align-items-center gap-1 justify-content-between w-100">
                <div>
                    <div class="topbar-text dropdown d-md-none ms-auto">
                        <a class="topbar-link fs-14 d-flex gap-2 fs-14 align-items-center lh-1 direction-ltr" href="tel:{{ $web_config['phone'] }}">
                            <i class="fi fi-rr-phone-call text-primary"></i> {{ $web_config['phone'] }}
                        </a>
                    </div>
                    <div class="d-none d-md-block mr-2 text-nowrap">
                        <a class="topbar-link fs-14 d-flex gap-2 fs-14 align-items-center lh-1 direction-ltr" href="tel:{{ $web_config['phone'] }}">
                            <i class="fi fi-rr-phone-call text-primary"></i> {{ $web_config['phone'] }}
                        </a>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-lg-4 gap-sm-3 gap-2">
                    @php($currency_model = getWebConfig(name: 'currency_model'))
                    @if($currency_model=='multi_currency')
                        <div class="topbar-text dropdown disable-autohide mr-4">
                            <a class="topbar-link dropdown-toggle border-0 fs-14 fw-normal bg-transparent" href="#" data-toggle="dropdown">
                                <span>{{session('currency_code')}} {{session('currency_symbol')}}</span>
                            </a>
                            <ul class="text-align-direction dropdown-menu dropdown-menu-{{Session::get('direction') === "rtl" ? 'right' : 'left'}} min-width-160px">
                                {{-- getActiveCurrencies() is cached; the raw query used to run on
                                     every page view. CurrencyRepository busts it on any change. --}}
                                @foreach (getActiveCurrencies() as $currency)
                                    <li class="dropdown-item cursor-pointer get-currency-change-function" role="button" tabindex="0"
                                        data-code="{{$currency['code']}}">
                                        {{ $currency->name }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="topbar-text dropdown disable-autohide  __language-bar text-capitalize">
                        <a class="topbar-link dropdown-toggle border-0 fs-14 d-flex align-items-center gap-1 fw-normal bg-transparent text-capitalize" href="#" data-toggle="dropdown">
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
                                         src="{{theme_asset(path: 'public/assets/front-end/img/flags/'.strtolower($langFlagCode).'.png')}}"
                                         alt="{{ $data['name'] }}">
                                    {{$data['name']}}
                                @endif
                            @endforeach
                        </a>
                        <ul class="text-align-direction dropdown-menu dropdown-menu-{{Session::get('direction') === "rtl" ? 'right' : 'left'}}">
                            @foreach($web_config['language'] as $key =>$data)
                                @if($data['status'] == 1)
                                        <?php
                                        $langFlagCode = $data['code'];
                                        if (\Illuminate\Support\Str::contains($data['code'], '-')) {
                                            $countryCodeArr = explode('-', $data['code']);
                                            $langFlagCode = $countryCodeArr[0];
                                        }
                                        ?>
                                    <li class="change-language" data-action="{{route('change-language')}}" data-language-code="{{$data['code']}}">
                                        <a class="dropdown-item pb-1" href="javascript:">
                                            <img class="mr-2"
                                                 width="20"
                                                 src="{{theme_asset(path: 'public/assets/front-end/img/flags/'.strtolower($langFlagCode).'.png')}}"
                                                 alt="{{$data['name']}}"/>
                                            <span class="text-capitalize">{{$data['name']}}</span>
                                        </a>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="navbar-sticky bg-light mobile-head">
        <div class="navbar navbar-expand-md navbar-light">
            <div class="container ">
                <div>
                    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarCollapse">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <a class="navbar-brand d-sm-none system-logo-element"
                       href="{{route('home')}}">
                        <img src="{{ getStorageImages(path: $web_config['mob_logo'], type: 'logo') }}"
                             alt="{{$web_config['company_name']}}"/>
                    </a>
                </div>
                <a class="navbar-brand d-none d-sm-block me-3 flex-shrink-0 system-logo-element mb-2"
                   href="{{route('home')}}">
                    <img src="{{ getStorageImages(path: $web_config['web_logo'], type: 'logo') }}"
                         alt="{{ $web_config['company_name'] }}">
                </a>

                <div class="input-group-overlay mx-lg-4 search-form-mobile text-align-direction">
                    <form action="{{route('products')}}" type="submit" class="search_form">
                        <div class="d-flex align-items-center gap-2">
                            <input class="form-control appended-form-control search-bar-input" type="search"
                                   autocomplete="off" data-given-value=""
                                   placeholder="{{ translate("search_for_items")}}..."
                                   name="name" value="{{ request('name') }}">

                            <input type="hidden" name="global_search_input" value="1">

                            <button class="input-group-append-overlay search_button d-none d-md-block" type="submit">
                                <span class="input-group-text __text-20px">
                                    <i class="czi-search text-white"></i>
                                </span>
                            </button>

                            {{-- A real button, and shown on the whole range that can OPEN the
                                 overlay (the opener is d-lg-none): tablets could open it but the
                                 d-md-none span gave them no way to close it, and a span with
                                 type="submit" was never focusable anyway. --}}
                            <button class="close-search-form-mobile fs-14 font-semibold text-muted d-lg-none text-nowrap border-0 bg-transparent"
                                    type="button">
                                {{ translate('cancel') }}
                            </button>
                        </div>

                        <input name="data_from" value="search" hidden>
                        <input name="page" value="1" hidden>
                        <diV class="card search-card mobile-search-card">
                            <div class="card-body">
                                <div class="search-result-box __h-400px overflow-x-hidden overflow-y-auto"></div>
                            </div>
                        </diV>
                    </form>
                </div>

                <div class="navbar-toolbar d-flex flex-shrink-0 align-items-center">
                    <a class="navbar-tool navbar-stuck-toggler" href="#">
                        <span class="navbar-tool-tooltip">{{ translate('expand_Menu') }}</span>
                        <div class="navbar-tool-icon-box">
                            <i class="navbar-tool-icon czi-menu open-icon"></i>
                            <i class="navbar-tool-icon czi-close close-icon"></i>
                        </div>
                    </a>
                    <div class="navbar-tool open-search-form-mobile d-lg-none {{Session::get('direction') === "rtl" ? 'mr-md-3' : 'ml-md-3'}}">
                        <a class="navbar-tool-icon-box bg-secondary" href="javascript:">
                            <i class="tio-search"></i>
                        </a>
                    </div>
                    <div class="navbar-tool dropdown d-none d-md-block {{Session::get('direction') === "rtl" ? 'mr-md-3' : 'ml-md-3'}}">
                        <a class="navbar-tool-icon-box bg-secondary" href="{{route('wishlists')}}"
                           aria-label="{{ translate('wishlist') }}">
                            <span class="navbar-tool-label">
                                <span class="countWishlist">
                                    {{session()->has('wish_list')?count(session('wish_list')):0}}
                                </span>
                           </span>
                            <i class="navbar-tool-icon czi-heart"></i>
                        </a>
                    </div>
                    @if(auth('customer')->check())
                        <div class="dropdown">
                            {{-- href makes the toggle keyboard-focusable; without it the profile
                                 menu could only ever be opened with a mouse. --}}
                            <a class="navbar-tool ml-3" href="#" id="profileDropdownButton" role="button"
                               data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <div class="navbar-tool-icon-box bg-secondary">
                                    <img class="img-profile rounded-circle __inline-14" alt=""
                                         src="{{ getStorageImages(path: auth('customer')->user()->image_full_url, type: 'avatar') }}">
                                </div>
                                <div class="navbar-tool-text">
                                    <small>
                                        {{ translate('hello')}}, {{ Str::limit(auth('customer')->user()->f_name, 10) }}
                                    </small>
                                    {{ translate('dashboard')}}
                                </div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-{{Session::get('direction') === "rtl" ? 'left' : 'right'}}"
                                 aria-labelledby="profileDropdownButton">
                                <a class="dropdown-item"
                                   href="{{route('account-oder')}}"> {{ translate('my_Order')}} </a>
                                <a class="dropdown-item"
                                   href="{{route('user-account')}}"> {{ translate('my_Profile')}}</a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item"
                                   href="{{route('customer.auth.logout')}}">{{ translate('logout')}}</a>
                            </div>
                        </div>
                    @else
                        <div class="dropdown">
                            <a class="navbar-tool {{Session::get('direction') === "rtl" ? 'mr-md-3' : 'ml-md-3'}}"
                               id="authDropdownButton" role="button" data-toggle="dropdown" aria-haspopup="true"
                               href="#" rel="nofollow" aria-expanded="false"
                               aria-label="{{ translate('sign_in') }}">
                                <div class="navbar-tool-icon-box bg-secondary">
                                    <svg width="16" height="17" viewBox="0 0 16 17" fill="none"
                                         xmlns="http://www.w3.org/2000/svg">
                                        <path d="M4.25 4.41675C4.25 6.48425 5.9325 8.16675 8 8.16675C10.0675 8.16675 11.75 6.48425 11.75 4.41675C11.75 2.34925 10.0675 0.666748 8 0.666748C5.9325 0.666748 4.25 2.34925 4.25 4.41675ZM14.6667 16.5001H15.5V15.6667C15.5 12.4509 12.8825 9.83341 9.66667 9.83341H6.33333C3.11667 9.83341 0.5 12.4509 0.5 15.6667V16.5001H14.6667Z"
                                              fill="{{ $web_config['primary_color'] ?? '#1B7FED'}}"/>
                                    </svg>
                                </div>
                            </a>
                            <div class="text-align-direction dropdown-menu __auth-dropdown dropdown-menu-{{Session::get('direction') === "rtl" ? 'left' : 'right'}}"
                                 aria-labelledby="authDropdownButton">
                                <a class="dropdown-item" href="{{route('customer.auth.login')}}">
                                    <i class="fa fa-sign-in mr-2"></i> {{ translate('sign_in')}}
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="{{route('customer.auth.sign-up')}}">
                                    <i class="fa fa-user-circle mr-2"></i>{{ translate('sign_up')}}
                                </a>
                            </div>
                        </div>
                    @endif
                    <div id="cart_items">
                        @include('layouts.front-end.partials._cart')
                    </div>
                </div>
            </div>
        </div>

        <div class="navbar navbar-expand-md navbar-stuck-menu">
            <div class="container px-10px">
                <div class="collapse navbar-collapse text-align-direction" id="navbarCollapse">
                    <div class="w-100 d-md-none text-align-direction">
                        <button class="navbar-toggler p-0" type="button" data-toggle="collapse"
                                data-target="#navbarCollapse">
                            <i class="tio-clear __text-26px"></i>
                        </button>
                    </div>

                    <ul class="navbar-nav d-block d-md-none">
                        <li class="nav-item dropdown {{request()->is('/')?'active':''}}">
                            <a class="nav-link fw-semibold" href="{{ route('home') }}">{{ translate('home')}}</a>
                        </li>
                    </ul>

                    @php($categories = \App\Utils\CategoryManager::getCategoriesWithCountingAndPriorityWiseSorting(dataLimit: 11))

                    <ul class="navbar-nav mega-nav pr-lg-2 pl-lg-2 mr-2 d-none d-md-block __mega-nav">
                        <li class="nav-item {{!request()->is('/')?'dropdown':''}}">

                            <a class="nav-link fw-semibold dropdown-toggle category-menu-toggle-btn ps-0"
                               href="javascript:">
                                <svg width="21" height="21" viewBox="0 0 21 21" fill="none"
                                     xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                          d="M9.875 12.9195C9.875 12.422 9.6775 11.9452 9.32563 11.5939C8.97438 11.242 8.4975 11.0445 8 11.0445C6.75875 11.0445 4.86625 11.0445 3.625 11.0445C3.1275 11.0445 2.65062 11.242 2.29937 11.5939C1.9475 11.9452 1.75 12.422 1.75 12.9195V17.2945C1.75 17.792 1.9475 18.2689 2.29937 18.6202C2.65062 18.972 3.1275 19.1695 3.625 19.1695H8C8.4975 19.1695 8.97438 18.972 9.32563 18.6202C9.6775 18.2689 9.875 17.792 9.875 17.2945V12.9195ZM19.25 12.9195C19.25 12.422 19.0525 11.9452 18.7006 11.5939C18.3494 11.242 17.8725 11.0445 17.375 11.0445C16.1337 11.0445 14.2413 11.0445 13 11.0445C12.5025 11.0445 12.0256 11.242 11.6744 11.5939C11.3225 11.9452 11.125 12.422 11.125 12.9195V17.2945C11.125 17.792 11.3225 18.2689 11.6744 18.6202C12.0256 18.972 12.5025 19.1695 13 19.1695H17.375C17.8725 19.1695 18.3494 18.972 18.7006 18.6202C19.0525 18.2689 19.25 17.792 19.25 17.2945V12.9195ZM16.5131 9.66516L19.1206 7.05766C19.8525 6.32578 19.8525 5.13828 19.1206 4.4064L16.5131 1.79891C15.7813 1.06703 14.5937 1.06703 13.8619 1.79891L11.2544 4.4064C10.5225 5.13828 10.5225 6.32578 11.2544 7.05766L13.8619 9.66516C14.5937 10.397 15.7813 10.397 16.5131 9.66516ZM9.875 3.54453C9.875 3.04703 9.6775 2.57015 9.32563 2.2189C8.97438 1.86703 8.4975 1.66953 8 1.66953C6.75875 1.66953 4.86625 1.66953 3.625 1.66953C3.1275 1.66953 2.65062 1.86703 2.29937 2.2189C1.9475 2.57015 1.75 3.04703 1.75 3.54453V7.91953C1.75 8.41703 1.9475 8.89391 2.29937 9.24516C2.65062 9.59703 3.1275 9.79453 3.625 9.79453H8C8.4975 9.79453 8.97438 9.59703 9.32563 9.24516C9.6775 8.89391 9.875 8.41703 9.875 7.91953V3.54453Z"
                                          fill="currentColor"/>
                                </svg>
                                <span class="category-menu-toggle-btn-text fw-semibold">
                                    {{ translate('categories')}}
                                </span>
                            </a>
                        </li>
                    </ul>

                    <ul class="navbar-nav mega-nav1 pr-md-2 pl-md-2 d-block d-xl-none">
                        <li class="nav-item dropdown d-md-none">
                            <a class="nav-link fw-semibold dropdown-toggle ps-0"
                               href="javascript:" data-toggle="dropdown">
                                <i class="czi-menu align-middle mt-n1 me-2"></i>
                                <span class="me-4">
                                    {{ translate('categories')}}
                                </span>
                            </a>
                            <ul class="dropdown-menu __dropdown-menu-2 text-align-direction">
                                @php($categoryIndex=0)
                                @foreach($categories as $category)
                                    @php($categoryIndex++)
                                    @if($categoryIndex < 10)
                                        <li class="dropdown">

                                            <a href="{{ route('category-products', ['slug' => $category['slug']]) }}" class="d-flex gap-10px align-items-center">
                                                <img class="aspect-1 rounded-circle" width="20" src="{{ getStorageImages(path: $category?->icon_full_url, type: 'category') }}" alt="{{ $category['name'] }}">
                                                <span>{{$category['name']}}</span>
                                            </a>
                                            @if ($category->childes->count() > 0)
                                                <a data-toggle='dropdown' class='__ml-50px'>
                                                    <i class="czi-arrow-{{Session::get('direction') === "rtl" ? 'left' : 'right'}} __inline-16"></i>
                                                </a>
                                            @endif

                                            @if($category->childes->count()>0)
                                                <ul class="dropdown-menu text-align-direction">
                                                    @foreach($category['childes'] as $subCategory)
                                                        <li class="dropdown">
                                                            <a href="{{ route('category-products', ['slug' => $subCategory['slug']]) }}">
                                                                <span>{{$subCategory['name']}}</span>
                                                            </a>

                                                            @if($subCategory->childes->count()>0)
                                                                <a class="header-subcategories-links"
                                                                   data-toggle='dropdown'>
                                                                    <i class="czi-arrow-{{Session::get('direction') === "rtl" ? 'left' : 'right'}} __inline-16"></i>
                                                                </a>
                                                                <ul class="dropdown-menu">
                                                                    @foreach($subCategory['childes'] as $subSubCategory)
                                                                        <li>
                                                                            <a class="dropdown-item"
                                                                               href="{{ route('category-products', ['slug' => $subSubCategory['slug']]) }}">
                                                                                {{ $subSubCategory['name'] }}
                                                                            </a>
                                                                        </li>
                                                                    @endforeach
                                                                </ul>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </li>
                                    @endif
                                @endforeach
                                <li class="__inline-17">
                                    <div>
                                        <a class="dropdown-item web-text-primary" href="{{ route('categories') }}">
                                            {{ translate('view_more') }}
                                        </a>
                                    </div>
                                </li>
                            </ul>
                        </li>
                    </ul>

                    <ul class="navbar-nav">
                        <li class="nav-item dropdown d-none d-md-block {{request()->is('/')?'active':''}}">
                            <a class="nav-link fw-semibold" href="{{route('home')}}">{{ translate('home')}}</a>
                        </li>

                        @if(getWebConfig(name: 'product_brand'))
                            <li class="nav-item dropdown">
                                <a class="nav-link fw-semibold dropdown-toggle" href="#"
                                   data-toggle="dropdown">{{ translate('brand') }}</a>
                                <ul class="text-align-direction dropdown-menu __dropdown-menu-sizing dropdown-menu-{{Session::get('direction') === "rtl" ? 'right' : 'left'}} scroll-bar">
                                    @php($brandIndex=0)
                                    @foreach(\App\Utils\BrandManager::getActiveBrandWithCountingAndPriorityWiseSorting() as $brand)
                                        @php($brandIndex++)
                                        @if($brandIndex < 10 && !empty($brand['slug']))
                                            <li class="__inline-17">
                                                <div>
                                                    <a class="dropdown-item"
                                                       href="{{ route('brand-products', ['slug' => $brand['slug']]) }}">
                                                        {{ $brand['name'] }}
                                                    </a>
                                                </div>
                                                <div class="align-baseline">
                                                    @if($brand['brand_products_count'] > 0 )
                                                        <span class="count-value px-2">( {{ $brand['brand_products_count'] }} )</span>
                                                    @endif
                                                </div>
                                            </li>
                                        @endif
                                    @endforeach
                                    <li class="__inline-17">
                                        <div>
                                            <a class="dropdown-item web-text-primary" href="{{route('brands')}}">
                                                {{ translate('view_more') }}
                                            </a>
                                        </div>
                                    </li>
                                </ul>
                            </li>
                        @endif

                        <?php
                            // One offer type alone links directly; two or more collapse into the
                            // Offers dropdown. The old checks re-ran getFeaturedDealsProductList()
                            // seven times per render, and two exclusion clauses used
                            // ($web_config['flash_deals'] || ...) where every positive check uses
                            // (... && ...) - so an empty flash-deal record wrongly demoted a solo
                            // featured or clearance link into the dropdown.
                            // (A raw PHP block, not a Blade php block: this file opens with the
                            // inline parenthesized form on line 1, and Blade pairs that opener
                            // with any later block terminator - even inside a comment - and
                            // swallows everything between as raw PHP.)
                            $hasFeaturedDeals = count(getFeaturedDealsProductList()) > 0;
                            $hasFlashDeals = $web_config['flash_deals'] && count($web_config['flash_deals_products']) > 0;
                            $hasDiscounts = $web_config['discount_product'] > 0;
                            $hasClearanceSale = $web_config['clearance_sale_product_count'] > 0;
                            $offerTypeCount = (int) $hasFeaturedDeals + (int) $hasFlashDeals + (int) $hasDiscounts + (int) $hasClearanceSale;
                        ?>
                        @if($offerTypeCount === 1)
                            <li class="nav-item dropdown">
                                @if($hasFeaturedDeals)
                                    <a class="nav-link fw-semibold text-capitalize"
                                       href="{{ route('featured-deal-products') }}">
                                        {{ translate('featured_Deal')}}
                                    </a>
                                @elseif($hasFlashDeals)
                                    <a class="nav-link fw-semibold text-capitalize"
                                       href="{{ route('flash-deals', ['id' => $web_config['flash_deals']['id'] ?? 0]) }}">
                                        {{ translate('flash_deal')}}
                                    </a>
                                @elseif($hasDiscounts)
                                    <a class="nav-link fw-semibold text-capitalize"
                                       href="{{ route('discounted-products') }}">
                                        {{ translate('discounted_products')}}
                                    </a>
                                @else
                                    <a class="nav-link fw-semibold text-capitalize"
                                       href="{{ route('clearance-sale-products') }}">
                                        {{ translate('clearance_Sale')}}
                                    </a>
                                @endif
                            </li>
                        @elseif($offerTypeCount > 1)
                            <li class="nav-item">
                                <div class="dropdown">
                                    <button class="btn dropdown-toggle text-white text-max-md-dark text-capitalize ps-2 pe-0 fw-semibold"
                                            type="button" id="offersDropdownButton"
                                            data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        {{ translate('offers')}}
                                    </button>
                                    <div class="dropdown-menu __dropdown-menu-3 __min-w-165px text-align-direction"
                                         aria-labelledby="offersDropdownButton">
                                        @if($hasFeaturedDeals)
                                            <a class="dropdown-item text-nowrap text-capitalize"
                                               href="{{ route('featured-deal-products') }}">
                                                {{ translate('featured_Deal')}}
                                            </a>
                                        @endif

                                        @if($hasFlashDeals)
                                            @if($hasFeaturedDeals)
                                                <div class="dropdown-divider"></div>
                                            @endif
                                            <a class="dropdown-item text-nowrap text-capitalize" href="{{ route('flash-deals',['id' => $web_config['flash_deals']['id'] ?? 0]) }}">
                                                {{ translate('flash_deal')}}
                                            </a>
                                        @endif

                                        @if($hasDiscounts)
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-nowrap text-capitalize"
                                               href="{{ route('discounted-products') }}">
                                                {{ translate('discounted_products')}}
                                            </a>
                                        @endif

                                        @if($hasClearanceSale)
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-nowrap"
                                               href="{{ route('clearance-sale-products') }}">
                                                {{ translate('clearance_Sale')}}
                                            </a>
                                        @endif

                                    </div>
                                </div>
                            </li>
                        @endif

                        @if ($web_config['digital_product_setting'] && count($web_config['publishing_houses_header']) == 1)
                            @php($firstPublisherID = is_array($web_config['publishing_houses_header']) && isset($web_config['publishing_houses_header']['id']) ? $web_config['publishing_houses_header']['id'] : $web_config['publishing_houses_header']?->first()?->id)
                            <li class="nav-item dropdown {{request()->is('/')?'active':''}}">
                                <a class="nav-link fw-semibold" href="{{ route('products',['data_from' => 'publishing_house', 'publishing_house_id' => $firstPublisherID, 'product_type' => 'digital', 'page'=>1]) }}">
                                    {{ translate('Publication_House') }}
                                </a>
                            </li>
                        @elseif ($web_config['digital_product_setting'] && count($web_config['publishing_houses_header']) > 1)
                            <li class="nav-item dropdown">
                                <a class="nav-link fw-semibold dropdown-toggle" href="#" data-toggle="dropdown">
                                    {{ translate('Publication_House') }}
                                </a>
                                <ul class="text-align-direction dropdown-menu __dropdown-menu-sizing dropdown-menu-{{Session::get('direction') === "rtl" ? 'right' : 'left'}} scroll-bar">
                                    @php($publishingHousesIndex=0)
                                    @foreach($web_config['publishing_houses_header'] as $publishingHouseItem)
                                        @if($publishingHousesIndex < 10 && $publishingHouseItem['name'] != 'Unknown')
                                            @php($publishingHousesIndex++)
                                            <li class="__inline-17">
                                                <div>
                                                    <a class="dropdown-item"
                                                       href="{{ route('products',['data_from' => 'publishing_house', 'publishing_house_id' => $publishingHouseItem['id'], 'product_type' => 'digital', 'page'=>1]) }}">
                                                        {{ $publishingHouseItem['name'] }}
                                                    </a>
                                                </div>
                                                <div class="align-baseline">
                                                    @if($publishingHouseItem['publishing_house_products_count'] > 0 )
                                                        <span class="count-value px-2">( {{ $publishingHouseItem['publishing_house_products_count'] }} )</span>
                                                    @endif
                                                </div>
                                            </li>
                                        @endif
                                    @endforeach
                                    <li class="__inline-17">
                                        <div>
                                            <a class="dropdown-item web-text-primary"
                                               href="{{ route('products', ['data_from' => 'publishing_house', 'product_type' => 'digital', 'page' => 1]) }}">
                                                {{ translate('view_more') }}
                                            </a>
                                        </div>
                                    </li>
                                </ul>
                            </li>
                        @endif

                        @php($businessMode = getWebConfig(name: 'business_mode'))
                        @if ($businessMode == 'multi')
                            <li class="nav-item dropdown {{request()->is('/')?'active':''}}">
                                <a class="nav-link fw-semibold text-capitalize"
                                   href="{{route('vendors')}}">{{ translate('all_vendors')}}</a>
                            </li>
                        @endif

                        @if(auth('customer')->check())
                            <li class="nav-item d-md-none">
                                <a href="{{route('user-account')}}" class="nav-link fw-semibold text-capitalize">
                                    {{ translate('user_profile')}}
                                </a>
                            </li>
                            <li class="nav-item d-md-none">
                                <a href="{{route('wishlists')}}" class="nav-link font-weight-normal">
                                    {{ translate('Wishlist')}}
                                </a>
                            </li>
                        @else
                            <li class="nav-item d-md-none">
                                <a class="dropdown-item pl-2" href="{{route('customer.auth.login')}}">
                                    <i class="fa fa-sign-in mr-2"></i> {{ translate('sign_in')}}
                                </a>
                                <div class="dropdown-divider"></div>
                            </li>
                            <li class="nav-item d-md-none">
                                <a class="dropdown-item pl-2" href="{{route('customer.auth.sign-up')}}">
                                    <i class="fa fa-user-circle mr-2"></i>{{ translate('sign_up')}}
                                </a>
                            </li>
                        @endif
                        @if ($businessMode == 'multi')
                            @if(getWebConfig(name: 'seller_registration'))
                                <li class="nav-item">
                                    <div class="dropdown">
                                        <button class="btn dropdown-toggle text-white text-max-md-dark text-capitalize ps-2 pe-0 fw-semibold"
                                                type="button" id="vendorZoneDropdownButton"
                                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                            {{ translate('vendor_zone')}}
                                        </button>
                                        <div class="dropdown-menu __dropdown-menu-3 __min-w-165px text-align-direction"
                                             aria-labelledby="vendorZoneDropdownButton">
                                            <a class="dropdown-item text-nowrap text-capitalize" href="{{route('vendor.auth.registration.index')}}">
                                                {{ translate('become_a_vendor')}}
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-nowrap" href="{{route('vendor.auth.login')}}">
                                                {{ translate('vendor_login')}}
                                            </a>
                                        </div>
                                    </div>
                                </li>
                            @endif
                        @endif
                    </ul>

                     @if(function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction') && getWebConfig(name: 'auction_feature_status') && !(Request::is('auction*')))
                        <div class="mb-2 mt-3 d-md-none d-block">
                            <a href="{{ route('auction.index') }}" class="border btn badge rounded min-h-38 fs-14 text-primary fw-semibold bg-white py-1 px-3 d-inline-flex align-items-center justify-content-center gap-2">
                                <img width="14" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon1.png') }}" alt="{{ translate('Auctions') }}">
                                <span class="d-block">
                                {{ translate('Auctions') }}
                            </span>
                            </a>
                        </div>
                    @endif

                    @if(auth('customer')->check())
                        <div class="logout-btn mt-auto d-md-none">
                            <hr>
                            <a href="{{route('customer.auth.logout')}}" class="nav-link font-weight-normal">
                                <strong class="text-base">{{ translate('logout')}}</strong>
                            </a>
                        </div>
                    @endif
                </div>

                @if(function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction') && getWebConfig(name: 'auction_feature_status'))
                {{-- d-md-block, not d-xl-block: between 768 and 1199px neither this block nor
     the drawer copy (d-md-none) rendered, so tablets had no auction entry at all. --}}
                <div class="d-md-block d-none">
                    <a href="{{ route('auction.index') }}" class="btn badge rounded min-h-38 fs-14 text-primary fw-semibold bg-white py-2 px-3 d-flex align-items-center justify-content-center gap-2">
                        <img width="14" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon1.png') }}" alt="{{ translate('Auctions') }}">
                        <span class="d-sm-block d-none">
                            {{ translate('Auctions') }}
                        </span>
                    </a>
                </div>
                @endif
            </div>
        </div>


        <div class="megamenu-wrap">
            <div class="container">
                <div class="category-menu-wrap">
                    <ul class="category-menu">
                        @foreach ($categories as $key=>$category)
                            <li>
                                <a href="{{ route('category-products', ['slug' => $category['slug']]) }}">
                                    <span class="d-flex gap-10px justify-content-start align-items-center">
                                        <img class="aspect-1 rounded-circle" width="20" src="{{ getStorageImages(path: $category?->icon_full_url, type: 'category') }}" alt="{{ $category['name'] }}">
                                        <span class="line--limit-2">{{ $category->name }}</span>
                                    </span>
                                </a>
                                @if ($category->childes->count() > 0)
                                    <div class="mega_menu z-2">
                                        @foreach ($category->childes as $sub_category)
                                            <div class="mega_menu_inner">
                                                <h6>
                                                    <a href="{{ route('category-products', ['slug' => $sub_category['slug']]) }}">
                                                        {{ $sub_category->name }}
                                                    </a>
                                                </h6>
                                                @if ($sub_category->childes->count() >0)
                                                    @foreach ($sub_category->childes as $sub_sub_category)
                                                        <div>
                                                            <a href="{{ route('category-products', ['slug' => $sub_sub_category['slug']]) }}">
                                                                {{ $sub_sub_category->name }}
                                                            </a>
                                                        </div>
                                                    @endforeach
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </li>
                        @endforeach
                        <li class="text-center">
                            <a href="{{route('categories')}}" class="text-primary font-weight-bold justify-content-center">
                                {{ translate('View_All') }}
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</header>

@push('script')
    <script>
        "use strict";

        $(".category-menu").find(".mega_menu").parents("li")
            .addClass("has-sub-item").find("> a")
            .append("<i class='czi-arrow-{{Session::get('direction') === "rtl" ? 'left' : 'right'}}'></i>");
    </script>
@endpush
