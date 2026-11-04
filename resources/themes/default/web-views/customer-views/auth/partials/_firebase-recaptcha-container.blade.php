@if($web_config['firebase_otp_verification'] && $web_config['firebase_otp_verification']['status'])
    <div class="generate-firebase-auth-recaptcha" id="firebase-auth-recaptcha-{{ rand(111, 999) }}"></div>
@endif
