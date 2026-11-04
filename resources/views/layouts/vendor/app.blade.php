<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ Session::get('direction') }}">

<head>
    <meta charset="utf-8">
    <meta name="_token" content="{{ csrf_token() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="robots" content="nofollow, noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">

    <title>@yield('title')</title>
    <link rel="shortcut icon" href="{{ getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type: 'backend-logo') }}">

    @include("layouts.vendor.partials._style-partials")

    {!! ToastMagic::styles() !!}

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor-v2.css') }}">
    {{-- Kohl console tokens + components, same layer the admin shell loads. --}}
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/console.css') }}">

    @stack('css_or_js')
</head>

<body class="k k-console footer-offset {{ config('app.mode')=='demo'?'demo':'' }} v2-active" id="{{ config('app.mode')=='demo'?'demo':'' }}">

@if(config('app.mode')=='demo')
    <!-- Google Tag Manager (noscript) -->
    <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TTLMKMHQ"
                      height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
    <!-- End Google Tag Manager (noscript) -->
@endif
<div class="row">
    <div class="col-12 position-fixed z-9999 mt-10rem">
        <div id="loading" class="d--none">
            <div id="loader"></div>
        </div>
    </div>
</div>
@include('layouts.vendor.partials.v2._body')

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

    @include("layouts.vendor.partials._modals")
    @include("layouts.vendor.partials._toggle-modal")
    @include("layouts.vendor.partials._sign-out-modal")
    @include("layouts.vendor.partials._alert-message")

    @include("layouts.vendor.partials._translator-for-js")
    @include("layouts.vendor.partials._translated-message-container")
    @include("layouts.vendor.partials._script-partials")

    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor-v2.js') }}"></script>
    <script src="{{ dynamicAsset(path: 'public/assets/back-end/js/v2-pjax.js') }}"></script>

    @stack("script")
    @stack("script_2")

    @if(config('app.mode')=='demo')
        <script src="{{ dynamicAsset(path: 'public/js/demo.js')}}"></script>
    @endif

    <script>
        $('.notification-data-view').on('click',function (){
            let id= $(this).data('id');
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                }
            });
            $.post({
                url: "{{route('vendor.notification.index')}}",
                data: {
                    _token: '{{csrf_token()}}',
                    id: id,
                },
                beforeSend: function () {
                },
                success: function (data) {
                    $('.notification_data_new_badge'+id).fadeOut();
                    $('#NotificationModalContent').empty().html(data.view);
                    $('#NotificationModal').modal('show');
                    let notificationDataCount = $('.notification_data_new_count');
                    let notificationCount = parseInt(data.notification_count);
                    notificationCount === 0 ? notificationDataCount.fadeOut() : notificationDataCount.html(notificationCount);
                },
                complete: function () {
                },
            });
        })
        if (/MSIE \d|Trident.*rv:/.test(navigator.userAgent)) document.write(
            '<script src="{{ dynamicAsset(path: 'public/assets/back-end') }}/vendor/babel-polyfill/polyfill.min.js"><\/script>');
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
    
     <script>
       if ($('html').attr('dir') === 'rtl') {

    $('[data-toggle="tooltip"]').each(function () {

        let placement = $(this).attr('data-placement');

        if (placement === 'right') {
            $(this).attr('data-placement', 'left');
        } else if (placement === 'left') {
            $(this).attr('data-placement', 'right');
        }
    });
}

$('[data-toggle="tooltip"]').tooltip();
    </script>
</body>

</html>
