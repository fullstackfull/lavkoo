{{-- Test-only stand-in for the admin shell, so a page's own markup can be rendered without the shell's database. --}}
<!doctype html>
<html lang="en">
<head><title>@yield('title')</title></head>
<body>
@yield('content')
@stack('script')
</body>
</html>
