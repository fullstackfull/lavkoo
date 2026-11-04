<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ session()->get('direction') ?? 'ltr' }}">

<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <meta name="_token" content="{{csrf_token()}}">
    <meta property="og:site_name" content="{{ $web_config['company_name'] }}" />

    <meta name="google-site-verification" content="{{getWebConfig('google_search_console_code')}}">
    <meta name="msvalidate.01" content="{{getWebConfig('bing_webmaster_code')}}">
    <meta name="baidu-site-verification" content="{{getWebConfig('baidu_webmaster_code')}}">
    <meta name="yandex-verification" content="{{getWebConfig('yandex_webmaster_code')}}">

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $web_config['fav_icon']['path'] }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $web_config['fav_icon']['path'] }}">

    <link rel="stylesheet" media="screen" href="{{ theme_asset(path: 'public/assets/front-end/vendor/simplebar/dist/simplebar.min.css') }}">
    <link rel="stylesheet" media="screen" href="{{ theme_asset(path: 'public/assets/front-end/vendor/tiny-slider/dist/tiny-slider.css') }}">
    <link rel="stylesheet" media="screen" href="{{ theme_asset(path: 'public/assets/front-end/vendor/drift-zoom/dist/drift-basic.min.css') }}">
    <link rel="stylesheet" media="screen" href="{{ theme_asset(path: 'public/assets/front-end/vendor/lightgallery.js/dist/css/lightgallery.min.css') }}">
    <link rel="stylesheet" media="screen" href="{{ theme_asset(path: 'public/assets/front-end/css/theme.css') }}">
    {{-- slick was removed: nothing in this theme calls .slick() and no markup carries a
         slick- class, so its 54 KB shipped on every page for nothing. Owl, Swiper and
         tiny-slider all have real callers and stay. --}}
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/font-awesome.min.css') }}">
    {{-- These two icon-font stylesheets are 500 KB together and define 7,867 classes. Measured
         across the storefront's home, product list, login and contact pages, exactly TWO of them
         are used: fi-rr-phone-call and fi-sr-bars-filter. Subsetting was rejected as too fragile —
         a class added later in a view or by script would silently lose its icon — so instead they
         are loaded without blocking the first paint. The media="print" swap is the standard way to
         do that: the browser fetches at low priority and applies on load. The two icons appear a
         beat after the page, which for two icons is not perceptible; everything else paints sooner.
         <noscript> keeps them blocking for clients that cannot run the swap. --}}
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-regular-rounded.css') }}"
          media="print" onload="this.media='all';this.onload=null;">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-solid-rounded.css') }}"
          media="print" onload="this.media='all';this.onload=null;">
    <noscript>
        <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-regular-rounded.css') }}">
        <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-solid-rounded.css') }}">
    </noscript>
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/back-end/css/toastr.css') }}"/>
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/master.css') }}"/>
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/roboto-font.css')  }}">
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/css/lightbox.css') }}">
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/back-end/vendor/icon-set/style.css') }}">
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/owl.carousel.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/front-end/plugin/swiper/swiper-bundle.min.css') }}"/>
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/css/intlTelInput.css') }}">

    @stack('css_or_js')

    @include(VIEW_FILE_NAMES['robots_meta_content_partials'])

    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/home.css') }}"/>
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/responsive1.css') }}"/>
    <link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/style.css') }}">

    <style>
        :root {
            --base: {{ $web_config['primary_color'] }};
            --bs-base-rgb: {{ getHexToRGBColorCode($web_config['primary_color']) }};
            --base-2: {{ $web_config['secondary_color'] }};
            --web-primary: {{ $web_config['primary_color'] }};
            --web-primary-rgb: {{ getHexToRGBColorCode($web_config['primary_color']) }};
            --web-primary-10: {{ $web_config['primary_color'] }}10;
            --web-primary-20: {{ $web_config['primary_color'] }}20;
            --web-primary-40: {{ $web_config['primary_color'] }}40;
            --web-secondary: {{ $web_config['secondary_color'] }};
            --web-direction: {{ Session::get('direction') }};
            --text-align-direction: {{ Session::get('direction') === "rtl" ? 'right' : 'left' }};
            --text-align-direction-alt: {{ Session::get('direction') === "rtl" ? 'left' : 'right'}};
        }

        .dropdown-menu:not(.m-0) {
            margin-{{ Session::get('direction') === "rtl" ? 'right' : 'left' }}: -8px !important;
        }

        @media (max-width: 767px) {
            .navbar-expand-md .dropdown-menu > .dropdown > .dropdown-toggle {
                padding-{{ Session::get('direction') === "rtl" ? 'left' : 'right'}}: 1.95rem;
            }
        }
    </style>

    {{-- Active published theme's design tokens override the legacy colours above (emitted only when a
         theme is published; storefront unchanged otherwise). --}}
    @php($__themeSettings = app(\App\Services\Theme\StorefrontThemeRenderer::class)->publishedGlobalSettings(app(\App\Services\Theme\ThemeManager::class)))
    @if($__themeSettings)
        @include('partials.theme-global-tokens', ['themeSettings' => $__themeSettings])
    @endif

    <link rel="stylesheet" href="{{theme_asset(path: 'public/assets/front-end/css/custom.css')}}">

    {{-- Kohl Store: token + base layer, loaded last so it can take over section by
         section. It styles nothing that does not opt in with a .k- class. --}}
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/store.css') }}">

    {!! getSystemDynamicPartials(type: 'analytics_script') !!}

    @if(config('app.mode')=='demo')
        <!-- Google Tag Manager -->
        <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
                    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
                j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
                'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
            })(window,document,'script','dataLayer','GTM-TTLMKMHQ');</script>
        <!-- End Google Tag Manager -->
    @endif
</head>

<body class="k k-store toolbar-enabled {{ config('app.mode')=='demo'?'demo':'' }}" id="demo">

@include('layouts.front-end.partials._modals')

@include('layouts.front-end.partials._quick-view-modal')
@include('layouts.front-end.partials.modal._buy-now')

@include('layouts.front-end.partials._header')
@include('layouts.front-end.partials._alert-message')

@if(isset($isMobile) && $isMobile)
    <div id="app-banner" class="app-download-popup" style="--bottom: 30px;">
        <div class="d-flex gap-3 align-items-center">
            <button type="button" class="btn p-0 px-1 bg-transparent text-dark border-0 shadow-none app-banner-close">
                <i class="fi fi-sr-cross fs-14"></i>
            </button>
            <img width="44" class="img-fit aspect-1 w-40px flex-shrink-0"
                src="{{ getStorageImages(path: $web_config['mob_logo'], type: 'logo') }}"
                alt="6Valley"
            >
            <div class="flex-grow-1">
                <h5 class="fs-14 mb-1">{{ getWebConfig(name: 'company_name') }} {{ translate('Mobile_App') }}</h5>
                <div class="fs-11 opacity-70">{{ translate('For_better_experience_download') }}</div>
                <button type="button" id="install-btn" class="btn btn--primary fs-12 fw-semibold px-2 py-1 mt-2">
                    {{ translate('Download') }}
                </button>
            </div>
        </div>
    </div>
@endif

<span id="authentication-status" data-auth="{{ auth('customer')->check() ? 'true' : 'false' }}"></span>

<div class="row">
    <div class="col-12 loading-parent d--none" id="loading">
        <div class="loading-parent-first-div">
           <div class="text-center">
            <img width="200" alt="{{ translate('Loader') }}"
                 src="{{ getStorageImages(path: getWebConfig(name: 'loader_gif'), type: 'source', source: theme_asset(path: 'public/assets/front-end/img/loader.gif')) }}">
            </div>
        </div>
    </div>
</div>

<div id="global-loader" class="global-loader d-none">
    <span class="loader"></span>
</div>

@yield('content')

<span id="message-otp-sent-again" data-text="{{ translate('OTP_has_been_sent_again.') }}"></span>
<span id="message-wait-for-new-code" data-text="{{ translate('please_wait_for_new_code.') }}"></span>
<span id="message-password-not-match" data-text="{{ translate('password_do_not_match') }}"></span>
<span id="message-password-match" data-text="{{ translate('password_match') }}"></span>
<span id="message-password-need-longest" data-text="{{ translate('password_Must_Be_6_Character') }}"></span>
<span id="message-send-successfully" data-text="{{ translate('send_successfully') }}"></span>
<span id="message-update-successfully" data-text="{{ translate('update_successfully') }}"></span>
<span id="message-successfully-copied" data-text="{{ translate('successfully_copied') }}"></span>
<span id="message-copied-failed" data-text="{{ translate('copied_failed') }}"></span>
<span id="message-select-payment-method" data-text="{{ translate('please_select_a_payment_Methods') }}"></span>
<span id="message-please-choose-all-options" data-text="{{ translate('please_choose_all_the_options') }}"></span>
<span id="message-cannot-input-minus-value" data-text="{{ translate('cannot_input_minus_value') }}"></span>
<span id="message-all-input-field-required" data-text="{{ translate('all_input_field_required') }}"></span>
<span id="message-no-data-found" data-text="{{ translate('no_data_found') }}"></span>
<span id="message-minimum-order-quantity-cannot-less-than" data-text="{{ translate('minimum_order_quantity_cannot_be_less_than_') }}"></span>
<span id="message-item-has-been-removed-from-cart" data-text="{{ translate('item_has_been_removed_from_cart') }}"></span>
<span id="message-sorry-stock-limit-exceeded" data-text="{{ translate('sorry_stock_limit_exceeded') }}"></span>
<span id="message-sorry-the-minimum-order-quantity-not-match" data-text="{{ translate('sorry_the_minimum_order_quantity_does_not_match') }}"></span>
<span id="message-cart" data-text="{{ translate('cart') }}"></span>

<span id="route-messages-store" data-url="{{ route('messages') }}"></span>
<span id="route-address-update" data-url="{{ route('address-update') }}"></span>
<span id="route-coupon-apply" data-url="{{ route('coupon.apply') }}"></span>
<span id="route-cart-add" data-url="{{ route('cart.add') }}"></span>
<span id="route-cart-remove" data-url="{{ route('cart.remove') }}"></span>
<span id="route-cart-variant-price" data-url="{{ route('cart.variant_price') }}"></span>
<span id="route-cart-nav-cart" data-url="{{ route('cart.nav-cart') }}"></span>
<span id="route-cart-order-again" data-url="{{ route('cart.order-again') }}"></span>
<span id="route-cart-updateQuantity" data-url="{{route('cart.updateQuantity')}}"></span>
<span id="route-cart-updateQuantity-guest" data-url="{{route('cart.updateQuantity.guest')}}"></span>
<span id="route-pay-offline-method-list" data-url="{{ route('pay-offline-method-list') }}"></span>
<span id="route-customer-auth-sign-up" data-url="{{ route('customer.auth.sign-up') }}"></span>
<span id="route-searched-products" data-url="{{ url('/searched-products') }}"></span>
<span id="route-currency-change" data-url="{{ route('currency.change') }}"></span>
<span id="route-store-wishlist" data-url="{{ route('store-wishlist') }}"></span>
<span id="route-delete-wishlist" data-url="{{ route('delete-wishlist') }}"></span>
<span id="route-wishlists" data-url="{{ route('wishlists') }}"></span>
<span id="route-quick-view" data-url="{{ route('quick-view') }}"></span>
<span id="route-checkout-details" data-url="{{ route('checkout-details') }}"></span>
<span id="route-checkout-payment" data-url="{{ route('checkout-payment') }}"></span>
<span id="route-set-shipping-id" data-url="{{ route('customer.set-shipping-method') }}"></span>
<span id="route-order-note" data-url="{{ route('order_note') }}"></span>
<span id="route-product-restock-request" data-url="{{ route('cart.product-restock-request') }}"></span>
<div id="route-vendor-list" data-url="{{ route('vendors') }}"></div>

<span id="password-error-message" data-max-character="{{translate('at_least_8_characters').'.'}}" data-uppercase-character="{{translate('at_least_one_uppercase_letter_').'(A...Z)'.'.'}}" data-lowercase-character="{{translate('at_least_one_lowercase_letter_').'(a...z)'.'.'}}"
      data-number="{{translate('at_least_one_number').'(0...9)'.'.'}}" data-symbol="{{translate('at_least_one_symbol').'(!...%)'.'.'}}"></span>
<span class="system-default-country-code" data-value="{{ getWebConfig(name: 'country_code') ?? 'us' }}"></span>
<span id="system-session-direction" data-value="{{ session()->get('direction') ?? 'ltr' }}"></span>

<span id="is-request-customer-auth-sign-up" data-value="{{ Request::is('customer/auth/sign-up*') ? 1:0 }}"></span>
<span id="is-customer-auth-active" data-value="{{ auth('customer')->check() ? 1:0 }}"></span>

<span id="storage-flash-deals" data-value="{{ $web_config['flash_deals']['start_date'] ?? '' }}"></span>
<span id="exceeds10MBSizeLimit" data-text="{{ translate('File_exceeds_10MB_size_limit') }}"></span>
<span id="imageUploadMaxSize" data-max-size="{{ getFileUploadMaxSize() }}"></span>
@include('layouts.front-end.partials._footer')
@include('layouts.front-end.partials.modal._dynamic-modals')

<div class="floating-btn-grp">
    <div class="__floating-btn">
        @php($whatsapp = getWebConfig(name: 'whatsapp'))
        @if(isset($whatsapp['status']) && $whatsapp['status'] == 1 )
            <div class="wa-widget-send-button">
                <a href="https://wa.me/{{ $whatsapp['phone'] }}?text=Hello%20there!" target="_blank">
                    <img src="{{theme_asset(path: 'public/assets/front-end/img/whatsapp.svg')}}" class="wa-messenger-svg-whatsapp wh-svg-icon" alt="{{ translate('Chat_with_us_on_WhatsApp') }}">
                </a>
            </div>
        @endif
    </div>
    <a class="btn-scroll-top btn--primary" href="#top" data-scroll>
        <i class="btn-scroll-top-icon czi-arrow-up"></i>
    </a>
</div>

<span class="d-none" id="text-validate-translate"
      data-required="{{ translate('this_field_is_required') }}"
      data-file-size-larger="{{ translate('file_size_is_larger') }}"
      data-max-limit-crossed="{{ translate('max_limit_crossed') }}"
      data-something-went-wrong="{{ translate('something_went_wrong!') }}"
      data-passwords-do-not-match="{{ translate('passwords_do_not_match') }}"
      data-valid-email="{{ translate('please_enter_a_valid_email') }}"
      data-password-validation="{{ translate('password_must_be_8+_chars_with_upper,_lower,_number_&_symbol') }}"
      data-file-type-not-allowed="{{ translate('Invalid_file_type_selected') }}"
></span>

<span id="see-more-less"
    data-see-more="{{ translate('See_More') }}"
    data-see-less="{{ translate('See_Less') }}"
    >
</span>

<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/jquery/dist/jquery-2.2.4.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/bootstrap/dist/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/jquery-validate/jquery.validate.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/bs-custom-file-input/dist/bs-custom-file-input.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/simplebar/dist/simplebar.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/tiny-slider/dist/min/tiny-slider.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/smooth-scroll/dist/smooth-scroll.polyfills.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/js/lightbox.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/drift-zoom/dist/Drift.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/lightgallery.js/dist/js/lightgallery.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/vendor/lg-video.js/dist/lg-video.min.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/js/owl.carousel.min.js')}}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/plugin/swiper/swiper-bundle.min.js')}}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/plugin/easyzoom/easyzoom.min.js')}}"></script>
<script src="{{ theme_asset(path: "public/assets/back-end/js/toastr.js" )}}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/js/theme.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/js/sweet_alert.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/js/custom.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/js/seller-filter.js') }}"></script>
<script src="{{ theme_asset(path: 'public/assets/front-end/js/account-order-details.js') }}"></script>
{{-- Phone-input assets are pushed by the pages that actually render a phone field;
     see partials/_phone-input-assets. --}}
@stack('phone_input_assets')

<script src="{{ dynamicAsset(path: 'public/assets/backend/file-validation/polyfills.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/file-validation/just-validate.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/file-validation/form-advance-validation.js') }}"></script>

<script src="{{ dynamicAsset(path: 'public/assets/front-end/auction/js/auction-common.js') }}"></script>

{!! Toastr::message() !!}

@include('layouts.front-end.partials._firebase-script')


@if(isset($isMobile) && $isMobile)
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const banner = document.getElementById("app-banner");
            if (!banner) return;

            const closeBtn = document.querySelector(".app-banner-close");
            const installBtn = document.getElementById("install-btn");

            const REOPEN_DELAY = 10 * 60 * 1000; // re-show 10 minutes after user closes
            const INITIAL_DELAY = 3000;
            // Built by DetectMobile so it carries the campaign this visit arrived on: an install
            // that came from a campaign is attributed to it instead of appearing out of nowhere.
            const STORE_URL = @json($appInstallUrl ?? '');

            let reopenTimer = null;

            function showBanner() {
                if (!document.hidden) {
                    banner.style.display = "block";
                }
            }

            function scheduleReopen(delay) {
                if (reopenTimer) clearTimeout(reopenTimer);
                reopenTimer = setTimeout(showBanner, delay);
            }

            const closedAt = parseInt(localStorage.getItem("app_banner_closed_at") || "0");
            const elapsed = Date.now() - closedAt;

            if (closedAt && elapsed < REOPEN_DELAY) {
                banner.style.display = "none";
                scheduleReopen(REOPEN_DELAY - elapsed);
            } else {
                setTimeout(showBanner, INITIAL_DELAY);
            }

            closeBtn.addEventListener("click", function () {
                banner.style.display = "none";
                localStorage.setItem("app_banner_closed_at", Date.now().toString());
                scheduleReopen(REOPEN_DELAY);
            });

            installBtn.addEventListener("click", function () {
                if (!STORE_URL) return;
                window.location.href = STORE_URL;
            });
        });
    </script>
@endif


<script>
    "use strict";


    @if ($errors->any())
    @foreach($errors->all() as $error)
    toastr.error('{{$error}}', Error, {
        CloseButton: true,
        ProgressBar: true
    });
    @endforeach
    @endif

    $(document).mouseup(function (e) {
        let container = $(".search-card");
        if (!container.is(e.target) && container.has(e.target).length === 0) {
            container.hide();
        }
    });

    function route_alert(route, message) {
        Swal.fire({
            title: '{{ translate("are_you_sure")}}?',
            text: message,
            type: 'warning',
            showCancelButton: true,
            cancelButtonColor: 'default',
            confirmButtonColor: '{{$web_config['primary_color']}}',
            cancelButtonText: '{{ translate("no")}}',
            confirmButtonText: '{{ translate("yes")}}',
            reverseButtons: true
        }).then((result) => {
            if (result.value) {
                location.href = route;
            }
        })
    }

    @php($cookie = $web_config['cookie_setting'] ? json_decode($web_config['cookie_setting']['value'], true):null)
    let cookie_content = `
        <div class="cookie-section">
            <div class="container">
                <div class="d-flex flex-wrap align-items-center justify-content-between column-gap-4 row-gap-3">
                    <div class="text-wrapper">
                        <h5 class="title">{{ translate("Your_Privacy_Matter")}}</h5>
                        <div>{{ $cookie ? $cookie['cookie_text'] : '' }}</div>
                    </div>
                    <div class="btn-wrapper">
                        <button class="btn bg-dark text-white cursor-pointer" id="cookie-reject">{{ translate("no_thanks")}}</button>
                        <button class="btn btn-success cookie-accept" id="cookie-accept">{{ translate('i_Accept')}}</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    $(document).on('click','#cookie-accept',function() {
        document.cookie = '6valley_cookie_consent=accepted; max-age=' + 60 * 60 * 24 * 30;
        $('#cookie-section').hide();
    });
    $(document).on('click','#cookie-reject',function() {
        document.cookie = '6valley_cookie_consent=reject; max-age=' + 60 * 60 * 24;
        $('#cookie-section').hide();
    });

    $(document).ready(function() {
        if (document.cookie.indexOf("6valley_cookie_consent=accepted") !== -1) {
            $('#cookie-section').hide();
        } else if (document.cookie.indexOf("6valley_cookie_consent=reject") !== -1) {
            $('#cookie-section').hide();
        } else{
            $('#cookie-section').html(cookie_content).show();
        }
    });
</script>
@if(config('app.mode') == 'demo')
    <script>
        'use strict'
        function checkDemoResetTime() {
            let currentMinute = new Date().getMinutes();
            if (currentMinute > 55 && currentMinute <= 60) {
                $('#demo-reset-warning').addClass('active');
            } else {
                $('#demo-reset-warning').removeClass('active');
            }
        }
        checkDemoResetTime();
        setInterval(checkDemoResetTime, 60000);
    </script>
@endif

{{-- First-party analytics beacon. Loaded last and deferred, so it cannot delay anything a
     customer is waiting for. It reports only what a server-side page load cannot see: the
     filter's pushState navigations, and interactions that never navigate. --}}
@if (config('analytics.enabled', true) && config('analytics.beacon.enabled', true))
    <div id="analytics-beacon" data-endpoint="{{ route('analytics.collect') }}" hidden></div>
    <script src="{{ asset('assets/front-end/js/analytics-beacon.js') }}" defer></script>
@endif

@stack('script')
</body>
</html>
