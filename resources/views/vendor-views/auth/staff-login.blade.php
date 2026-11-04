<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if(session()->get('direction') === 'rtl' || in_array(app()->getLocale(), ['ar','sy','fa','ur'])) dir="rtl" @endif>

<head>
    <meta charset="utf-8">
    <meta name="robots" content="nofollow, noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ translate('staff_login') }}</title>
    <link rel="shortcut icon" href="{{ getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type: 'backend-logo') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/vendor/icon-set/style.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/style.css') }}">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f6fa; }
        .staff-login-card { width: 100%; max-width: 420px; margin: 1rem; }
    </style>
</head>

<body>
    <div class="staff-login-card">
        <div class="card shadow-sm">
            <div class="card-body p-4 p-sm-5">
                <div class="text-center mb-4">
                    <img src="{{ getStorageImages(path: getWebConfig(name: 'company_web_logo'), type: 'backend-logo') }}"
                         alt="{{ translate('logo') }}" style="max-height: 48px;" class="mb-3">
                    <h1 class="h4 mb-1">{{ translate('staff_login') }}</h1>
                    <p class="text-muted fs-13 mb-0">{{ translate('sign_in_with_the_credentials_your_shop_owner_gave_you') }}</p>
                </div>

                <form action="{{ route('vendor.staff-auth.login.submit') }}" method="post">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="staff-email">{{ translate('email') }}</label>
                        <input type="email" id="staff-email" name="email" class="form-control" required
                               value="{{ old('email') }}" autocomplete="username">
                        @error('email') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="staff-password">{{ translate('password') }}</label>
                        <input type="password" id="staff-password" name="password" class="form-control" required
                               autocomplete="current-password">
                        @error('password') <small class="text-danger">{{ $message }}</small> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary w-100">{{ translate('login') }}</button>
                </form>

                <div class="text-center mt-4">
                    <a href="{{ route('vendor.auth.login') }}" class="fs-13 text-muted">{{ translate('shop_owner_login') }}</a>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
