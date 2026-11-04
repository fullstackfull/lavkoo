@php
    use App\Services\SellerCenter\Shell;

    $scDirection = Shell::direction();
    $scLocale = Shell::locale();
    $scDensity = Shell::density();
@endphp
<!DOCTYPE html>
<html lang="{{ $scLocale }}" dir="{{ $scDirection }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', translate('seller_center'))</title>
    <link rel="shortcut icon" href="{{ getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type: 'backend-logo') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Inter for Latin, Cairo for Arabic — the same scale in both (handoff 03 §5, 10 B4). --}}
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Cairo:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/seller-center/css/tokens.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/seller-center/css/sc.css') }}">
    @stack('css')
</head>
{{-- `dir`, `lang` and the density are set once on the app root and read by every rule below. --}}
<body class="sc" lang="{{ $scLocale }}" dir="{{ $scDirection }}" data-density="{{ $scDensity }}"
      @if ($scLocale === 'ar') style="--font-heading:'Cairo',system-ui,sans-serif;--font-body:'Cairo',system-ui,sans-serif" @endif>

@include('layouts.seller.partials._topbar')

<div class="sc-body">
    @include('layouts.seller.partials._rail')
    @include('layouts.seller.partials._panel')

    <main class="sc-main" id="sc-main" role="main">
        @yield('content')
    </main>
</div>

@include('layouts.seller.partials._mobile-nav')
@include('layouts.seller.partials._palette')
@include('layouts.seller.partials._notifications')

<div class="sc-toasts" id="sc-toasts" aria-live="polite" aria-atomic="true">
    @foreach (['success' => 'check-circle', 'error' => 'x-circle', 'warning' => 'warning', 'info' => 'info'] as $type => $glyph)
        @if (session($type))
            <div class="sc-toast sc-toast--{{ $type }}">
                <span class="sc-toast__glyph"><x-sc.icon :name="$glyph" :size="14" /></span>
                <div class="sc-toast__body">{{ session($type) }}</div>
            </div>
        @endif
    @endforeach
</div>

<script>
    window.scConfig = {
        densityUrl: @json(route('seller.preferences.density')),
        directionUrl: @json(route('seller.preferences.direction')),
        searchUrl: @json(route('seller.search')),
        csrf: @json(csrf_token()),
        rtl: @json(Shell::isRtl()),
        strings: {
            noMatch: @json(translate('no_match_for')),
            searchHint: @json(translate('orders_and_shipments_search_by_full_reference_try_an_order_number_a_tracking_code_or_an_sku')),
            searchUnavailable: @json(translate('search_is_unavailable_retry')),
            selectionCleared: @json(translate('selection_cleared_filters_changed')),
            seeAll: @json(translate('see_all')),
        },
    };
</script>
<script src="{{ dynamicAsset(path: 'public/assets/seller-center/js/sc.js') }}" defer></script>
@stack('script')
</body>
</html>
