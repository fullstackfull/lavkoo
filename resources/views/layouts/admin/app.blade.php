<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ session('direction') ?? "ltr" }}">

<head>
    <meta charset="utf-8">
    <meta name="_token" content="{{ csrf_token() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="robots" content="nofollow, noindex">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">

    <title>@yield('title')</title>
    <link rel="shortcut icon"
          href="{{ getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type: 'backend-logo') }}">

    @include("layouts.admin.partials._style-partials")

    {!! ToastMagic::styles() !!}

    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/admin-v2.css') }}">

    {{-- Kohl Console: token + base layer, loaded last so it can take over surface by
         surface. It styles nothing that does not opt in with a .k- class, so adding it
         cannot change an existing page. Built by `npm run prod` from resources/css/kohl. --}}
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/console.css') }}">

    @stack('css_or_js')
</head>

{{-- Resolve the stored colour-scheme choice before first paint, so a dark-mode admin
     never sees a white flash. Inline and synchronous on purpose.

     The console theme is an EXPLICIT choice, not the OS preference: most of this panel is legacy
     Bootstrap CSS with no dark palette, so silently following a dark OS produced the half-dark
     page (and light text on white modals) merchants reported. Absent a stored value we stamp
     light, and the header toggle is the only way into dark — which then stamps all three layers
     at once: Kohl tokens, Bootstrap 5.3's own dark theme, and the v2 shell palette. --}}
<script>(function(){try{var t=localStorage.getItem('k-theme');t=(t==='dark')?'dark':'light';var r=document.documentElement;r.setAttribute('data-k-theme',t);r.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
<body class="k k-console {{ config('app.mode') == 'demo' ? 'demo' : '' }} v2-active">
<script type="text/javascript">
    localStorage.getItem('aside-mini') === 'true' ? document.body.classList.add('aside-mini') : document.body.classList.remove('aside-mini');
</script>

<div class="row">
    <div class="col-12 position-fixed loader-container mt-10rem">
        <div id="loading" class="d--none">
            <div id="loader"></div>
        </div>
    </div>
</div>

@include('layouts.admin.partials.v2._body')

<audio id="myAudio">
    <source src="{{ dynamicAsset(path: 'public/assets/backend/sound/notification.mp3') }}" type="audio/mpeg">
</audio>

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

@include('layouts.admin.partials._translator-for-js')
@include("layouts.admin.partials._translated-message-container")
@include("layouts.admin.partials._routes-list-container")
@include("layouts.admin.partials._script-partials")

<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/admin-v2.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/v2-pjax.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/kohl/js/kohl.js') }}"></script>

@stack('script')


</body>

</html>
