<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/fonts/inter/inter.css') }}">

<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-regular-rounded.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-solid-rounded.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/css/intlTelInput.css') }}" />
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/swiper/swiper-bundle.min.css') }}"/>
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/sweetalert2/sweetalert2.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/sweetalert2/sweetalert2-custom.css') }}">

<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/vendor/icon-set/style.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/theme.minc619.css?v=1.0') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/daterangepicker.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/style.css') }}">
@if (Session::get('direction') === 'rtl')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/menurtl.css')}}">
@endif
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/css/lightbox.css') }}">

<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/custom.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/vendor/css/custom.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/vendor/css/product-add-auto-fill.css') }}">

{{-- Last on purpose: this completes the logical direction utilities Bootstrap 4.5 lacks, and
     corrects the three (me-3, float-start, float-end) that were defined physically despite their
     logical names. Loading it earlier would let bootstrap.min.css and custom.css win. --}}
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor/logical-utilities.css') }}">

<style>
    select {
        background-image: url('{{dynamicAsset(path: 'public/assets/back-end/img/arrow-down.png')}}');
        background-size: 7px;
        background-position: 96% center;
    }
</style>
