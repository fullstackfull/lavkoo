{{-- Test-only stand-in for the vendor shell, so a page's own markup can be rendered without the shell's database. --}}
<!doctype html>
<html lang="en">
<head><title>@yield('title')</title>@stack('css_or_js')</head>
<body>
@yield('content')
@stack('script')
@stack('script_2')
</body>
</html>
