{{-- Test-only stand-in for the storefront shell, so a page's own markup can be rendered without the shell's database. --}}
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ session('direction', 'ltr') }}">
<head><title>@yield('title')</title>@stack('css_or_js')</head>
<body>
@yield('content')
@stack('phone_input_assets')
@stack('script')
</body>
</html>
