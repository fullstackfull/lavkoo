<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/fonts/inter/inter.css') }}">

<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/bootstrap/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/bootstrap/bootstrap.rtl.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-regular-rounded.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-solid-rounded.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/select2/select2.min.css') }}">

<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/css/intlTelInput.css') }}" />
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/tags-input/tags-input.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/swiper/swiper-bundle.min.css') }}"/>
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/filepond/filepond.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/lightbox/lightbox.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/daterangepicker/daterangepicker.css') }}">

<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/sweetalert2/sweetalert2.min.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/sweetalert2/sweetalert2-custom.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/alert-popup.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/rich-text-editor/rich-editor.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/style.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/style-extended.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/custom.css') }}">
<link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/product-add-auto-fill.css') }}">

@if(Request::is('admin/payment/configuration/addon-payment-get*') || Request::is('admin/sms/configuration/addon-sms-get*'))
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/payment-addon.css') }}">
@endif

@if (config('app.mode')=='demo')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/demo.css') }}">
@endif

@if($web_config['panel_sidebar_color'])
    <style>
        :root,
        [data-bs-theme=light] {
            --panel-sidebar-primary: {!! ($web_config['panel_sidebar_color']) !!};
        }
    </style>
@endif
