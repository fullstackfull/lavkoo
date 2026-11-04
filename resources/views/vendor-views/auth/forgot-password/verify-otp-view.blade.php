<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="_token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ translate('Verify_OTP') }}</title>
    <link rel="shortcut icon"
          href="{{ getStorageImages(path: getWebConfig(name: 'company_fav_icon'), type:'backend-logo') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/backend/libs/fonts/inter/inter.css') }}">

    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/google-fonts.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/vendor.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/vendor/icon-set/style.css') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/theme.minc619.css?v=1.0') }}">
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/back-end/css/style.css') }}">

    @if ($web_config['primary_color'])
        <style>
            :root {
                --bs-primary: {!! $web_config['primary_color'] !!};
            }
        </style>
    @endif

    {!! ToastMagic::styles() !!}
</head>

<body>
<main id="content" role="main" class="main">
    <div class="auth-wrapper">
        <div class="auth-wrapper-left"
             style="background: url('{{ dynamicAsset(path: 'public/assets/back-end/img/login-bg.webp') }}') no-repeat center center / cover">
            <div class="auth-left-cont">
                @php($eCommerceLogo = getWebConfig(name: 'company_web_logo'))
                <a class="d-inline-flex mb-5" href="{{ route('home') }}">
                    <img width="310" src="{{ getStorageImages(path: $eCommerceLogo, type:'backend-logo') }}" alt="Logo">
                </a>
                <h2 class="title">
                    {{ translate('Make Your Business') }}
                    <span class="font-weight-bold c1 d-block text-capitalize">
                        {{ translate('Profitable...') }}
                    </span>
                </h2>
            </div>
        </div>
        <div class="auth-wrapper-right">
            <div class="auth-wrapper-form text-center">
                <div class="__inline-20">
                    <div class="d-block d-lg-none">
                        <a class="d-inline-flex mb-3" href="{{ route('home') }}">
                            <img width="100" src="{{ getStorageImages(path: $eCommerceLogo, type:'backend-logo') }}"
                                 alt="Logo">
                        </a>
                    </div>

                    <div class="mb-5">
                        <h1 class="display-4">{{ translate('Verify_OTP') }}</h1>
                        <h1 class="h4 text-gray-900 mb-4">
                            {{ translate('provide_your_otp_and_proceed') }}
                        </h1>
                    </div>

                    <form id="form-id" class="otp-form" action="{{route('vendor.auth.forgot-password.otp-verification')}}" method="post">
                        @csrf
                        <div class="js-form-message form-group mt-5">
                            <div class="form-group text-center">
                                <label for="partitioned">{{translate('enter_your_OTP')}}</label>
                                <div class="d-flex gap-2 gap-sm-3 align-items-end justify-content-center">
                                    <input class="otp-field" type="text" name="token[]" maxlength="1" autocomplete="off">
                                    <input class="otp-field" type="text" name="token[]" maxlength="1" autocomplete="off">
                                    <input class="otp-field" type="text" name="token[]" maxlength="1" autocomplete="off">
                                    <input class="otp-field" type="text" name="token[]" maxlength="1" autocomplete="off">
                                    <input class="otp-field" type="text" name="token[]" maxlength="1" autocomplete="off">
                                    <input class="otp-field" type="text" name="token[]" maxlength="1" autocomplete="off">
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-center align-items-baseline flex-wrap gap-2">
                            <a class="btn btn-lg btn-block btn-secondary w-auto flex-grow-1"
                               href="{{ route('vendor.auth.forgot-password.index') }}">
                                {{ translate('back')}}
                            </a>
                            <button type="submit" class="btn btn-lg btn-block btn--primary w-auto flex-grow-1" disabled>
                                {{ translate('proceed')}}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>


<script src="{{dynamicAsset(path: 'public/assets/back-end/js/vendor.min.js')}}"></script>
<script src="{{dynamicAsset(path: 'public/assets/back-end/js/theme.min.js')}}"></script>
<script src="{{dynamicAsset(path: 'public/assets/backend/vendor/js/auth.js')}}"></script>

{!! ToastMagic::scripts() !!}

@if ($errors->any())
    <script>
        'use strict';
        @foreach($errors->all() as $error)
        toastMagic.error('{{ $error }}');
        @endforeach
    </script>
@endif

<script>
    'use strict';
    $(document).ready(function() {
        let $inputs = $(".otp-field");

        $inputs.on("input", function() {
            if (this.value.length === 1) {
                $(this).next(".otp-field").focus();
            }
            checkOtpFilled();
        });

        $inputs.on("keydown", function(e) {
            if (e.key === "Backspace" && this.value === "") {
                $(this).prev(".otp-field").focus();
            }
        });

        $inputs.on("paste", function(e) {
            e.preventDefault();
            let pasteData = (e.originalEvent || e).clipboardData.getData("text");
            let chars = pasteData.split("");
            let lastIndex = 0;

            $inputs.each(function(i) {
                if (chars[i]) {
                    $(this).val(chars[i]);
                    lastIndex = i;
                }
            });

            $inputs.eq(lastIndex).focus();

            checkOtpFilled();
        });

        function checkOtpFilled() {
            let allFilled = true;
            $inputs.each(function() {
                if ($(this).val() === "") {
                    allFilled = false;
                }
            });
            $("[type=submit]").prop("disabled", !allFilled);
        }
    });

</script>


</body>
</html>



