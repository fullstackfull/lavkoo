@php use App\Enums\DemoConstant; @endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="_token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="robots" content="nofollow, noindex">

    <title>{{ translate($role) }} | {{ translate('login') }}</title>

    <link rel="shortcut icon"
        href="{{ getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type: 'backend-logo') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/backend/libs/bootstrap/bootstrap.min.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-regular-rounded.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/backend/webfonts/uicons-solid-rounded.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/style.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/style-extended.css') }}">

    <link rel="stylesheet"
        href="{{ dynamicAsset(path: 'public/assets/backend/admin/css/custom.css') }}">

    <style>
        :root {
            --sy-primary: {{ $web_config['primary_color'] ?? '#6c36d8' }};
            --sy-secondary: #315bc9;
            --sy-purple: #8b39dc;
            --sy-light: #f8f5ff;
            --sy-text: #272332;
            --sy-muted: #777181;
            --sy-border: #e9e3f4;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Inter", "Cairo", sans-serif;
            color: var(--sy-text);
            background: #fff;
        }

        .main {
            padding: 0 !important;
            min-height: 100vh;
        }

        /* ==========================
           Main Layout
        ========================== */

        .sy-auth {
            width: 100%;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.12fr .88fr;
            overflow: hidden;
        }

        /* ==========================
           Left Banner
        ========================== */

        .sy-auth-visual {
            position: relative;
            min-height: 100vh;

            /*
             * ضع هنا الصورة التي صممناها:
             *
             * public/assets/back-end/img/sy-login-banner.webp
             */
       background: #f7e1ff;

            overflow: hidden;
        }

        .sy-auth-visual::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(
                    180deg,
                    rgba(255, 255, 255, 0.03) 0%,
                    rgba(68, 31, 124, 0.08) 100%
                );
            pointer-events: none;


background: #ffe7fe5c;
        }

        /* Optional content in case the banner image is simple */
        .sy-brand-overlay {
            position: absolute;
            left: 7%;
            bottom: 7%;
            max-width: 520px;
            z-index: 2;

            padding: 25px 28px;
            border: 1px solid rgba(255,255,255,.45);
            border-radius: 24px;

            background: rgba(255,255,255,.68);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);

            box-shadow: 0 24px 60px rgba(51, 23, 91, .12);
        }

        .sy-brand-overlay h2 {
            font-family: "Cairo", sans-serif;
            font-weight: 800;
            line-height: 1.5;
            margin: 0;
            font-size: clamp(25px, 2.5vw, 42px);
        }

        .sy-brand-overlay .gradient-text {
            background: linear-gradient(
                90deg,
                var(--sy-secondary),
                var(--sy-primary),
                var(--sy-purple)
            );
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .sy-brand-overlay p {
            font-family: "Cairo", sans-serif;
            margin: 10px 0 0;
            font-size: 15px;
            line-height: 1.9;
            color: #5e5868;
        }

        /* ==========================
           Right Login Section
        ========================== */

        .sy-auth-login {
            min-height: 100vh;
            position: relative;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 55px 55px;

            background:
                radial-gradient(
                    circle at 100% 0%,
                    rgba(140, 57, 220, .09),
                    transparent 28%
                ),
                radial-gradient(
                    circle at 0% 100%,
                    rgba(49, 91, 201, .07),
                    transparent 28%
                ),
                #ffffff;
        }

        .sy-auth-login::before {
            content: "";
            width: 220px;
            height: 220px;
            position: absolute;
            top: -100px;
            right: -80px;
            border-radius: 50%;
            background: rgba(139, 57, 220, .06);
        }

        .sy-auth-login::after {
            content: "";
            width: 160px;
            height: 160px;
            position: absolute;
            bottom: -80px;
            left: -60px;
            border-radius: 50%;
            background: rgba(49, 91, 201, .05);
        }

        .sy-login-wrapper {
            width: 100%;
            max-width: 470px;
            position: relative;
            z-index: 2;
        }

        /* Version */
        .sy-version {
            position: absolute;
            right: 25px;
            top: 20px;
            z-index: 10;

            font-size: 11px;
            font-weight: 700;

            padding: 7px 12px;
            border-radius: 50px;

            color: #6b3bd1;
            background: #f2ebff;
            border: 1px solid #e6d8ff;

display:none;
        }

        /* ==========================
           Logo
        ========================== */

        .sy-login-logo {
            margin-bottom: 35px;
        }

        .sy-login-logo img {
            max-width: 230px;
            max-height: 100px;
            object-fit: contain;
        }

        /* ==========================
           Header
        ========================== */

        .sy-login-header {
            margin-bottom: 35px;
        }

        .sy-login-header .welcome {
            display: inline-flex;
            align-items: center;
            gap: 8px;

            color: var(--sy-primary);
            background: var(--sy-light);

            padding: 7px 13px;
            border-radius: 50px;

            font-size: 13px;
            font-weight: 700;

            margin-bottom: 13px;
        }

        .sy-login-header h1 {
            font-family: "Cairo", "Inter", sans-serif;
            margin: 0;

            font-size: clamp(30px, 3vw, 42px);
            line-height: 1.25;

            font-weight: 800;
            letter-spacing: -1px;
        }

        .sy-login-header p {
            font-family: "Cairo", "Inter", sans-serif;

            color: var(--sy-muted);
            font-size: 15px;
            margin: 10px 0 0;

            line-height: 1.8;
        }

        /* ==========================
           Form
        ========================== */

        .sy-form-group {
            margin-bottom: 22px;
        }

        .sy-form-label {
            display: block;
            margin-bottom: 8px;

            font-family: "Cairo", "Inter", sans-serif;
            font-size: 13px;
            font-weight: 700;
            color: #4f4958;
        }

        .sy-input-wrapper {
            position: relative;
        }

        .sy-input-icon {
            position: absolute;
            left: 17px;
            top: 50%;
            transform: translateY(-50%);

            color: #9b90a6;
            font-size: 18px;

            z-index: 2;
        }

        .sy-form-control {
            width: 100%;
            height: 57px;

            border-radius: 14px !important;
            border: 1px solid var(--sy-border) !important;

            padding: 12px 50px !important;

            background: #fcfbfe !important;
            color: #2c2832;

            font-size: 14px;

            transition: all .25s ease;
            box-shadow: none !important;
        }

        .sy-form-control:hover {
            border-color: #d9c9ed !important;
            background: #fff !important;
        }

        .sy-form-control:focus {
            border-color: var(--sy-primary) !important;
            background: #fff !important;

            box-shadow:
                0 0 0 4px rgba(108, 54, 216, .08) !important;
        }

        .sy-form-control::placeholder {
            color: #b5afb9;
        }

        /* Password button */

        .sy-password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);

            height: 38px;
            width: 38px;

            border-radius: 10px;

            display: flex;
            align-items: center;
            justify-content: center;

            color: #807789;
            text-decoration: none;

            transition: all .2s ease;

            z-index: 4;
        }

        .sy-password-toggle:hover {
            background: var(--sy-light);
            color: var(--sy-primary);
        }

        /* ==========================
           Remember
        ========================== */

        .sy-remember {
            display: flex;
            align-items: center;
            gap: 9px;

            font-family: "Cairo", "Inter", sans-serif;

            color: #6e6875;
            font-size: 13px;
        }

        .sy-remember input {
            width: 17px;
            height: 17px;

            accent-color: var(--sy-primary);
        }

        /* ==========================
           Button
        ========================== */

        .sy-login-btn {
            width: 100%;
            min-height: 57px;

            border: none;
            border-radius: 14px;

            margin-top: 18px;

            font-family: "Cairo", "Inter", sans-serif;
            font-size: 15px;
            font-weight: 800;

            color: white;

            background:
                linear-gradient(
                    110deg,
                    var(--sy-secondary) 0%,
                    var(--sy-primary) 52%,
                    var(--sy-purple) 100%
                );

            box-shadow:
                0 13px 30px rgba(108, 54, 216, .23);

            transition:
                transform .2s ease,
                box-shadow .2s ease;
        }

        .sy-login-btn:hover {
            color: #fff;
            transform: translateY(-2px);

            box-shadow:
                0 17px 38px rgba(108, 54, 216, .31);
        }

        .sy-login-btn:active {
            transform: translateY(0);
        }

        /* ==========================
           Demo card
        ========================== */

        .sy-demo {
            margin-top: 25px;
            border-radius: 15px;

            padding: 16px;

            background: #faf7ff;
            border: 1px dashed #d8c6f5;
        }

        .sy-demo-title {
            font-size: 12px;
            font-weight: 800;

            color: var(--sy-primary);

            margin-bottom: 8px;
        }

        .sy-demo-info {
            font-size: 12px;
            color: #69616f;
        }

        .sy-copy-btn {
            border: 0;
            width: 42px;
            height: 42px;

            border-radius: 11px;

            color: white;
            background: var(--sy-primary);

            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* ==========================
           Footer
        ========================== */

        .sy-login-footer {
            text-align: center;
            margin-top: 35px;

            color: #a09aa6;
            font-size: 11px;
        }

        /* ==========================
           Responsive
        ========================== */

        @media (max-width: 1100px) {
            .sy-auth {
                grid-template-columns: 1fr 1fr;
            }

            .sy-auth-login {
                padding: 45px 35px;
            }

            .sy-brand-overlay {
                left: 5%;
                right: 5%;
            }
        }

        @media (max-width: 850px) {

            .sy-auth {
                display: block;
            }

            .sy-auth-visual {
                min-height: 330px;
                height: 40vh;
                max-height: 450px;
            }

            .sy-brand-overlay {
                display: none;
            }

            .sy-auth-login {
                min-height: auto;
                padding: 45px 24px 60px;
            }

            .sy-login-logo {
                text-align: center;
            }

            .sy-login-header {
                text-align: center;
            }
        }

        @media (max-width: 500px) {

            .sy-auth-visual {
                min-height: 235px;
                height: 30vh;
            }

            .sy-auth-login {
                padding: 35px 18px 50px;
            }

            .sy-login-logo img {
                max-width: 190px;
            }

            .sy-login-header h1 {
                font-size: 29px;
            }

            .sy-version {
                top: 12px;
                right: 12px;
            }
        }
    </style>

    {!! ToastMagic::styles() !!}
</head>

<body>

<main id="content" role="main" class="main">

    <div class="sy-auth">

        {{-- =========================
             LEFT / BRAND BANNER
        ========================== --}}
        <section class="sy-auth-visual">

            <div class="sy-brand-overlay d-none">
                <h2>
                    {{ translate('Make Your Business') }}

                    <span class="gradient-text d-block">
                        {{ translate('Profitable...') }}
                    </span>
                </h2>

                <p>
                    SY Cosmetics & Vitamins
                </p>
            </div>

        </section>


        {{-- =========================
             RIGHT / LOGIN
        ========================== --}}
        <section class="sy-auth-login">

            @if (SOFTWARE_VERSION)
                <div class="sy-version user-select-none">
                    v{{ SOFTWARE_VERSION }}
                </div>
            @endif


            <div class="sy-login-wrapper">

                {{-- Logo --}}
                @php($eCommerceLogo = getWebConfig(name: 'company_web_logo'))

                <div class="sy-login-logo">
                    <a href="{{ route('home') }}">
                        <img
                            src="{{ getStorageImages(path: $eCommerceLogo, type: 'backend-logo') }}"
                            alt="SY Cosmetics and Vitamins">
                    </a>
                </div>


                {{-- Header --}}
                <div class="sy-login-header">

                    <span class="welcome">
                        ✦ {{ translate('welcome_back') }}
                    </span>

                    <h1>
                        {{ translate('sign_in') }}
                    </h1>

                    <p>
                        {{ translate('welcome_back_to') }}
                        <strong>{{ translate($role) }}</strong>
                        {{ translate('Login') }}
                    </p>

                </div>


                {{-- Login Form --}}
                <form
                    action="{{ route('login') }}"
                    method="post"
                    id="admin-login-form"
                    class="admin-login-form-form">

                    @csrf

                    <input
                        type="hidden"
                        name="role"
                        id="role"
                        value="{{ $role }}">


                    {{-- Email --}}
                    <div class="sy-form-group js-form-message">

                        <label
                            class="sy-form-label user-select-none"
                            for="signingAdminEmail">

                            {{ translate('your_email') }}

                        </label>

                        <div class="sy-input-wrapper">

                            <i class="fi fi-rr-envelope sy-input-icon"></i>

                            <input
                                type="email"
                                class="form-control sy-form-control"
                                name="email"
                                id="signingAdminEmail"
                                tabindex="1"
                                placeholder="email@address.com"
                                value="{{ old('email') }}"
                                autocomplete="email"
                                required>

                        </div>

                    </div>


                    {{-- Password --}}
                    <div class="sy-form-group js-form-message">

                        <label
                            class="sy-form-label user-select-none"
                            for="signingAdminPassword">

                            {{ translate('password') }}

                        </label>

                        <div class="sy-input-wrapper">

                            <i class="fi fi-rr-lock sy-input-icon"></i>

                            <input
                                type="password"
                                class="js-toggle-password form-control sy-form-control"
                                name="password"
                                id="signingAdminPassword"
                                placeholder="{{ translate('Minimum_8_characters_required') }}"
                                autocomplete="current-password"
                                required>

                            <a
                                id="changePassTarget"
                                class="sy-password-toggle changePassTarget"
                                href="javascript:void(0)"
                                aria-label="Toggle password">

                                <i
                                    id="changePassIcon"
                                    class="fi fi-sr-eye-crossed">
                                </i>

                            </a>

                        </div>

                    </div>


                    {{-- Remember --}}
                    <div class="sy-remember user-select-none">

                        <input
                            type="checkbox"
                            id="termsCheckbox"
                            name="remember">

                        <label for="termsCheckbox">
                            {{ translate('remember_me') }}
                        </label>

                    </div>


                    {{-- Login Button --}}
                    <button
                        type="submit"
                        id="admin-login-btn"
                        class="btn sy-login-btn">

                        {{ translate('sign_in') }}

                        <i class="fi fi-rr-arrow-right ms-2"></i>

                    </button>

                </form>


                {{-- Demo --}}
                @if (config('app.mode') == 'demo')

                    <div class="sy-demo">

                        <div class="sy-demo-title">
                            {{ translate('demo_login') }}
                        </div>

                        <div class="row align-items-center g-2">

                            <div class="col">

                                <div class="sy-demo-info d-flex flex-column gap-1">

                                    <span
                                        id="admin-email"
                                        data-email="{{ DemoConstant::ADMIN['email'] }}">

                                        {{ translate('email') }} :
                                        {{ DemoConstant::ADMIN['email'] }}

                                    </span>

                                    <span
                                        id="admin-password"
                                        data-password="{{ DemoConstant::ADMIN['password'] }}">

                                        {{ translate('password') }} :
                                        {{ DemoConstant::ADMIN['password'] }}

                                    </span>

                                </div>

                            </div>

                            <div class="col-auto">

                                <button
                                    type="button"
                                    class="sy-copy-btn"
                                    id="copyLoginInfo">

                                    <i class="fi fi-rr-copy"></i>

                                </button>

                            </div>

                        </div>

                    </div>

                @endif


                <div class="sy-login-footer">
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
    src="{{ dynamicAsset(path: 'public/assets/new/back-end/libs/jquery/jquery-3.7.1.min.js') }}">
</script>

<script
    src="{{ dynamicAsset(path: 'public/assets/backend/libs/bootstrap/bootstrap.bundle.min.js') }}">
</script>

<script
    src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/script.js') }}">
</script>

<script
    src="{{ dynamicAsset(path: 'public/assets/new/back-end/js/script-extended.js') }}">
</script>

<script
    src="{{ dynamicAsset(path: 'public/assets/backend/admin/js/auth.js') }}">
</script>


{!! ToastMagic::scripts() !!}


@if ($errors->any())

<script>
    "use strict";

    @foreach ($errors->all() as $error)
        toastMagic.error(@json($error));
    @endforeach
</script>

@endif

</body>
</html>