@extends('layouts.front-end.app')

{{-- Visual Theme Builder home sections (Phase 1). When a published theme defines home sections they
     REPLACE the hardcoded home below — one theme on the page, never two stacked. With no published
     sections this outputs nothing and the default home renders unchanged.

     Rendered HERE, before any section or stack is opened, and not where it is echoed. A view that
     throws while being rendered inside a section takes Laravel's whole view state with it —
     handleViewException calls flushState(), which drops the open @section, and the page then dies
     at @endsection with "cannot end a section without first starting one". The catch below is
     useless in that position: the exception is reported, the storefront still 500s, and the error
     the merchant sees names a line of layout code with nothing wrong with it. Out here a flush
     costs nothing, so the promise this guard makes — a broken section never takes the shop down —
     is one it can actually keep. --}}
@php
    $__themedHome = '';
    try {
        $__themedHome = trim(view('theme-sections.home')->render());
    } catch (\Throwable $themeSectionsError) {
        report($themeSectionsError);
        $__themedHome = '';
    }
@endphp

@section('title', $web_config['meta_title'])
<style>

.navbar-tool-icon-box svg{
    transform: translate(15px, 13px);

}


 .search_button {
    height: 74% !important;
}

.ml-hero__cap a{
display:none;

}


/* الحاوية الدائرية للشعار */
.ml-brand {
    display: flex !important;
    align-items: center;
    justify-content: center;
    width: 90px;             /* تحكم بالحجم الذي يناسبك */
    height: 90px;            /* يجب أن يكون نفس العرض تماماً لتصبح دائرة */
    border-radius: 50%;
    border: 2px solid rgba(var(--rf-accent-rgb), .65);;
    padding: 12px;           /* مسافة أمان داخلية لمنع ملامسة الشعار للحواف */
    background-color: #ffffff;
    overflow: hidden;        /* لمنع أي جزء من الصورة من الخروج عن الدائرة */
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); /* لمسة احترافية خفيفة */
    transition: all 0.3s ease;
}

/* تأثير عند التمرير (Hover) */
.ml-brand:hover {
    border-color: #577dba;      /* أو لون الهوية الخاص بك */
    transform: translateY(-2px);
}

/* ضبط الصورة داخل الدائرة */
.ml-brand img {
    max-width: 100% !important;
    max-height: 100% !important;
    width: auto !important;
    height: auto !important;
    object-fit: contain !important; /* يحافظ على أبعاد الشعار بدون تمطيط */
    filter: grayscale(1);           /* يعطي مظهر رمادي موحد قبل الـ Hover */
    opacity: 0.8;
    transition: all 0.3s ease;
}

.ml-brand:hover img {
    filter: grayscale(0);           /* إظهار الألوان الأصلية عند التمرير */
    opacity: 1;
}





/* الحاوية الرئيسية: تصفيط العناصر في المنتصف */
.ml-cat-wrapper, 
div[class*="ml-cat"] {
    display: flex !important;
    flex-wrap: nowrap !important;
    justify-content: center !important; /* توسيط العناصر في المنتصف */
    align-items: center;
    overflow-x: auto !important;
    gap: 20px;
    padding: 15px 5px;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}

/* إخفاء شريط التمرير */
.ml-cat-wrapper::-webkit-scrollbar {
    display: none;
}
.ml-cat-wrapper {
    -ms-overflow-style: none;
    scrollbar-width: none;
}

/* منع انكماش العناصر */
.ml-cat-wrapper > div,
.ml-cat-wrapper > a {
    flex: 0 0 auto !important;
    text-align: center; /* توسيط النصوص تحت الصور */
}

/* ضبط الدائرة للأيقونات */
.ml-cat-ring {
    width: 85px !important;
    height: 85px !important;
    border-radius: 50% !important;
    display: flex !important;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px auto !important;
    padding: 12px;
    background: #ffffff;
    border: 1px solid rgba(var(--rf-accent-rgb), .65) !important;
    transition: transform 0.2s ease, border-color 0.2s ease;

box-shadow: #f3bef8 0px 30px 60px -12px inset, #998f99 0px 18px 36px -18px inset;


}

/* ضبط الصور داخل الدوائر */
.ml-cat-ring img {
    max-width: 100% !important;
    max-height: 100% !important;
    width: auto !important;
    height: auto !important;
    object-fit: contain !important;
}

/* تأثير التمرير */
.ml-cat-ring:hover {
    border-color: #000;
    transform: translateY(-3px);
}
</style>




@push('css_or_js')
    {{-- Removed: layouts/front-end/app.blade.php already links this globally, so the page was
         downloading and parsing it a second time. --}}
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/owl.theme.default.min.css') }}">
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/category-header.css') }}">
    <script>
        (function () {
            try {
                var hu = parseInt(localStorage.getItem('auction_badge_hidden_until') || '0', 10);
                if (hu && Date.now() < hu) {
                    var s = document.createElement('style');
                    s.textContent = '.auction__badge{display:none !important}';
                    document.head.appendChild(s);
                } else if (hu) {
                    localStorage.removeItem('auction_badge_hidden_until');
                }
            } catch (e) {}
        })();
    </script>
@endpush

@section('content')

{!! $__themedHome !!}

@if(function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction') && getWebConfig(name: 'auction_feature_status'))
<div class="auction__badge dropdown">
    <a href="javascript:" class="btn_auction p-0 d-flex align-items-center justify-content-center" role="button" data-toggle="dropdown" aria-expanded="false">
        <img width="31" src="{{ dynamicAsset(path: 'public/assets/front-end/auction/images/icons/sale-icon1.png') }}" alt="img" data-toggle="tooltip" data-placement="bottom" data-title="See Auction">
    </a>
    <ul class="dropdown-menu">
        <li>
            <a href="{{ route('auction.index') }}" class="dropdown-item d-flex align-items-center gap-2 fs-14 text-black">
                <i class="fi fi-sr-compass-alt text-primary"></i>
                {{ translate('Explore Auction') }}
                <i class="fi fi-sr-arrow-small-right fs-16 text-black"></i>
            </a>
        </li>
        @if(getWebConfig(name: 'active_auction_for_customer'))
        <li>
            <a href="{{ auth('customer')->check() ? '' : route('customer.auth.login') }}" class="dropdown-item d-flex align-items-center gap-2 fs-14 text-black">
                <i class="fi fi-sr-add text-primary"></i>
                {{ translate('Create Auction') }}
                <i class="fi fi-sr-arrow-small-right fs-16 text-black"></i>
            </a>
        </li>
        @endif
    </ul>
    <button type="button" class="auction_remove d-center btn p-0 outline-0 border-0">
        <i class="fi fi-sr-cross d-flex"></i>
    </button>
</div>
@endif

<?php
    $orderSuccessIds = session('order_success_ids');
    $isNewCustomerInSession = session('isNewCustomerInSession');
    session()->forget('order_success_ids');
    session()->forget('isNewCustomerInSession');
?>
@include("web-views.partials._order-success-modal",['orderSuccessIds' => $orderSuccessIds,'isNewCustomerInSession' => $isNewCustomerInSession])

@if ($__themedHome === '')
<div class="__inline-61 d-flex flex-column gap-20">
        @php($decimalPointSettings = !empty(getWebConfig(name: 'decimal_point_settings')) ? getWebConfig(name: 'decimal_point_settings') : 0)

        @include('web-views.partials._home-top-slider',['bannerTypeMainBanner'=>$bannerTypeMainBanner])
        @if ($flashDeal['flashDeal'] && $flashDeal['flashDealProducts'] && count($flashDeal['flashDealProducts']) > 0)
            @include('web-views.partials._flash-deal', ['decimal_point_settings'=>$decimalPointSettings])
        @endif

        @if(function_exists('getCheckAddonPublishedStatus') && getCheckAddonPublishedStatus(moduleName: 'Auction') && getWebConfig(name: 'auction_feature_status'))
            @include('auction.web-views.partials._auction-home-slider', ['auctionProducts' => $auctionProducts])
       @endif

        @if ($featuredProductsList->count() > 0 )
            <div class="container">
                <div class="__inline-62 section-card-margin">
                    <div class="d-flex justify-content-between align-items-baseline px-3 pt-3">
                        <h2 class="feature-product-title font-bold m-0 text-capitalize h5 letter-spacing-0">
                            {{ translate('featured_products') }}
                        </h2>
                        <div class="text-end d-none d-md-block">
                            <a class="text-capitalize view-all-btn-text" href="{{ route('featured-products') }}">
                                <span class="view-btn-text">{{ translate('View_All') }}</span>
                                <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                            </a>
                        </div>
                    </div>
                    <div class="feature-product">
                        <div class="carousel-wrap p-1">
                            <div class="owl-carousel featured_products_listSlide owl-theme" data-slide-items="{{ count($featuredProductsList) }}">
                                @foreach($featuredProductsList as $product)
                                    <div>
                                        @include('web-views.partials._feature-product',['product'=>$product, 'decimal_point_settings'=>$decimalPointSettings])
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="text-center pt-2 d-md-none">
                            <a class="text-capitalize view-all-btn-text" href="{{ route('featured-products') }}">
                                <span class="view-btn-text">{{ translate('View_All') }}</span>
                                <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @include('web-views.partials._category-section-home')

        {{-- Home promo grid: banners that belong to no category, laid out full
             row / half row / rotating slider by each banner's own layout. --}}
        @if (($homePromoBanners ?? collect())->count() > 0)
            <section class="container rtl pb-0 px-max-sm-0">
                @include('web-views.partials._banner-grid', ['banners' => $homePromoBanners])
            </section>
        @endif

         @if(getFeaturedDealsProductList() && (count(getFeaturedDealsProductList()) > 0))
            <section class="featured_deal pb-3">
                <div class="container">
                    <div class="__featured-deal-wrap bg--light px-0-mobile">
                        <div class="d-flex flex-wrap justify-content-between align-items-sm-start gap-8 mb-xxl-4 mb-3">
                            <div class="w-0 flex-grow-1">
                                <span class="featured_deal_title font-bold text-dark">{{ translate('featured_deal')}}</span>
                                <br>
                                <span class="text-left">{{ translate('see_the_latest_deals_and_exciting_new_offers')}}!</span>
                            </div>
                            <div>
                                <a class="text-capitalize view-all-btn-text" href="{{ route('featured-deal-products') }}">
                                    <span class="view-btn-text">{{ translate('View_All') }}</span>
                                    <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                                </a>
                            </div>
                        </div>
                        <div class="owl-carousel owl-theme new-arrivals-product" data-slide-items="{{ count(getFeaturedDealsProductList()) }}">
                           @foreach(getFeaturedDealsProductList() as $key=>$product)
                                @include('web-views.partials._product-card-1',['product'=>$product, 'decimal_point_settings'=>$decimalPointSettings])
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>
         @endif

        @include('web-views.partials._clearance-sale-products', ['clearanceSaleProducts' => $clearanceSaleProducts])

        @if (isset($bannerTypeMainSectionBanner))
            <div class="container rtl pt-0 px-0 px-md-3">
                <a href="{{$bannerTypeMainSectionBanner->url}}" target="_blank"
                    class="cursor-pointer d-block">
                    <img loading="lazy" class="d-block footer_banner_img __inline-63" alt=""
                         src="{{ getStorageImages(path:$bannerTypeMainSectionBanner->photo_full_url, type: 'wide-banner') }}">
                </a>
            </div>
        @endif

        @php($businessMode = getWebConfig(name: 'business_mode'))
        @if ($businessMode == 'multi' && count($topVendorsList) > 0)
            @include('web-views.partials._top-sellers')
        @endif

        @include('web-views.partials._deal-of-the-day', ['decimal_point_settings' => $decimalPointSettings])

        <section class="new-arrival-section">

            @if ($newArrivalProducts->count() >0 )
                <div class="container rtl">
                    <div class="section-header">
                        <h2 class="arrival-title d-block mb-1">
                            <div class="text-capitalize">
                                {{ translate('new_arrivals')}}
                            </div>
                        </h2>
                    </div>
                </div>
                <div class="container rtl mb-3 overflow-hidden">
                    <div class="py-2">
                        <div class="new_arrival_product">
                            <div class="carousel-wrap">
                                <div class="owl-carousel owl-theme new-arrivals-product" data-slide-items="{{ count($newArrivalProducts) }}">
                                    @foreach($newArrivalProducts as $key=> $product)
                                        @include('web-views.partials._product-card-2',['product'=>$product,'decimal_point_settings'=>$decimalPointSettings])
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="container rtl px-0 px-md-3">
                <div class="row g-3 mx-max-md-0">

                    @if ($bestSellProduct->count() >0)
                        @include('web-views.partials._best-selling')
                    @endif

                    @if ($topRatedProducts->count() >0)
                        @include('web-views.partials._top-rated')
                    @endif
                </div>
            </div>
        </section>


        @if (count($bannerTypeFooterBanner) > 1)
            <div class="container rtl">
                <div class="promotional-banner-slider owl-carousel owl-theme">
                    @foreach($bannerTypeFooterBanner as $banner)
                        <a href="{{ $banner['url'] }}" class="d-block" target="_blank" data-analytics="banner_clicked" data-analytics-type="banner" data-analytics-id="{{ $banner['id'] }}">
                            <img loading="lazy" class="footer_banner_img __inline-63"  alt="" src="{{ getStorageImages(path:$banner->photo_full_url, type: 'banner') }}">
                        </a>
                    @endforeach
                </div>
            </div>
        @elseif (count($bannerTypeFooterBanner) > 0 && count($bannerTypeFooterBanner) == 1)
            <div class="container rtl">
                <div class="row">
                    @foreach($bannerTypeFooterBanner as $banner)
                        <div class="col-md-6">
                            <a href="{{ $banner['url'] }}" class="d-block" target="_blank" data-analytics="banner_clicked" data-analytics-type="banner" data-analytics-id="{{ $banner['id'] }}">
                                <img loading="lazy" class="footer_banner_img __inline-63"  alt="" src="{{ getStorageImages(path:$banner->photo_full_url, type: 'banner') }}">
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        @if($web_config['brand_setting'] && $brands->count() > 0)
            <section class="container rtl">

                <div class="section-header align-items-center mb-1">
                    <h2 class="text-black font-bold __text-22px mb-0">
                        <span> {{translate('brands')}}</span>
                    </h2>
                    <div class="__mr-2px">
                        <a class="text-capitalize view-all-btn-text" href="{{route('brands')}}">
                            <span class="view-btn-text">{{ translate('View_All') }}</span>
                            <i class="czi-arrow-{{ session('direction') === "rtl" ? 'left' : 'right' }}"></i>
                        </a>
                    </div>
                </div>

                <div class="mt-sm-3 mb-0 brand-slider">
                    <div class="owl-carousel owl-theme p-2 brands-slider" data-slide-items="{{ count($brands) }}">
                        @php($brandCount=0)
                        @foreach($brands as $brand)
                            @if($brandCount < 15 && !empty($brand['slug']))
                                <div class="text-center">
                                    <a href="{{ route('brand-products', ['slug' => $brand['slug']]) }}"
                                       class="__brand-item">
                                        <img loading="lazy" alt="{{ $brand?->image_alt_text ?? translate('Brand') }}"
                                             src="{{ getStorageImages(path: $brand->image_full_url, type: 'brand') }}">
                                    </a>
                                </div>
                            @endif
                            @php($brandCount++)
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        @if ($homeCategories->count() > 0)
            @foreach($homeCategories as $category)
                @include('web-views.partials._category-wise-product', ['decimal_point_settings'=>$decimalPointSettings])
            @endforeach
        @endif

        @php($companyReliability = getWebConfig(name: 'company_reliability'))
        @if($companyReliability != null)
            @include('web-views.partials._company-reliability')
        @endif
    </div>
@endif

    <span id="direction-from-session" data-value="{{ session()->get('direction') }}"></span>
@endsection

@push('script')
    @if($orderSuccessIds)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var modalEl = document.getElementById('order_successfully');
                // Bootstrap 4 API: this storefront loads BS4 + jQuery, and `new bootstrap.Modal`
                // is a BS5 global that does not exist here — the call threw and the order-success
                // modal never appeared for anyone.
                if (window.jQuery && jQuery.fn.modal) {
                    jQuery(modalEl).modal({backdrop: 'static', keyboard: false, show: true});
                } else if (window.bootstrap && bootstrap.Modal) {
                    new bootstrap.Modal(modalEl, {backdrop: 'static', keyboard: false}).show();
                }


                document.querySelectorAll('.copy-order-id').forEach(function(copyBtn) {
                    copyBtn.addEventListener('click', function() {
                        let orderTextEl = null;
                        orderTextEl = this.closest('tr')?.querySelector('.order-id-text');
                        if (!orderTextEl) {
                            orderTextEl = this.parentElement.querySelector('.order-id-text');
                        }
                        const orderText = orderTextEl?.textContent.trim();
                        if (orderText) {
                            navigator.clipboard.writeText(orderText).then(() => {
                                toastr.success('Order ID copied successfully!');
                            }).catch(err => {
                                console.warn('Clipboard error:', err);
                                toastr.warning('Unable to copy. Clipboard requires HTTPS or localhost.');
                            });
                        }
                    });
                });
                document.getElementById('modal-close-btn').addEventListener('click', function() {
                    setTimeout(() => { orderModal.hide(); }, 600);
                });
            });
        </script>
    @endif

    @if(Request::is('/') && \Illuminate\Support\Facades\Cookie::has('popup_banner') == false && empty($orderSuccessIds))
        <script>
            $(document).ready(function () {
                $('#popup-modal').modal('show');
            });
        </script>
        @php(\Illuminate\Support\Facades\Cookie::queue('popup_banner', 'off', 1))
    @endif
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/owl.carousel.min.js') }}"></script>
    <script src="{{ theme_asset(path: 'public/assets/front-end/js/home.js') }}"></script>

    <script>
        $(document).on('click', '.auction_remove', function () {
            localStorage.setItem('auction_badge_hidden_until', String(Date.now() + 5 * 60 * 1000));
            $(this).closest('.auction__badge').hide();
        });
    </script>
@endpush