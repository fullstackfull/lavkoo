<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ session()->get('direction') ?? 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="_token" content="{{ csrf_token() }}">
    <meta property="og:site_name" content="{{ $web_config['company_name'] }}" />

    <meta name="google-site-verification" content="{{ getWebConfig('google_search_console_code') }}">
    <meta name="msvalidate.01" content="{{ getWebConfig('bing_webmaster_code') }}">
    <meta name="baidu-site-verification" content="{{ getWebConfig('baidu_webmaster_code') }}">
    <meta name="yandex-verification" content="{{ getWebConfig('yandex_webmaster_code') }}">

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $web_config['fav_icon']['path'] }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ $web_config['fav_icon']['path'] }}">

    <title>@yield('title')</title>

    @stack('css_or_js')

    @include("auction.layouts.partials._style-assets")
</head>
<body>

    @include("auction.layouts.partials._header")

    @yield('content')

    @include("auction.web-views.partials._image-modal")

    @include("auction.layouts.partials._footer")
    @include('layouts.front-end.partials.modal._dynamic-modals')

    <div class="floating-btn-grp">
        <a class="btn-scroll-top btn--primary" href="#top" data-scroll>
            <i class="btn-scroll-top-icon fs-12 fi fi-rr-angle-small-up"></i>
        </a>
    </div>

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

    @include("auction.layouts.partials._script-assets")

    @stack('script')
</body>
</html>
