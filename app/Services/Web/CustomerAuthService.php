<?php

namespace App\Services\Web;

use App\Enums\SessionKey;
use App\Utils\Helpers;
use App\Utils\SMSModule;
use App\Events\EmailVerificationEvent;
use Exception;


class CustomerAuthService
{
    public function getCustomerVerificationToken(): string
    {
        return (config('app.mode') == 'live') ? rand(100000, 999999) : 123456;
    }

    public function forgetPendingSocialLogin(): void
    {
        session()->forget(['social_login_new_customer', 'socialLoginEmailRemovedForOldUser']);
    }

    public function getCustomerLoginDataReset(): array
    {
        return [
            'login_hit_count' => 0,
            'is_temp_blocked' => 0,
            'temp_block_time' => null,
            'updated_at' => now()
        ];
    }


    public function sendCustomerPhoneVerificationToken($phone, $token): array
    {
        $response = SMSModule::sendCentralizedSMS($phone, $token);
        return [
            'response' => $response,
            'status' => 'success',
            'message' => translate('please_check_your_SMS_for_OTP'),
        ];
    }

    public function sendCustomerEmailVerificationToken($user, $token): array
    {
        $emailServicesSmtp = getWebConfig(name: 'mail_config');
        if ($emailServicesSmtp['status'] == 0) {
            $emailServicesSmtp = getWebConfig(name: 'mail_config_sendgrid');
        }
        if ($emailServicesSmtp['status'] == 1 && $user['email']) {
            try {
                $data = [
                    'userName' => $user['f_name'],
                    'subject' => translate('registration_Verification_Code'),
                    'title' => translate('registration_Verification_Code'),
                    'verificationCode' => $token,
                    'userType' => 'customer',
                    'templateName' => 'registration-verification',
                ];

                event(new EmailVerificationEvent(email: $user['email'], data: $data));
                return [
                    'status' => 'success',
                    'message' => translate('check_your_email'),
                ];
            } catch (Exception $exception) {
                return [
                    'status' => 'error',
                    'message' => translate('email_is_not_configured') . '. ' . translate('contact_with_the_administrator'),
                ];
            }
        } else {
            return [
                'status' => 'error',
                'message' => translate('email_failed'),
            ];
        }
    }

    public function getCustomerRegisterData(object|array $request, object|array|null $referUser): array
    {
        return [
            'name' => $request['f_name'] . ' ' . $request['l_name'],
            'f_name' => $request['f_name'],
            'l_name' => $request['l_name'],
            'email' => $request['email'],
            'phone' => $request['phone'],
            'is_active' => 1,
            'password' => bcrypt($request['password']),
            'referral_code' => Helpers::generate_referer_code(),
            'referred_by' => $referUser ? $referUser['id'] : null,
        ];
    }

    public function storeCustomerAuthReturnURL(): void
    {
        $historyUrls = session('recent_user_routes_history', []);
        if (!empty($historyUrls)) {
            $lastUrl = end($historyUrls);
            session()->put('keep_customer_login_redirect_url', $lastUrl);
        } else {
            session()->forget('keep_customer_login_redirect_url');
        }
    }

    public function getCustomerAuthReturnURL(): string
    {
        $keepReturnUrl = route('home');
        if (session()->has('keep_customer_login_redirect_url')) {
            $keepReturnUrl = session('keep_customer_login_redirect_url');
        }
        return $keepReturnUrl;
    }

    /**
     * The phone or email a request is about, decoded.
     *
     * The OTP flow carries the identity through its pages as base64 in the request. base64_decode's parameter is
     * typed, so `?identity[]=x` was a 500 on a PUBLIC registration and password-reset path. An identity that is
     * not a string decodes to nothing, and nothing matches no account.
     */
    public function decodeIdentity(mixed $encodedIdentity): string
    {
        return is_string($encodedIdentity) ? base64_decode($encodedIdentity) : '';
    }

    /** The code of the phone login was just entered for this phone: this browser may now finish its profile. */
    public function rememberVerifiedPhone(string $phone): void
    {
        session()->put(SessionKey::CUSTOMER_OTP_VERIFIED_PHONE, $phone);
    }

    public function isVerifiedPhone(string $phone): bool
    {
        return $phone !== '' && session(SessionKey::CUSTOMER_OTP_VERIFIED_PHONE) === $phone;
    }

    public function forgetVerifiedPhone(): void
    {
        session()->forget(SessionKey::CUSTOMER_OTP_VERIFIED_PHONE);
    }

    /** A code was just sent for this account to this browser by its own sign-up or sign-in: show its verification page. */
    public function rememberPendingVerification(int $userId): void
    {
        $userIds = session(SessionKey::CUSTOMER_PENDING_VERIFICATION_USER_IDS, []);
        session()->put(SessionKey::CUSTOMER_PENDING_VERIFICATION_USER_IDS, array_values(array_unique([...$userIds, $userId])));
    }

    public function hasPendingVerification(int $userId): bool
    {
        return in_array(needle: $userId, haystack: session(SessionKey::CUSTOMER_PENDING_VERIFICATION_USER_IDS, []), strict: true);
    }

    public function forgetPendingVerification(int $userId): void
    {
        $userIds = session(SessionKey::CUSTOMER_PENDING_VERIFICATION_USER_IDS, []);
        session()->put(SessionKey::CUSTOMER_PENDING_VERIFICATION_USER_IDS, array_values(array_diff($userIds, [$userId])));
    }

}
