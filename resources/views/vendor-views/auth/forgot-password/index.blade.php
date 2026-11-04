@php
    use App\Enums\DemoConstant;
    $eCommerceLogo = getWebConfig(name: 'company_web_logo');
    $verificationBy = getWebConfig('vendor_forgot_password_method') ?? 'phone';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="robots" content="nofollow, noindex">
    <meta name="_token" content="{{ csrf_token() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <title>{{ translate('forgot_password') }}</title>

    <link rel="shortcut icon" href="{{ getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type: 'backend-logo') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/fonts/inter/inter.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/google-fonts.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/vendor/icon-set/style.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/theme.minc619.css?v=1.0') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/style.css') }}">
    
    <!-- Required for Phone Input -->
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/plugins/intl-tel-input/css/intlTelInput.css') }}">

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

        /* ==========================================
           FIX FOR INTL-TEL-INPUT FLAGS (SYRIA & ALL)
           ========================================== */
        .iti__flag, .iti-flag {
            background-image: url('{{ dynamicAsset(path: "public/assets/backend/libs/intl-tel-input/img/flags.png") }}') !important;
        }
        /* Fix for high-resolution (Retina) displays */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .iti__flag, .iti-flag {
                background-image: url('{{ dynamicAsset(path: "public/assets/backend/libs/intl-tel-input/img/flags@2x.png") }}') !important;
            }
        }

        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body { margin: 0; background: #fff; color: var(--sy-text); font-family: "Inter", Arial, sans-serif; }

        .main { min-height: 100vh; padding: 0 !important; margin: 0 !important; }

        .vendor-auth {
            width: 100%; min-height: 100vh; display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(430px, .85fr);
            background: #fff; overflow: hidden;
        }

        .vendor-auth-image {
            position: relative; min-height: 100vh; background: #f7e1ff; overflow: hidden;
        }
        .vendor-auth-image::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(255,255,255,0) 45%, rgba(35,16,65,.08) 100%);
            pointer-events: none;
        }

        .vendor-auth-panel {
            position: relative; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 55px 55px;
            background: radial-gradient(circle at 100% 0%, rgba(142, 69, 220, .10), transparent 30%),
                        radial-gradient(circle at 0% 100%, rgba(47, 103, 204, .07), transparent 28%), #fff;
        }
        .vendor-auth-panel::before {
            content: ""; position: absolute; top: -120px; right: -120px; width: 280px; height: 280px;
            border-radius: 50%; background: rgba(120, 66, 211, .04);
        }
        .vendor-auth-panel::after {
            content: ""; position: absolute; left: -70px; bottom: -100px; width: 220px; height: 220px;
            border-radius: 50%; background: rgba(47, 103, 204, .04);
        }

        .vendor-login-container { width: 100%; max-width: 470px; position: relative; z-index: 2; }
        .vendor-logo { margin-bottom: 24px; }
        .vendor-logo img { width: auto; max-width: 235px; max-height: 95px; object-fit: contain; }

        .vendor-login-badge {
            display: inline-flex; align-items: center; gap: 7px; padding: 7px 13px; margin-bottom: 14px;
            border-radius: 50px; background: #f4efff; color: var(--sy-primary); font-size: 12px; font-weight: 700;
        }
        .vendor-login-badge::before { content: "✦"; font-size: 12px; }

        .vendor-login-heading { margin-bottom: 25px; }
        .vendor-login-heading h1 { margin: 0; font-size: 32px; line-height: 1.25; font-weight: 800; letter-spacing: -1px; color: #24202a; }
        .vendor-login-heading p { margin: 10px 0 0; font-size: 14px; line-height: 1.7; color: var(--sy-muted); }
        .vendor-login-heading strong { color: var(--sy-primary); font-weight: 700; }

        .vendor-form-group { margin-bottom: 21px; }
        .vendor-form-label {
            width: 100%; display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 8px; color: #4f4957; font-size: 13px; font-weight: 700;
        }
        .vendor-form-label a { color: var(--sy-primary); text-decoration: none; font-weight: 600; transition: .2s ease; }
        .vendor-form-label a:hover { color: var(--sy-primary-dark); }

        .vendor-input-wrap { position: relative; }
        .vendor-input-icon {
            position: absolute; left: 17px; top: 50%; transform: translateY(-50%);
            font-size: 18px; color: #9c94a4; z-index: 4;
        }
        .vendor-control {
            width: 100% !important; height: 58px !important; padding-left: 50px !important; padding-right: 20px !important;
            border: 1px solid var(--sy-border) !important; border-radius: 14px !important;
            background: #fcfbfe !important; color: #2c2831 !important; font-size: 14px !important;
            outline: none !important; box-shadow: none !important; transition: border-color .2s ease, box-shadow .2s ease, background .2s ease;
        }
        .vendor-control:hover { border-color: #dacdec !important; background: #fff !important; }
        .vendor-control:focus { background: #fff !important; border-color: var(--sy-primary) !important; box-shadow: 0 0 0 4px rgba(112, 65, 215, .08) !important; }
        .vendor-control::placeholder { color: #b6b0bb; }

        .vendor-login-btn {
            width: 100%; min-height: 58px; margin-top: 20px; display: flex; align-items: center; justify-content: center; gap: 9px;
            border: 0 !important; border-radius: 14px !important; color: #fff !important; font-size: 15px; font-weight: 700;
            background: linear-gradient(110deg, var(--sy-blue) 0%, var(--sy-primary) 55%, var(--sy-purple) 100%) !important;
            box-shadow: 0 14px 30px rgba(112, 65, 215, .23); transition: transform .2s ease, box-shadow .2s ease;
        }
        .vendor-login-btn:hover { transform: translateY(-2px); box-shadow: 0 18px 38px rgba(112, 65, 215, .30); color: #fff !important; }

        .vendor-demo-box {
            margin-top: 25px; padding: 15px 16px; border: 1px dashed #d7c8ec; border-radius: 14px; background: #faf7ff;
        }
        .vendor-demo-title { margin-bottom: 9px; font-size: 12px; font-weight: 700; color: var(--sy-primary); }
        .vendor-demo-value { display: block; font-size: 12px; line-height: 1.7; color: #6f6876; }
        .vendor-copy-btn {
            width: 42px; height: 42px; padding: 0 !important; display: inline-flex; align-items: center; justify-content: center;
            border: 0 !important; border-radius: 10px !important; color: #fff !important; background: var(--sy-primary) !important;
        }

        .vendor-auth-footer { margin-top: 30px; text-align: center; color: #aaa4ae; font-size: 11px; }

        /* Steps List Styling */
        .vendor-steps-list { list-style: none; padding: 0; margin: 0 0 25px 0; }
        .vendor-steps-list li { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; font-size: 13px; color: var(--sy-muted); line-height: 1.6; }
        .vendor-steps-list li span { 
            display: inline-flex; align-items: center; justify-content: center; 
            width: 20px; height: 20px; border-radius: 50%; background: var(--sy-light); 
            color: var(--sy-primary); font-weight: 700; font-size: 11px; flex-shrink: 0; margin-top: 2px;
        }

        @media (max-width: 850px) {
            .vendor-auth { display: flex; flex-direction: column; }
            .vendor-auth-image { min-height: 250px; height: 30vh; max-height: 350px; background-position: center center; }
            .vendor-auth-panel { min-height: auto; padding: 45px 25px 55px; }
            .vendor-login-container { max-width: 520px; }
            .vendor-logo { text-align: center; }
            .vendor-login-heading { text-align: center; }
            .vendor-login-badge { margin-left: auto; margin-right: auto; }
        }
        @media (max-width: 576px) {
            .vendor-auth-image { height: 200px; min-height: 200px; }
            .vendor-auth-panel { padding: 32px 18px 45px; }
            .vendor-login-heading h1 { font-size: 28px; }
        }
      /* ==========================================
   FIX PHONE INPUT WIDTH & LAYOUT
   ========================================== */
.iti {
    width: 100% !important;
    display: block !important;
}

.iti__flag-container {
    position: absolute;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 10;
}

.iti__selected-flag {
    height: 100%;
    border-radius: 14px 0 0 14px !important;
    background-color: #fcfbfe;
    border-right: 1px solid var(--sy-border);
}

/* عند الـ Focus */
.vendor-control:focus + .iti__flag-container .iti__selected-flag,
.iti__selected-flag:hover {
    background-color: #fff;
    border-right-color: var(--sy-primary);
}

/* تعديل حقل الإدخال ليتناسب مع وجود العلم */
.phone-input-with-country-picker-forgot-password {
    padding-left: 95px !important; /* مساحة للعلم + كود الدولة */
    padding-right: 20px !important;
    width: 100% !important;
}

/* إزالة أي padding زائد من المكتبة */
.iti--allow-dropdown input,
.iti--allow-dropdown input[type=text],
.iti--allow-dropdown input[type=tel] {
    padding-left: 95px !important;
}
    </style>

    {!! ToastMagic::styles() !!}
</head>

<body>
<main id="content" role="main" class="main">
    <div class="vendor-auth">
        {{-- LEFT IMAGE --}}
        <section class="vendor-auth-image"></section>

        {{-- RIGHT PANEL --}}
        <section class="vendor-auth-panel">
            <div class="vendor-login-container">
                {{-- Logo --}}
                <div class="vendor-logo text-center">
                    <a href="{{ route('home') }}">
                        <img src="{{ getStorageImages(path: $eCommerceLogo, type: 'backend-logo') }}" alt="Logo">
                    </a>
                </div>

                {{-- Header --}}
                <div class="vendor-login-heading text-center">
                    <div class="vendor-login-badge">
                        {{ translate('Forgot_Password') }}
                    </div>
                    <h1>{{ translate('Reset_Password') }}</h1>
                    <p>{{ translate('Follow_steps_to_reset_vendor_password') }}</p>
                </div>

                {{-- FORM --}}
                <form id="vendor-forgot-password-form" action="{{ route('vendor.auth.forgot-password.index') }}" method="post">
                    @csrf

                    @if ($verificationBy == 'email')
                        <ol class="vendor-steps-list">
                            <li><span>1</span> {{ translate('enter_your_email_address_below') }}.</li>
                            <li><span>2</span> {{ translate('we_will_send_you_a_temporary_link_via_email') }}.</li>
                            <li><span>3</span> {{ translate('by_clicking_the_link_to_change_your_password_on_our_secure_website') }}.</li>
                        </ol>

                        <div class="vendor-form-group js-form-message">
                            <label class="vendor-form-label" for="forgotVendorEmail">
                                <span>{{ translate('Email_Address') }}</span>
                                <a href="{{ route('vendor.auth.login') }}">{{ translate('back_to_login') }}</a>
                            </label>
                            <div class="vendor-input-wrap">
                                <i class="tio-email vendor-input-icon"></i>
                                <input type="email" class="form-control vendor-control" name="identity" id="forgotVendorEmail" 
                                       value="{{ old('identity') }}" placeholder="{{ translate('enter_email_address') }}" required>
                            </div>
                        </div>
                    @else
                        <ol class="vendor-steps-list">
                            <li><span>1</span> {{ translate('fill_in_your_phone_number_below') }}.</li>
                            <li><span>2</span> {{ translate('we_will_send_you_a_temporary_OTP_via_phone') }}.</li>
                            <li><span>3</span> {{ translate('use_the_OTP_to_change_your_password_on_our_secure_website') }}.</li>
                        </ol>

                        <div class="vendor-form-group js-form-message">
                            <label class="vendor-form-label" for="forgotVendorPassword">
                                <span>{{ translate('phone') }}</span>
                                <a href="{{ route('vendor.auth.login') }}">{{ translate('back_to_login') }}</a>
                            </label>
                            <div class="vendor-input-wrap">
                                <i class="tio-phone vendor-input-icon"></i>
                                <input type="tel" id="forgotVendorPassword" name="identity" value="{{ old('identity') }}" 
                                       class="form-control vendor-control phone-input-with-country-picker-forgot-password" 
                                       placeholder="{{ translate('enter_phone_number') }}" required />
                            </div>
                        </div>
                    @endif

                    @if ($web_config['firebase_otp_verification'] && $web_config['firebase_otp_verification']['status'])
                        <div class="generate-firebase-auth-recaptcha mb-3" id="firebase-auth-recaptcha-{{ rand(111, 999) }}"></div>
                    @endif

                    <button type="submit" id="vendor-forgot-password-btn" class="btn vendor-login-btn">
                        <span>{{ $verificationBy == 'email' ? translate('Send') : translate('Get_OTP') }}</span>
                        <i class="tio-arrow-forward"></i>
                    </button>
                </form>

                {{-- DEMO MODE --}}
                @if (config('app.mode') == 'demo')
                    <div class="vendor-demo-box">
                        <div class="vendor-demo-title">{{ translate('demo_login') }}</div>
                        <div class="row align-items-center">
                            <div class="col">
                                <span id="vendor-email" class="vendor-demo-value" data-email="{{ DemoConstant::VENDOR['email'] }}">
                                    {{ translate('email') }} : {{ DemoConstant::VENDOR['email'] }}
                                </span>
                                <span id="vendor-password" class="vendor-demo-value" data-password="{{ DemoConstant::VENDOR['password'] }}">
                                    {{ translate('password') }} : {{ DemoConstant::VENDOR['password'] }}
                                </span>
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn vendor-copy-btn" id="copyLoginInfo">
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

<span id="message-copied_success" data-text="{{ translate('copied_successfully') }}"></span>

{{-- Scripts --}}
<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/vendor.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/theme.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/back-end/js/toastr.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/vendor/js/auth.js') }}"></script>
{!! ToastMagic::scripts() !!}

@if ($errors->any())
    <script>
        'use strict';
        @foreach ($errors->all() as $error)
            toastMagic.error(@json($error));
        @endforeach
    </script>
@endif

{{-- Phone Input & Firebase Scripts (Required for functionality) --}}
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/intlTelInput.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/utils.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/intl-tel-input/js/intlTelInout-validation.js') }}"></script>

@php($fcmCredentials = getWebConfig('fcm_credentials'))
<span id="Firebase_Configuration_Config" 
      data-api-key="{{ $fcmCredentials['apiKey'] ?? '' }}"
      data-auth-domain="{{ $fcmCredentials['authDomain'] ?? '' }}"
      data-project-id="{{ $fcmCredentials['projectId'] ?? '' }}"
      data-storage-bucket="{{ $fcmCredentials['storageBucket'] ?? '' }}"
      data-messaging-sender-id="{{ $fcmCredentials['messagingSenderId'] ?? '' }}"
      data-app-id="{{ $fcmCredentials['appId'] ?? '' }}"
      data-measurement-id="{{ $fcmCredentials['measurementId'] ?? '' }}"
      data-csrf-token="{{ csrf_token() }}"
      data-route="{{ route('system.subscribeToTopic') }}"
      data-recaptcha-store="{{ route('g-recaptcha-response-store') }}"
      data-favicon="{{ $web_config['fav_icon']['path'] }}"
      data-firebase-service-worker-file="{{ dynamicAsset(path: 'firebase-messaging-sw.js') }}"
      data-firebase-service-worker-scope="{{ dynamicAsset(path: 'firebase-cloud-messaging-push-scope') }}">
</span>

<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase.min.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase-init.js') }}"></script>
<script src="{{ dynamicAsset(path: 'public/assets/backend/libs/firebase/firebase-auth.js') }}"></script>

<script>
    try {
        const topics = {!! json_encode(getFCMTopicListToSubscribe()) !!};
        subscribeToNotificationTopics(topics);
    } catch (e) {
        console.warn(e);
    }
</script>

</body>
</html>