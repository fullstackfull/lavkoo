<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/css/bootstrap.min.css') }}"/>
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/font-awesome.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-regular-rounded.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-solid-rounded.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-brands.css') }}">
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/css/roboto-font.css') }}">
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/css/open-sans-font.css') }}"/>
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/owl-carousel/owl.carousel.min.css') }}"/>
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/swiper/swiper-bundle.min.css') }}"/>
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/intl-tel-input/css/intlTelInput.min.css') }}"/>
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/quill-editor/quill-editor.css') }}"/>
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/plugin/daterangepicker/daterangepicker.css') }}"/>
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/css/select2.min.css') }}"/>
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/product-add-auto-fill.css') }}">

<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/back-end/css/toastr.css') }}"/>
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/sweetalert2/sweetalert2.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/sweetalert2/sweetalert2-custom.css') }}">

<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/scss/helpers.css') }}"/>
<link rel="stylesheet" href="{{ theme_asset(path: 'public/assets/front-end/auction/scss/auction.css') }}"/>

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

    a[href^="mailto:"]::after,
    a[href^="tel:"]::after {
        content: ''; /* Empty content */
        display: none; /* Hide the tooltip */
    }
</style>

@include(VIEW_FILE_NAMES['robots_meta_content_partials'])

{!! ToastMagic::styles() !!}

{!! getSystemDynamicPartials(type: 'analytics_script') !!}
