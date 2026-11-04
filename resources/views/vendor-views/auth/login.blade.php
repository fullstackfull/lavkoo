@php
    use App\Enums\DemoConstant;
    $eCommerceLogo = getWebConfig(name: 'company_web_logo');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="robots" content="nofollow, noindex">
    <meta name="_token" content="{{ csrf_token() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <title>{{ translate('vendor_Login') }}</title>

    <link rel="shortcut icon"
        href="{{ getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type: 'backend-logo') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/back-end/css/google-fonts.css') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/backend/libs/fonts/inter/inter.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor.min.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/back-end/css/bootstrap.min.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/back-end/vendor/icon-set/style.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/back-end/css/theme.minc619.css?v=1.0') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/back-end/css/style.css') }}">

    <style>
        :root {
            --sy-primary: {{ $web_config['primary_color'] ?? '#7041D7' }};
            --sy-primary-dark: #5630b5;
            --sy-blue: #2f67cc;
            --sy-purple: #9a45dc;

            --sy-text: #27232f;
            --sy-muted: #77717f;
            --sy-border: #eae4f2;
            --sy-light: #f8f5ff;
            --sy-bg: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            background: #fff;
            color: var(--sy-text);
            font-family: "Inter", Arial, sans-serif;
        }

        .main {
            min-height: 100vh;
            padding: 0 !important;
            margin: 0 !important;
        }

        /* ============================================
           MAIN AUTH LAYOUT
        ============================================ */

        .vendor-auth {
            width: 100%;
            min-height: 100vh;

            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(430px, .85fr);

            background: #fff;
            overflow: hidden;
        }

        /* ============================================
           LEFT SIDE
        ============================================ */

        .vendor-auth-image {
            position: relative;
            min-height: 100vh;

        background: #f7e1ff;

            overflow: hidden;
        }

        .vendor-auth-image::after {
            content: "";
            position: absolute;
            inset: 0;

            background:
                linear-gradient(
                    180deg,
                    rgba(255,255,255,0) 45%,
                    rgba(35,16,65,.08) 100%
                );

            pointer-events: none;
        }

        /* إذا كانت الصورة لا تحتوي الشعار
           يمكنك إظهار هذا البلوك بإزالة d-none */
        .vendor-image-logo {
            position: absolute;
            left: 50px;
            top: 40px;
            z-index: 3;

            display: none;
        }

        .vendor-image-logo img {
            max-width: 240px;
            max-height: 100px;
            object-fit: contain;
        }

        /* ============================================
           RIGHT SIDE
        ============================================ */

        .vendor-auth-panel {
            position: relative;

            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 55px 55px;

            background:
                radial-gradient(
                    circle at 100% 0%,
                    rgba(142, 69, 220, .10),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 0% 100%,
                    rgba(47, 103, 204, .07),
                    transparent 28%
                ),
                #fff;
        }

        .vendor-auth-panel::before {
            content: "";
            position: absolute;
            top: -120px;
            right: -120px;

            width: 280px;
            height: 280px;

            border-radius: 50%;
            background: rgba(120, 66, 211, .04);
        }

        .vendor-auth-panel::after {
            content: "";
            position: absolute;
            left: -70px;
            bottom: -100px;

            width: 220px;
            height: 220px;

            border-radius: 50%;
            background: rgba(47, 103, 204, .04);
        }

        .vendor-login-container {
            width: 100%;
            max-width: 470px;

            position: relative;
            z-index: 2;
        }

        /* ============================================
           LOGO
        ============================================ */

        .vendor-logo {
            margin-bottom: 34px;
        }

        .vendor-logo a {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
        }

        .vendor-logo img {
            width: auto;
            max-width: 235px;
            max-height: 95px;
            object-fit: contain;
        }

        /* ============================================
           HEADER
        ============================================ */

        .vendor-login-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            padding: 7px 13px;
            margin-bottom: 14px;

            border-radius: 50px;

            background: #f4efff;
            color: var(--sy-primary);

            font-size: 12px;
            font-weight: 700;
        }

        .vendor-login-badge::before {
            content: "✦";
            font-size: 12px;
        }

        .vendor-login-heading {
            margin-bottom: 33px;
        }

        .vendor-login-heading h1 {
            margin: 0;

            font-size: 38px;
            line-height: 1.25;

            font-weight: 800;
            letter-spacing: -1px;

            color: #24202a;
        }

        .vendor-login-heading p {
            margin: 10px 0 0;

            font-size: 14px;
            line-height: 1.7;

            color: var(--sy-muted);
        }

        .vendor-login-heading strong {
            color: var(--sy-primary);
            font-weight: 700;
        }

        /* ============================================
           FORM
        ============================================ */

        .vendor-form-group {
            margin-bottom: 21px;
        }

        .vendor-form-label {
            width: 100%;

            display: flex;
            align-items: center;
            justify-content: space-between;

            margin-bottom: 8px;

            color: #4f4957;

            font-size: 13px;
            font-weight: 700;
        }

        .vendor-form-label a {
            color: var(--sy-primary);
            text-decoration: none;
            font-weight: 600;

            transition: .2s ease;
        }

        .vendor-form-label a:hover {
            color: var(--sy-primary-dark);
        }

        .vendor-input-wrap {
            position: relative;
        }

        .vendor-input-icon {
            position: absolute;

            left: 17px;
            top: 50%;

            transform: translateY(-50%);

            font-size: 18px;
            color: #9c94a4;

            z-index: 4;
        }

        .vendor-control {
            width: 100% !important;
            height: 58px !important;

            padding-left: 50px !important;
            padding-right: 50px !important;

            border: 1px solid var(--sy-border) !important;
            border-radius: 14px !important;

            background: #fcfbfe !important;
            color: #2c2831 !important;

            font-size: 14px !important;

            outline: none !important;
            box-shadow: none !important;

            transition:
                border-color .2s ease,
                box-shadow .2s ease,
                background .2s ease;
        }

        .vendor-control:hover {
            border-color: #dacdec !important;
            background: #fff !important;
        }

        .vendor-control:focus {
            background: #fff !important;

            border-color: var(--sy-primary) !important;

            box-shadow:
                0 0 0 4px rgba(112, 65, 215, .08) !important;
        }

        .vendor-control::placeholder {
            color: #b6b0bb;
        }

        /* ============================================
           PASSWORD
        ============================================ */

        .vendor-password-wrap {
            position: relative;
        }

        .vendor-password-toggle {
            position: absolute !important;

            right: 10px !important;
            top: 50% !important;

            transform: translateY(-50%) !important;

            width: 40px;
            height: 40px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 10px;

            background: transparent;
            z-index: 10;
        }

        .vendor-password-toggle .input-group-text {
            width: 40px;
            height: 40px;

            padding: 0 !important;

            display: flex;
            align-items: center;
            justify-content: center;

            border: 0 !important;

            color: #807988 !important;
            background: transparent !important;

            border-radius: 10px !important;

            transition: .2s ease;
        }

        .vendor-password-toggle .input-group-text:hover {
            color: var(--sy-primary) !important;
            background: var(--sy-light) !important;
        }

        /* ============================================
           REMEMBER + REGISTER
        ============================================ */

        .vendor-login-options {
            margin-top: 2px;
            margin-bottom: 5px;

            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 15px;
        }

        .vendor-remember {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .vendor-remember input {
            width: 17px;
            height: 17px;

            accent-color: var(--sy-primary);
            cursor: pointer;
        }

        .vendor-remember label {
            margin: 0 !important;

            font-size: 13px;
            color: #726b78;

            cursor: pointer;
        }

        .vendor-register {
            font-size: 13px;
            font-weight: 700;

            color: var(--sy-primary) !important;

            text-decoration: none !important;
        }

        .vendor-register:hover {
            color: var(--sy-primary-dark) !important;
        }

        /* ============================================
           LOGIN BUTTON
        ============================================ */

        .vendor-login-btn {
            width: 100%;
            min-height: 58px;

            margin-top: 20px;

            display: flex;
            align-items: center;
            justify-content: center;

            gap: 9px;

            border: 0 !important;
            border-radius: 14px !important;

            color: #fff !important;

            font-size: 15px;
            font-weight: 700;

            background:
                linear-gradient(
                    110deg,
                    var(--sy-blue) 0%,
                    var(--sy-primary) 55%,
                    var(--sy-purple) 100%
                ) !important;

            box-shadow:
                0 14px 30px rgba(112, 65, 215, .23);

            transition:
                transform .2s ease,
                box-shadow .2s ease,
                opacity .2s ease;
        }

        .vendor-login-btn:hover {
            transform: translateY(-2px);

            box-shadow:
                0 18px 38px rgba(112, 65, 215, .30);

            color: #fff !important;
        }

        .vendor-login-btn:active {
            transform: translateY(0);
        }

        /* ============================================
           REGISTER DIVIDER
        ============================================ */

        .vendor-divider {
            margin: 28px 0 20px;

            display: flex;
            align-items: center;
            gap: 14px;

            color: #aba5b0;

            font-size: 11px;
        }

        .vendor-divider::before,
        .vendor-divider::after {
            content: "";

            height: 1px;
            flex: 1;

            background: #eee9f2;
        }

        .vendor-create-account {
            min-height: 54px;

            display: flex;
            justify-content: center;
            align-items: center;

            border-radius: 14px;
            border: 1px solid #ded4ed;

            color: var(--sy-primary);
            background: #fff;

            font-size: 14px;
            font-weight: 700;

            text-decoration: none !important;

            transition: .2s ease;
        }

        .vendor-create-account:hover {
            color: var(--sy-primary-dark);

            border-color: #cbb9e8;
            background: #faf7ff;

            transform: translateY(-1px);
        }

        /* ============================================
           DEMO DATA
        ============================================ */

        .vendor-demo-box {
            margin-top: 25px;

            padding: 15px 16px;

            border: 1px dashed #d7c8ec;
            border-radius: 14px;

            background: #faf7ff;
        }

        .vendor-demo-title {
            margin-bottom: 9px;

            font-size: 12px;
            font-weight: 700;

            color: var(--sy-primary);
        }

        .vendor-demo-value {
            display: block;

            font-size: 12px;
            line-height: 1.7;

            color: #6f6876;
        }

        .vendor-copy-btn {
            width: 42px;
            height: 42px;

            padding: 0 !important;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border: 0 !important;
            border-radius: 10px !important;

            color: #fff !important;
            background: var(--sy-primary) !important;
        }

        /* ============================================
           FOOTER
        ============================================ */

        .vendor-auth-footer {
            margin-top: 30px;

            text-align: center;

            color: #aaa4ae;

            font-size: 11px;
        }

        /* ============================================
           RESPONSIVE
        ============================================ */

        @media (max-width: 1100px) {

            .vendor-auth {
                grid-template-columns: 1fr 1fr;
            }

            .vendor-auth-panel {
                padding: 45px 35px;
            }

        }

        @media (max-width: 850px) {

            .vendor-auth {
                display: flex;
                flex-direction: column;
            }

            .vendor-auth-image {
                min-height: 310px;
                height: 38vh;
                max-height: 430px;

                background-position: center center;
            }

            .vendor-auth-panel {
                min-height: auto;

                padding:
                    45px
                    25px
                    55px;
            }

            .vendor-login-container {
                max-width: 520px;
            }

            .vendor-logo {
                text-align: center;
            }

            .vendor-login-heading {
                text-align: center;
            }

            .vendor-login-badge {
                margin-left: auto;
                margin-right: auto;
            }

        }

        @media (max-width: 576px) {

            .vendor-auth-image {
                height: 255px;
                min-height: 255px;

                background-position: center center;
            }

            .vendor-auth-panel {
                padding:
                    32px
                    18px
                    45px;
            }

            .vendor-logo {
                margin-bottom: 25px;
            }

            .vendor-logo img {
                max-width: 190px;
            }

            .vendor-login-heading {
                margin-bottom: 27px;
            }

            .vendor-login-heading h1 {
                font-size: 30px;
            }

            .vendor-login-options {
                align-items: flex-start;
                flex-direction: column;
            }

        }
    </style>

    {!! ToastMagic::styles() !!}

</head>

<body>

<main id="content" role="main" class="main">

    <div class="vendor-auth">

        {{-- ==========================================
             LEFT IMAGE
        =========================================== --}}
        <section class="vendor-auth-image">

            {{-- إذا أردت إظهار اللوغو فوق الصورة
                 احذف display:none من CSS --}}
            <div class="vendor-image-logo">
                <a href="{{ route('home') }}">
                    <img
                        src="{{ getStorageImages(path: $eCommerceLogo, type: 'backend-logo') }}"
                        alt="SY Cosmetics & Vitamins">
                </a>
            </div>

        </section>


        {{-- ==========================================
             RIGHT LOGIN PANEL
        =========================================== --}}
        <section class="vendor-auth-panel">

            <div class="vendor-login-container">

                {{-- Logo --}}
                <div class="vendor-logo">

                    <a href="{{ route('home') }}">

                        <img
                            src="{{ getStorageImages(path: $eCommerceLogo, type: 'backend-logo') }}"
                            alt="SY Cosmetics & Vitamins">

                    </a>

                </div>


                {{-- Header --}}
                <div class="vendor-login-heading">

                    <div class="vendor-login-badge">
                        {{ translate('Vendor_Login') }}
                    </div>

                    <h1>
                        {{ translate('sign_in') }}
                    </h1>

                    <p>
                        {{ translate('welcome_back_to') }}
                        <strong>
                            {{ translate('Vendor_Login') }}
                        </strong>
                    </p>

                </div>


                {{-- ==================================
                     FORM
                =================================== --}}
                <form
                    action="{{ route('vendor.auth.login') }}"
                    method="post"
                    id="vendor-login-form">

                    @csrf


                    {{-- Email --}}
                    <div class="vendor-form-group js-form-message">

                        <label
                            class="vendor-form-label"
                            for="signingVendorEmail">

                            <span>
                                {{ translate('your_email') }}
                            </span>

                        </label>


                        <div class="vendor-input-wrap">

                            <i class="tio-email vendor-input-icon"></i>

                            <input
                                type="email"
                                class="form-control vendor-control"
                                name="email"
                                id="signingVendorEmail"
                                tabindex="1"
                                placeholder="email@address.com"
                                aria-label="email@address.com"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                required
                                data-msg="Please enter a valid email address.">

                        </div>

                    </div>


                    {{-- Password --}}
                    <div class="vendor-form-group js-form-message">

                        <label
                            class="vendor-form-label"
                            for="signingVendorPassword">

                            <span>
                                {{ translate('password') }}
                            </span>

                            <a href="{{ route('vendor.auth.forgot-password.index') }}">
                                {{ translate('forgot_password') }}
                            </a>

                        </label>


                        <div class="vendor-input-wrap vendor-password-wrap">

                            <i class="tio-lock vendor-input-icon"></i>

                            <input
                                type="password"
                                class="js-toggle-password form-control vendor-control"
                                name="password"
                                id="signingVendorPassword"
                                placeholder="{{ translate('8+_characters_required') }}"
                                aria-label="8+ characters required"
                                autocomplete="current-password"
                                required
                                data-msg="Your password is invalid. Please try again."
                                data-hs-toggle-password-options='{
                                    "target": "#changePassTarget",
                                    "defaultClass": "tio-hidden-outlined",
                                    "showClass": "tio-visible-outlined",
                                    "classChangeTarget": "#changePassIcon"
                                }'>


                            <div
                                id="changePassTarget"
                                class="vendor-password-toggle">

                                <a
                                    class="input-group-text"
                                    href="javascript:void(0)"
                                    aria-label="Show password">

                                    <i
                                        id="changePassIcon"
                                        class="tio-visible-outlined">
                                    </i>

                                </a>

                            </div>

                        </div>

                    </div>


                    {{-- Remember / Register --}}
                    <div class="vendor-login-options">

                        <div class="vendor-remember">

                            <input
                                type="checkbox"
                                id="termsCheckbox"
                                name="remember">

                            <label for="termsCheckbox">
                                {{ translate('remember_me') }}
                            </label>

                        </div>


                        <a
                            href="{{ route('vendor.auth.registration.index') }}"
                            class="vendor-register">

                            {{ translate('Register_New_Account') }}

                        </a>

                    </div>


                    {{-- Submit --}}
                    <button
                        type="submit"
                        id="vendorLoginBtn"
                        class="btn vendor-login-btn">

                        <span>
                            {{ translate('sign_in') }}
                        </span>

                        <i class="tio-arrow-forward"></i>

                    </button>

                </form>


                {{-- ==================================
                     Alternative Registration Button
                =================================== --}}
                <div class="vendor-divider">
                    {{ translate('or') }}
                </div>


                <a
                    href="{{ route('vendor.auth.registration.index') }}"
                    class="vendor-create-account">

                    <span>
                        {{ translate('Register_New_Account') }}
                    </span>

                </a>


                {{-- ==================================
                     DEMO MODE
                =================================== --}}
                @if (config('app.mode') == 'demo')

                    <div class="vendor-demo-box">

                        <div class="vendor-demo-title">
                            {{ translate('demo_login') }}
                        </div>

                        <div class="row align-items-center">

                            <div class="col">

                                <span
                                    id="vendor-email"
                                    class="vendor-demo-value"
                                    data-email="{{ DemoConstant::VENDOR['email'] }}">

                                    {{ translate('email') }}
                                    :
                                    {{ DemoConstant::VENDOR['email'] }}

                                </span>


                                <span
                                    id="vendor-password"
                                    class="vendor-demo-value"
                                    data-password="{{ DemoConstant::VENDOR['password'] }}">

                                    {{ translate('password') }}
                                    :
                                    {{ DemoConstant::VENDOR['password'] }}

                                </span>

                            </div>


                            <div class="col-auto">

                                <button
                                    type="button"
                                    class="btn vendor-copy-btn"
                                    id="copyLoginInfo">

                                    <i class="tio-copy"></i>

                                </button>

                            </div>

                        </div>

                    </div>

                @endif


                {{-- Footer --}}
                <div class="vendor-auth-footer">

                    © {{ date('Y') }} SY Cosmetics & Vitamins

                </div>

            </div>

        </section>

    </div>

</main>


<span
    id="message-copied_success"
    data-text="{{ translate('copied_successfully') }}">
</span>


<script
    src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor.min.js') }}">
</script>

<script
    src="{{ dynamicAsset(path: 'public/assets/back-end/js/theme.min.js') }}">
</script>

<script
    src="{{ dynamicAsset(path: 'public/assets/back-end/js/toastr.js') }}">
</script>

<script
    src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor/login.js') }}">
</script>


{!! ToastMagic::scripts() !!}


@if ($errors->any())

    <script>
        'use strict';

        @foreach ($errors->all() as $error)
            toastMagic.error(@json($error));
        @endforeach

    </script>

@endif


</body>
</html>