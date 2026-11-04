<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/font-awesome.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/bootstrap.min.css') }}">
    {!! ToastMagic::styles() !!}
</head>
<body>
    <div class="flex-center position-ref full-height">
        <div class="code">
            @yield('code')
        </div>

        <div class="message">
            @yield('message')
        </div>
    </div>

    {!! ToastMagic::scripts() !!}
</body>
</html>
