<?php

namespace App\Http\Controllers\Customer\Auth;

use Carbon\Carbon;
use App\Utils\Helpers;
use App\Utils\SMSModule;
use App\Utils\CartManager;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;
use App\Utils\CustomerManager;
use App\Services\FirebaseService;
use Illuminate\Http\JsonResponse;
use App\Services\RecaptchaService;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\RedirectResponse;
use App\Events\EmailVerificationEvent;
use App\Services\Web\CustomerAuthService;
use Illuminate\Support\Facades\Validator;
use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Contracts\Repositories\LoginSetupRepositoryInterface;
use App\Contracts\Repositories\PhoneOrEmailVerificationRepositoryInterface;

class CustomerAuthController extends Controller
{
    public function __construct(
        private readonly CustomerRepositoryInterface                 $customerRepo,
        private readonly PhoneOrEmailVerificationRepositoryInterface $phoneOrEmailVerificationRepo,
        private readonly LoginSetupRepositoryInterface               $loginSetupRepo,
        private readonly CustomerAuthService                         $customerAuthService,
        private readonly FirebaseService                             $firebaseService,
    )
    {
        $this->middleware('guest:customer', ['except' => ['logout']]);
    }

    public function loginView(): View|RedirectResponse
    {
        $this->customerAuthService->storeCustomerAuthReturnURL();
        $keepCustomerLoginRedirectUrl = session('keep_customer_login_redirect_url');
        return view('web-views.customer-views.auth.login', [
            'keepCustomerLoginRedirectUrl' => $keepCustomerLoginRedirectUrl,
        ]);
    }

    public function loginSubmit(Request $request): JsonResponse|RedirectResponse
    {
        $result = RecaptchaService::verificationStatus(request: $request, session: 'default_recaptcha_id_customer_auth', action: "customer_auth", firebase: true);
        if ($result && !$result['status']) {
            if ($request->ajax()) {
                return response()->json([
                    'error' => $result['message'],
                ]);
            }
            Toastr::error($result['message']);
            return back();
        }

        if ($request['keep_customer_login_redirect_url']) {
            session()->put('keep_customer_login_redirect_url', $request['keep_customer_login_redirect_url']);
        } else {
            $this->customerAuthService->storeCustomerAuthReturnURL();
        }
        $authAttemptRedirectUrl = $this->customerAuthService->getCustomerAuthReturnURL();

        $loginOptions = json_decode($this->loginSetupRepo->getFirstWhere(params: ['key' => 'login_options'])?->value ?? [], true);
        session()->forget('tempCustomerInfo');
        if (isset($loginOptions['otp_login']) && $loginOptions['otp_login'] && $request['login_type'] == 'otp-login') {
            return $this->loginByOTP(request: $request);
        } elseif (isset($loginOptions['manual_login']) && $loginOptions['manual_login'] && $request['login_type'] == 'manual-login') {
            return $this->loginByEmailOrPhone(request: $request);
        } else {
            return redirect($authAttemptRedirectUrl);
        }
    }


    public function loginByOTP(Request $request): JsonResponse|RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|min:6|max:20'
        ]);

        if ($validator->fails()) {
            if ($request->ajax()) {
                return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
            }
            $errors = Helpers::validationErrorProcessor($validator);
            foreach ($errors as $value) {
                Toastr::error(translate($value['message']));
            }
            return back();
        }


        $phoneNumber = $request['phone'];
        $OTPIntervalTime = getWebConfig(name: 'otp_resend_time') ?? 60;
        $OTPVerificationData = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $phoneNumber]);

        if (isset($OTPVerificationData) && Carbon::parse($OTPVerificationData['created_at'])->DiffInSeconds() < $OTPIntervalTime) {
            $time = $OTPIntervalTime - Carbon::parse($OTPVerificationData['created_at'])->DiffInSeconds();
            if (request()->ajax()) {
                return response()->json([
                    'status' => 0,
                    'message' => translate('please_try_again_after_') . $time . ' ' . translate('seconds')
                ]);
            }
            Toastr::error(translate('please_try_again_after_') . $time . ' ' . translate('seconds'));
            return redirect()->back();
        }

        $token = $this->customerAuthService->getCustomerVerificationToken();
        $firebaseOTPVerification = getWebConfig(name: 'firebase_otp_verification') ?? [];
        $errorMsg = translate('OTP_sending_failed');

        if ($firebaseOTPVerification && $firebaseOTPVerification['status']) {
            $firebaseResponse = $this->firebaseService->sendOtp($phoneNumber);
            $response = 'error';
            if ($firebaseResponse['status'] == 'success') {
                $token = $firebaseResponse['sessionInfo'];
                $response = $firebaseResponse['status'];
            } else {
                $errorMsg = translate(ucfirst(strtolower($firebaseResponse['errors'])));
            }
        } else {
            $response = $this->customerAuthService->sendCustomerPhoneVerificationToken($phoneNumber, $token);
            $response = $response['response'] ?? 'error';
            if (config('app.mode') == 'dev') {
                $response = 'success';
            }
        }

        if ($response == 'success') {
            $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $phoneNumber], value: [
                'phone_or_email' => $phoneNumber,
                'token' => $token,
            ]);
        }

        if (request()->ajax()) {
            if ($response == 'success') {
                return response()->json([
                    'status' => 'success',
                    'redirect_url' => route('customer.auth.login.verify-account', ['identity' => base64_encode($phoneNumber), 'type' => base64_encode('phone_verification')]),
                    'message' => translate('Please_Verify_that_its_you')
                ]);
            }
            return response()->json([
                'status' => 'error',
                'message' => $errorMsg
            ]);
        } else {
            if ($response == 'success') {
                return redirect()->route('customer.auth.login.verify-account', ['identity' => base64_encode($phoneNumber), 'type' => base64_encode('phone_verification')]);
            }
            Toastr::error($errorMsg);
            return redirect()->back();
        }
    }

    public function loginByEmailOrPhone(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'user_identity' => 'required',
            'password' => 'required'
        ]);

        if ($request['keep_customer_login_redirect_url']) {
            session()->put('keep_customer_login_redirect_url', $request['keep_customer_login_redirect_url']);
        } else {
            $this->customerAuthService->storeCustomerAuthReturnURL();
        }
        $authAttemptRedirectUrl = $this->customerAuthService->getCustomerAuthReturnURL();

        $user = $this->customerRepo->getByIdentity(filters: ['identity' => $request['user_identity']]);
        if (!$user || (!Hash::check($request->input('password'), $user['password']))) {
            if ($request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => translate('credentials_doesnt_match'),
                    'redirect_url' => ''
                ]);
            }
            Toastr::error(translate('credentials_doesnt_match'));
            return back()->withInput();
        }

        $remember = (bool)$request['remember'];

        $maxLoginHit = getWebConfig(name: 'maximum_login_hit') ?? 5;
        $tempBlockTime = getWebConfig(name: 'temporary_login_block_time') ?? 5; //seconds
        if (isset($user->temp_block_time) && Carbon::parse($user->temp_block_time)->DiffInSeconds() <= $tempBlockTime) {
            $time = $tempBlockTime - Carbon::parse($user->temp_block_time)->DiffInSeconds();
            if ($request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans(),
                    'redirect_url' => ''
                ]);
            }
            Toastr::error(translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans());
            return back()->withInput();
        }

        $phoneVerification = getLoginConfig(key: 'phone_verification');
        $emailVerification = getLoginConfig(key: 'email_verification');
        $emailVerification = !$phoneVerification ? $emailVerification : 0;

        if ($phoneVerification && $user['phone'] && !$user['is_phone_verified'] || $emailVerification && $user['email'] && !$user['is_email_verified']) {
            $this->getCustomerVerificationCheck($request, $user, $phoneVerification, $emailVerification);
            $verifyType = ($phoneVerification && !$user['is_phone_verified']) ? 'phone_verification' : 'email_verification';
            $verifyIdentity = ($phoneVerification && !$user['is_phone_verified']) ? $user['phone'] : $user['email'];
            if ($request->ajax()) {
                return response()->json([
                    'status' => 'success',
                    'message' => $verifyType == 'phone_verification' ? translate('Please_verify_your_phone') : translate('Please_verify_your_email'),
                    'redirect_url' => route('customer.auth.check-verification', ['identity' => base64_encode($verifyIdentity), 'type' => base64_encode($verifyType)]),
                ]);
            }
            return redirect(route('customer.auth.check-verification', ['identity' => base64_encode($verifyIdentity), 'type' => base64_encode($verifyType)]));
        }

        $loginMedia = $user['phone'] ? 'phone' : 'email';
        if (auth('customer')->attempt([$loginMedia => $user[$loginMedia], 'password' => $request['password']], $remember)) {
            if (!$user['is_active']) {
                auth()->guard('customer')->logout();
                if ($request->ajax()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => translate('your_account_is_suspended'),
                    ]);
                }
                Toastr::error(translate('your_account_is_suspended'));
                return back()->withInput();
            }

            CustomerManager::updateCustomerSessionData(userId: auth('customer')->id());
            CartManager::cartListSessionToDatabase();
            $this->customerRepo->updateWhere(params: ['id' => $user['id']], data: $this->customerAuthService->getCustomerLoginDataReset());

            if ($request->ajax()) {
                return response()->json([
                    'status' => 'success',
                    'message' => translate('login_successful'),
                    'redirect_url' => $authAttemptRedirectUrl,
                ]);
            }

            Toastr::success(translate('welcome_to') . ' ' . getWebConfig(name: 'company_name') . '!');
            return redirect($authAttemptRedirectUrl);
        } else {
            // Login attempt check start
            if (isset($user->temp_block_time) && Carbon::parse($user->temp_block_time)->diffInSeconds() <= $tempBlockTime) {
                $time = $tempBlockTime - Carbon::parse($user->temp_block_time)->diffInSeconds();
                $ajaxMessage = [
                    'status' => 'error',
                    'message' => translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans(),
                    'redirect_url' => ''
                ];
                Toastr::error(translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans());
            } elseif ($user['is_temp_blocked'] == 1 && Carbon::parse($user['temp_block_time'])->diffInSeconds() >= $tempBlockTime) {

                $this->customerRepo->updateWhere(params: ['id' => $user['id']], data: $this->customerAuthService->getCustomerLoginDataReset());
                $ajaxMessage = [
                    'status' => 'error',
                    'message' => translate('credentials_doesnt_match'),
                    'redirect_url' => ''
                ];
                Toastr::error(translate('credentials_doesnt_match'));
            } elseif ($user['login_hit_count'] >= $maxLoginHit && $user['is_temp_blocked'] == 0) {
                $this->customerRepo->updateWhere(params: ['id' => $user['id']], data: [
                    'is_temp_blocked' => 1,
                    'temp_block_time' => now(),
                    'updated_at' => now()
                ]);

                $time = $tempBlockTime - Carbon::parse($user['temp_block_time'])->diffInSeconds();

                $ajaxMessage = [
                    'status' => 'error',
                    'message' => translate('too_many_attempts._please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans(),
                    'redirect_url' => ''
                ];
                Toastr::error(translate('too_many_attempts._please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans());
            } else {
                $ajaxMessage = [
                    'status' => 'error',
                    'message' => translate('credentials_doesnt_match'),
                    'redirect_url' => ''
                ];
                Toastr::error(translate('credentials_doesnt_match'));
            }
            $user = $this->customerRepo->getByIdentity(filters: ['identity' => $request['user_identity']]);
            $this->customerRepo->updateWhere(params: ['id' => $user['id']], data: [
                'login_hit_count' => ($user['login_hit_count'] + 1),
                'updated_at' => now()
            ]);
            // Login attempt check end

            if ($request->ajax()) {
                return response()->json($ajaxMessage);
            }
            return back()->withInput();
        }
    }


    public function getCustomerVerificationCheck($request, $user, $phoneVerification, $emailVerification)
    {
        $response = null;
        $token = $this->customerAuthService->getCustomerVerificationToken();
        $firebaseOTPVerification = getWebConfig(name: 'firebase_otp_verification') ?? [];

        if ($phoneVerification && $user['phone'] && !$user['is_phone_verified'] && $firebaseOTPVerification && $firebaseOTPVerification['status']) {
            $response = $this->firebaseService->sendOtp($user['phone']);
            if ($response['status'] == 'success') {
                $token = $response['sessionInfo'];
            }
        } else if ($phoneVerification && $user['phone'] && !$user['is_phone_verified']) {
            $response = $this->customerAuthService->sendCustomerPhoneVerificationToken($user['phone'], $token);
        } else if ($emailVerification && $user['email'] && !$user['is_email_verified']) {
            $response = $this->customerAuthService->sendCustomerEmailVerificationToken($user, $token);
        }

        if ($response && $response['status'] == 'error') {
            Toastr::error($response['message']);
            return back();
        }

        if ($response && $response['status'] == 'success') {
            $verifyIdentify = $phoneVerification && !$user['is_phone_verified'] ? $user?->phone : $user?->email;
            $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $verifyIdentify], value: [
                'phone_or_email' => $verifyIdentify,
                'token' => $token,
            ]);
        }
        return $response;
    }

    public function loginVerifyPhone(Request $request): View|JsonResponse
    {
        $token = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $this->identityFrom($request)]);
        $otpResendTime = getWebConfig(name: 'otp_resend_time') > 0 ? getWebConfig(name: 'otp_resend_time') : 0;
        if ($token) {
            $token_time = Carbon::parse($token['created_at']);
            $convertTime = $token_time->addSeconds((int)$otpResendTime);
            $getTimeInSecond = $convertTime > Carbon::now() ? Carbon::now()->diffInSeconds($convertTime) : 0;
        } else {
            $getTimeInSecond = 0;
        }
        $userVerify = 0;

        return view(VIEW_FILE_NAMES['customer_auth_verify_otp_login'], [
            'userVerify' => $userVerify,
            'identity' => $request['identity'],
            'otpResendTime' => $otpResendTime,
            'getTimeInSecond' => $getTimeInSecond,
            'otpFromType' => $request['action'] ?? '',
        ]);
    }

    public function verifyAccount(Request $request): View|RedirectResponse|JsonResponse
    {
        $result = RecaptchaService::verificationStatus(request: $request, session: 'default_recaptcha_id_customer_auth', action: "customer_auth", firebase: true);
        if ($result && !$result['status']) {
            if ($request->ajax()) {
                return response()->json([
                    'error' => $result['message'],
                ]);
            }
            Toastr::error($result['message']);
            return back();
        }
        if (!$request->has('token') || empty($request['token'])) {
            if (request()->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => translate('The_token_field_is_required'),
                ]);
            }
            Toastr::error(translate('The_token_field_is_required'));
            return redirect()->back();
        }

        if ($request['keep_customer_login_redirect_url']) {
            session()->put('keep_customer_login_redirect_url', $request['keep_customer_login_redirect_url']);
        } else {
            $this->customerAuthService->storeCustomerAuthReturnURL();
        }
        $authAttemptRedirectUrl = $this->customerAuthService->getCustomerAuthReturnURL();

        $firebaseOTPVerification = getWebConfig(name: 'firebase_otp_verification') ?? [];
        $identity = $this->identityFrom($request);
        $maxOTPHit = getWebConfig(name: 'maximum_otp_hit') ?? 5;
        $maxOTPHitTime = getWebConfig(name: 'otp_resend_time') ?? 60; // seconds
        $tempBlockTime = getWebConfig(name: 'temporary_block_time') ?? 600; // seconds
        $verificationData = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $identity]);
        $OTPVerificationData = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $identity, 'token' => $request['token']]);

        if ($verificationData) {
            $validateBlock = 0;
            $errorMsg = translate('OTP_is_not_matched');
            if (isset($verificationData->temp_block_time) && Carbon::parse($verificationData->temp_block_time)->DiffInSeconds() <= $tempBlockTime) {
                $time = $tempBlockTime - Carbon::parse($verificationData->temp_block_time)->DiffInSeconds();
                $validateBlock = 1;
                $errorMsg = translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans();
            } else if ($verificationData['is_temp_blocked'] == 1 && Carbon::parse($verificationData['updated_at'])->DiffInSeconds() >= $tempBlockTime) {
                $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $identity], value: [
                    'otp_hit_count' => 0,
                    'is_temp_blocked' => 0,
                    'temp_block_time' => null,
                ]);
                $validateBlock = 1;
                $errorMsg = translate('OTP_is_not_matched');
            } else if ($verificationData['otp_hit_count'] >= $maxOTPHit && Carbon::parse($verificationData['updated_at'])->DiffInSeconds() < $maxOTPHitTime && $verificationData['is_temp_blocked'] == 0) {
                $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $identity], value: [
                    'is_temp_blocked' => 1,
                    'temp_block_time' => now(),
                ]);

                $validateBlock = 1;
                $time = $tempBlockTime - Carbon::parse($verificationData['temp_block_time'])->DiffInSeconds();
                $errorMsg = translate('Too_many_attempts.') . ' ' . translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans();
            }
            $verificationData = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $identity]);
            $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $identity], value: [
                'otp_hit_count' => ($verificationData['otp_hit_count'] + 1),
                'updated_at' => now(),
            ]);
            if ($validateBlock) {
                if (request()->ajax()) {
                    return response()->json([
                        'status' => 0,
                        'message' => $errorMsg
                    ]);
                }
                Toastr::error($errorMsg);
                return redirect()->back();
            }

            $tokenVerifyStatus = false;
            if ($firebaseOTPVerification && $firebaseOTPVerification['status']) {
                $firebaseVerify = $this->firebaseService->verifyOtp($verificationData['token'], $verificationData['phone_or_email'], $request['token']);
                $tokenVerifyStatus = (bool)($firebaseVerify['status'] == 'success');
                if (!$tokenVerifyStatus) {
                    $verificationData = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $identity]);
                    $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $identity], value: [
                        'otp_hit_count' => ($verificationData['otp_hit_count'] + 1),
                        'updated_at' => now(),
                        'temp_block_time' => null,
                    ]);
                    Toastr::error(translate(strtolower($firebaseVerify['errors'])));
                    return back();
                }
            } else {
                $tokenVerifyStatus = (bool)$OTPVerificationData;
            }

            if ($tokenVerifyStatus) {
                if (isset($OTPVerificationData->temp_block_time) && \Illuminate\Support\Carbon::parse($OTPVerificationData->temp_block_time)->DiffInSeconds() <= $tempBlockTime) {
                    $time = $tempBlockTime - Carbon::parse($OTPVerificationData->temp_block_time)->DiffInSeconds();
                    $errorMsg = translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans();
                    if (request()->ajax()) {
                        return response()->json([
                            'status' => 0,
                            'message' => $errorMsg
                        ]);
                    }
                    Toastr::error($errorMsg);
                    return redirect()->back();
                }

                $this->customerRepo->updateWhere(params: ['phone' => $identity], data: [
                    'is_phone_verified' => 1,
                ]);
                $user = $this->customerRepo->getByIdentity(filters: ['identity' => $identity]);
                $this->phoneOrEmailVerificationRepo->delete(params: ['phone_or_email' => $identity]);
                if (isset($request['type']) && $request['type'] == 'password-reset') {
                    auth('customer')->login($user);
                    CustomerManager::updateCustomerSessionData(userId: auth('customer')->id());
                    return redirect($authAttemptRedirectUrl);
                } elseif ($user && ($user['name'] == null)) {
                    return redirect()->route('customer.auth.login.update-info', ['identity' => base64_encode($identity)]);
                } elseif ($user && $user['name']) {
                    auth('customer')->login($user);
                    CustomerManager::updateCustomerSessionData(userId: auth('customer')->id());
                    return redirect($authAttemptRedirectUrl);
                } else {
                    session()->put('tempCustomerInfo', [
                        'phone' => $identity,
                        'password' => bcrypt(rand(11111111, 99999999)),
                        'referral_code' => Helpers::generate_referer_code(),
                    ]);
                    return redirect()->route('customer.auth.login.update-info', ['identity' => base64_encode($identity)]);
                }
            }
        }

        $errorMsg = translate('OTP_is_not_matched');
        if (request()->ajax()) {
            return response()->json([
                'status' => 0,
                'message' => $errorMsg
            ]);
        }
        Toastr::error($errorMsg);
        return redirect()->back();
    }

    public function updateInfo(Request $request): View|RedirectResponse
    {
        $user = $this->customerRepo->getFirstWhere(params: ['phone' => $this->identityFrom($request)]);
        return view(VIEW_FILE_NAMES['customer_auth_verify_otp_update_info'], [
            'user' => $user,
            'tempUser' => session('tempCustomerInfo'),
            'updateType' => 'otp',
            'identity' => $request['identity'],
        ]);
    }

    public function updateInfoSubmit(Request $request): View|RedirectResponse
    {
        $result = RecaptchaService::verificationStatus(request: $request, session: 'default_recaptcha_id_customer_auth', action: "customer_auth", firebase: true);
        if ($result && !$result['status']) {
            Toastr::error($result['message']);
            return back();
        }

        $checkEmail = $this->customerRepo->getFirstWhere(params: ['email' => $request['email']]);
        if (!empty($request['email']) && $checkEmail) {
            Toastr::error(translate('Email_already_exists'));
            return back();
        }

        if ($request['keep_customer_login_redirect_url']) {
            session()->put('keep_customer_login_redirect_url', $request['keep_customer_login_redirect_url']);
        } else {
            $this->customerAuthService->storeCustomerAuthReturnURL();
        }
        $authAttemptRedirectUrl = $this->customerAuthService->getCustomerAuthReturnURL();

        $user = $this->customerRepo->getFirstWhere(params: ['phone' => $this->identityFrom($request)]);
        if ($user || session()->has('tempCustomerInfo')) {
            // trim() and explode() both reject an array, and this is a public registration flow —
            // a name that is not a string is a name that was not given.
            $name = is_string($request['name']) ? trim($request['name']) : '';
            $nameParts = explode(' ', $name, 2);

            $data = [
                'name' => $request['name'],
                'f_name' => $nameParts[0] ?? '',
                'l_name' => $nameParts[1] ?? '',
                'email' => $request['email'],
                'is_phone_verified' => 1,
                'referral_code' => Helpers::generate_referer_code(),
            ];
            if (session()->has('tempCustomerInfo')) {
                $data = array_merge($data, session('tempCustomerInfo'));
            }
            $this->customerRepo->updateOrCreate(params: ['phone' => $this->identityFrom($request)], data: $data);
            if (!empty($request['email'])) {
                $user = $this->customerRepo->getFirstWhere(params: ['email' => $request['email']]);
            } else {
                $user = $this->customerRepo->getFirstWhere(params: ['phone' => $data['phone']]);
            }

            auth('customer')->login($user);
            CustomerManager::updateCustomerSessionData(userId: auth('customer')->id());
            Toastr::success(translate('Update_successfully'));
            return redirect($authAttemptRedirectUrl);
        } else {
            Toastr::error(translate('User_not_found'));
            return back();
        }
    }

    public function resendOTPCode(Request $request): JsonResponse|RedirectResponse
    {
        $user = $this->customerRepo->getFirstWhere(params: ['phone' => $this->identityFrom($request)]);
        if (!$user) {
            Toastr::error(translate('User_not_found'));
            return back();
        }
        $token = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $user['phone']]);
        $otpResendTime = getWebConfig(name: 'otp_resend_time') > 0 ? getWebConfig(name: 'otp_resend_time') : 0;

        // Time Difference in Minutes
        if ($token) {
            $tokenTime = Carbon::parse($token->created_at);
            $addTime = $tokenTime->addSeconds((int)$otpResendTime);
            $timeDifferance = $addTime > Carbon::now() ? Carbon::now()->diffInSeconds($addTime) : 0;
        } else {
            $timeDifferance = 0;
        }

        $newTokenGenerate = (config('app.mode') == 'live') ? rand(100000, 999999) : 123456;
        if ($timeDifferance == 0) {
            if ($token) {
                $token->token = $newTokenGenerate;
                $token->otp_hit_count = 0;
                $token->is_temp_blocked = 0;
                $token->temp_block_time = null;
                $token->created_at = now();
                $token->save();
            } else {
                $new_token = new PhoneOrEmailVerification();
                $new_token->phone_or_email = $user['email'];
                $new_token->token = $newTokenGenerate;
                $new_token->created_at = now();
                $new_token->updated_at = now();
                $new_token->save();
            }

            $phone_verification = getLoginConfig(key: 'phone_verification');
            $email_verification = getLoginConfig(key: 'email_verification');
            if ($phone_verification && !$user->is_phone_verified) {
                SMSModule::sendCentralizedSMS($user['phone'], $newTokenGenerate);
            }

            if ($email_verification && !$user['is_email_verified']) {
                $emailServicesSmtp = getWebConfig(name: 'mail_config');
                if ($emailServicesSmtp['status'] == 0) {
                    $emailServicesSmtp = getWebConfig(name: 'mail_config_sendgrid');
                }
                if ($emailServicesSmtp['status'] == 1) {
                    try {
                        $data = [
                            'userName' => $user['f_name'],
                            'subject' => translate('registration_Verification_Code'),
                            'title' => translate('registration_Verification_Code'),
                            'verificationCode' => $newTokenGenerate,
                            'userType' => 'customer',
                            'templateName' => 'registration-verification',
                        ];
                        event(new EmailVerificationEvent(email: $user['email'], data: $data));
                    } catch (\Exception $exception) {
                        return response()->json([
                            'status' => "0",
                        ]);
                    }
                }
            }
            if (request()->ajax()) {
                return response()->json(['status' => 1, 'new_time' => $otpResendTime]);
            } else {
                Toastr::success(translate('Resend_OTP_successfully'));
                return back();
            }
        } else {
            if (request()->ajax()) {
                return response()->json(['status' => 0]);
            } else {
                Toastr::error(translate('Resend_OTP_unsuccessful'));
                return back();
            }
        }
    }

    public function firebaseAuthVerify(Request $request): View|RedirectResponse
    {
        return view(VIEW_FILE_NAMES['customer_auth_verify_firebase_auth_verify'], [
            'phone' => $request['phone'],
            'session' => $request['session'],
        ]);
    }

    public function firebaseAuthVerifySubmit(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'session' => 'required',
            'phone' => 'required',
            'code' => 'required',
        ]);

        $firebaseOTPVerification = getWebConfig(name: 'fcm_credentials');
        $webApiKey = $firebaseOTPVerification ? $firebaseOTPVerification['apiKey'] : '';

        $response = Http::post('https://identitytoolkit.googleapis.com/v1/accounts:signInWithPhoneNumber?key=' . $webApiKey, [
            'sessionInfo' => $request['session'],
            'phoneNumber' => '+' . $request['phone'],
            'code' => $request['code'],
        ]);

        $responseData = $response->json();
        if (isset($responseData['error'])) {
            Toastr::error(translate(strtolower($responseData['error']['message'])));
            return back();
        }

        if ($request['keep_customer_login_redirect_url']) {
            session()->put('keep_customer_login_redirect_url', $request['keep_customer_login_redirect_url']);
        } else {
            $this->customerAuthService->storeCustomerAuthReturnURL();
        }
        $authAttemptRedirectUrl = $this->customerAuthService->getCustomerAuthReturnURL();

        $this->customerRepo->updateWhere(params: ['phone' => $responseData['phoneNumber']], data: ['is_phone_verified' => 1]);
        $user = $this->customerRepo->getByIdentity(filters: ['identity' => $responseData['phoneNumber']]);
        if ($user && ($user['name'] == null || $user['email'] == null)) {
            return redirect()->route('customer.auth.login.update-info', ['identity' => base64_encode($user['email'])]);
        } elseif ($user && $user['name'] && $user['email']) {
            auth('customer')->login($user);
            CustomerManager::updateCustomerSessionData(userId: auth('customer')->id());
            return redirect($authAttemptRedirectUrl);
        } else {
            $user = $this->customerRepo->updateOrCreate(params: ['phone' => $responseData['phoneNumber']], data: [
                'phone' => $responseData['phoneNumber'],
                'password' => bcrypt(rand(11111111, 99999999)),
                'referral_code' => Helpers::generate_referer_code(),
            ]);
            return redirect()->route('customer.auth.login.update-info', ['identity' => base64_encode($responseData['phoneNumber'])]);
        }
    }
    /**
     * The phone or email this request is about, decoded.
     *
     * Six methods on this controller carry the identity through the OTP flow as base64 in the
     * request, and every one of them decoded it inline. base64_decode's parameter is typed, so
     * `?identity[]=x` was a 500 on a PUBLIC registration and password-reset path — six times over,
     * which is exactly why it is read in one place now.
     *
     * An identity that is not a string decodes to nothing, and nothing matches no account.
     */
    private function identityFrom(Request $request): string
    {
        return is_string($request['identity']) ? base64_decode($request['identity']) : '';
    }
}
