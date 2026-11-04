<?php

namespace App\Services;

use App\Services\Platform\Policy;
use Illuminate\Support\Facades\Http;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Validation\ValidationException;

class RecaptchaService
{
    public static function verify(string $token, ?string $action = null): bool
    {
        $secretKey = getWebConfig(name: 'recaptcha')['secret_key'] ?? null;

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secretKey,
            'response' => $token,
            'remoteip' => request()->ip(),
        ]);

        $data = $response->json();
        if (!($data['success'] ?? false)) {
            ToastMagic::error(translate('ReCAPTCHA_Failed'));
            return false;
        }

        // The score a shop is willing to accept is a posture decision — a stricter one turns away
        // more bots and more people — so it comes from the settings page rather than from here.
        if (($data['score'] ?? 0) < app(Policy::class)->float('recaptcha_minimum_score')) {
            ToastMagic::error(translate('ReCAPTCHA_Score_Too_Low_Please_Try_Again'));
            return false;
        }
        if ($action !== null && ($data['action'] ?? '') !== $action) {
            ToastMagic::error(translate('ReCAPTCHA_Action_Invalid'));
            return false;
        }

        return true;
    }

    /**
     * Whether a form has cleared the captcha.
     *
     * Every login and forgot-password flow — admin, employee, vendor, customer — funnels through
     * this one method, which is what made switching captcha off a single edit here rather than nine.
     * It also made switching it back ON a deploy: the platform's only bot defence on its
     * authentication forms was decided in a class nobody outside the codebase could see.
     *
     * So the decision is a setting now, and the shipped default is the behaviour this install
     * already has — off, with rate limiting as the compensating control (see the `auth` limiter in
     * RouteServiceProvider). Turning it on needs a site key and a secret key, and the settings page
     * says so rather than enabling a check that would then fail every sign-in.
     */
    public static function verificationStatus(object|array $request, string $session, ?string $action = 'default', ?bool $firebase = false): array
    {
        return self::isEnforced()
            ? self::verificationStatusOriginal($request, $session, $action, $firebase)
            : ['status' => true, 'message' => ''];
    }

    /** Configured AND switched on. Enforcing without keys would refuse every sign-in on the shop. */
    public static function isEnforced(): bool
    {
        $recaptcha = getWebConfig(name: 'recaptcha');

        return is_array($recaptcha)
            && (int) ($recaptcha['status'] ?? 0) === 1
            && !empty($recaptcha['site_key'])
            && !empty($recaptcha['secret_key']);
    }

    public static function verificationStatusOriginal(object|array $request, string $session, ?string $action = 'default', ?bool $firebase = false): array
    {
        $firebaseOTPVerification = getWebConfig(name: 'firebase_otp_verification') ?? [];
        if ($firebase && $firebaseOTPVerification && $firebaseOTPVerification['status']) {
            if (empty($request['g-recaptcha-response'])) {
                return [
                    'status' => false,
                    'message' => translate('ReCAPTCHA_Failed'),
                ];
            } else {
                return [
                    'status' => true,
                    'message' => translate('ReCAPTCHA_verification_success.'),
                ];
            }
        }

        $recaptcha = getWebConfig(name: 'recaptcha');
        if (isset($recaptcha) && $recaptcha['status'] == 1 && !$request['default_captcha_value']) {
            try {
                $request->validate([
                    'g-recaptcha-response' => [
                        function ($attribute, $value, $fail) use ($action) {
                            if (empty($value)) {
                                $fail(translate('ReCAPTCHA_verification_failed.'));
                                return;
                            }
                            if (!RecaptchaService::verify(token: $value, action: $action)) {
                                $fail(translate('ReCAPTCHA_verification_failed.'));
                                return;
                            }
                        },
                    ],
                ]);
            } catch (ValidationException $e) {
                return [
                    'status' => false,
                    'message' => $e->validator->errors()->first('g-recaptcha-response'),
                ];
            }
        } else if (strtolower(session($session)) != strtolower($request['default_captcha_value'])) {
            return [
                'status' => false,
                'message' => translate('ReCAPTCHA_failed.'),
            ];
        }

        if (isset($request['default_captcha_value']) && strtolower(session($session)) == strtolower($request['default_captcha_value'])) {
            session()->forget($session);
        }

        session()->forget($session);
        return [
            'status' => true,
            'message' => translate('ReCAPTCHA_verification_success.'),
        ];
    }
}


?>
