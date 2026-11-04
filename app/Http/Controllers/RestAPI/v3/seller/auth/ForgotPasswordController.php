<?php

namespace App\Http\Controllers\RestAPI\v3\seller\auth;

use App\Contracts\Repositories\PhoneOrEmailVerificationRepositoryInterface;
use App\Services\Platform\PasswordPolicy;
use App\Contracts\Repositories\VendorRepositoryInterface;
use App\Events\PasswordResetEvent;
use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Traits\CustomerTrait;
use App\Utils\Helpers;
use App\Utils\SMSModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Gateways\Traits\SmsGateway;
use App\Models\ZenderSetting;
use App\Services\ZenderOtpService;
class ForgotPasswordController extends Controller
{
    use CustomerTrait;

    public function __construct(
        private readonly PhoneOrEmailVerificationRepositoryInterface $phoneOrEmailVerificationRepo,
        private readonly VendorRepositoryInterface                   $vendorRepo,
    )
    {
    }

    public function reset_password_request(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $verification_by = getWebConfig(name: 'vendor_forgot_password_method');
        DB::table('password_resets')->where('user_type', 'seller')->where('identity', 'like', "%{$request['identity']}%")->delete();

        if ($verification_by == 'email') {
            $seller = Seller::Where(['email' => $request['identity']])->first();
            if (isset($seller)) {
                $token = Str::random(120);
                DB::table('password_resets')->insert([
                    'identity' => $seller['email'],
                    'token' => $token,
                    'user_type' => 'seller',
                    'created_at' => now(),
                ]);
                $reset_url = route('vendor.auth.forgot-password.reset-password', ['token' => $token]);

                $emailServices_smtp = getWebConfig(name: 'mail_config');
                if ($emailServices_smtp['status'] == 0) {
                    $emailServices_smtp = getWebConfig(name: 'mail_config_sendgrid');
                }
                if ($emailServices_smtp['status'] == 1) {
                    $data = [
                        'userType' => 'vendor',
                        'templateName' => 'forgot-password',
                        'vendorName' => $seller['f_name'],
                        'subject' => translate('password_reset'),
                        'title' => translate('password_reset'),
                        'passwordResetURL' => $reset_url,
                    ];
                    event(new PasswordResetEvent(email: $seller['email'], data: $data));
                    $response = translate('check_your_email');
                } else {
                    $response = translate('email_failed');
                }
                return response()->json(['message' => $response], 200);
            }
        } elseif ($verification_by == 'phone') {
            $seller = Seller::where('phone', 'like', "%{$request['identity']}%")->first();
            if (isset($seller)) {
                $token = (config('app.mode') == 'live') ? rand(100000, 999999) : 123456;
                DB::table('password_resets')->insert([
                    'identity' => $seller['phone'],
                    'token' => $token,
                    'user_type' => 'seller',
                    'created_at' => now(),
                ]);

              //  $response = SMSModule::sendCentralizedSMS($seller->phone, $token);
   $zender = ZenderSetting::first();

if ($zender && $zender->enabled) {

    $sent = app(ZenderOtpService::class)->send(
        $seller->phone,
        $token
    );

    $response = $sent ? 'success' : 'failed';

} else {

    $response = SMSModule::sendCentralizedSMS(
        $seller->phone,
        $token
    );
}

                if (config('app.mode') == 'dev') {
                    $response = 'success';
                }

                if ($response == 'success') {
                    return response()->json(['message' => 'otp sent successfully.'], 200);
                }
                return response()->json(['message' => 'otp sent failed.'], 200);
            }
        }
        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => 'user not found!']
        ]], 404);
    }

    public function otp_verification_submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
            'otp' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $id = $request['identity'];
        $data = DB::table('password_resets')
            ->where('user_type', 'seller')
            ->where(['token' => $request['otp']])
            ->where('identity', 'like', "%{$id}%")
            ->orderBy('created_at', 'desc')
            ->first();

        if (isset($data)) {
            return response()->json(['message' => 'otp verified.'], 200);
        }

        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => 'invalid OTP']
        ]], 404);
    }

    public function reset_password_submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
            'otp' => 'required',
            'password' => 'same:confirm_password|' . app(PasswordPolicy::class)->ruleString(),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $data = DB::table('password_resets')
            ->where('user_type', 'seller')
            ->where('identity', 'like', "%{$request['identity']}%")
            ->where(['token' => $request['otp']])
            ->orderBy('created_at', 'desc')
            ->first();

        $data2 = DB::table('phone_or_email_verifications')
            ->where(['token' => $request['otp']])
            ->where('phone_or_email', 'like', "%{$request['identity']}%")
            ->orderBy('created_at', 'desc')
            ->first();

        if ($data || $data2) {

            // The reset ends every existing session. Someone resetting their
            // password because they believe they were compromised was leaving
            // the attacker signed in: the password changed and auth_token did
            // not, so the stolen bearer kept working.
            DB::table('sellers')->where('phone', 'like', "%{$request['identity']}%")
                ->update([
                    'password' => bcrypt(str_replace(' ', '', $request['password'])),
                    'auth_token' => Str::random(50),
                ]);

            DB::table('password_resets')
                ->where('user_type', 'seller')
                ->where('identity', 'like', "%{$request['identity']}%")
                ->delete();

            DB::table('phone_or_email_verifications')
                ->where(['token' => $request['otp']])
                ->where('phone_or_email', 'like', "%{$request['identity']}%")
                ->delete();

            return response()->json(['message' => 'Password changed successfully.'], 200);
        }
        return response()->json(['errors' => [
            ['code' => 'invalid', 'message' => 'Invalid token.']
        ]], 400);
    }

    public function firebaseAuthTokenStore(Request $request): JsonResponse
    {
        $this->phoneOrEmailVerificationRepo->updateOrCreate(params: ['phone_or_email' => $request['identity']], value: [
            'phone_or_email' => $request['identity'],
            'token' => $request['token'],
        ]);
        return response()->json(['message' => translate('Token_is_successfully_Saved')], 200);
    }

    public function firebaseAuthVerify(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'sessionInfo' => 'required',
            'phoneNumber' => 'required',
            'code' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $verificationData = $this->phoneOrEmailVerificationRepo->getFirstWhere(params: ['phone_or_email' => $request['phoneNumber']]);
        $verifyStatus = $this->checkCustomerOTPBlockTimeOrInvalid(verificationData: $verificationData, identity: $request['phoneNumber']);
        if ($verifyStatus['status'] == 1) {
            return response()->json([
                'errors' => [
                    ['code' => $verifyStatus['code'], 'message' => $verifyStatus['message']]
                ]
            ], 403);
        }

        $firebaseOTPVerification = getWebConfig(name: 'firebase_otp_verification');
        $webApiKey = $firebaseOTPVerification ? $firebaseOTPVerification['web_api_key'] : '';

        $response = Http::post('https://identitytoolkit.googleapis.com/v1/accounts:signInWithPhoneNumber?key=' . $webApiKey, [
            'sessionInfo' => $request['sessionInfo'],
            'phoneNumber' => $request['phoneNumber'],
            'code' => $request['code'],
        ]);

        $responseData = $response->json();

        if (isset($responseData['error'])) {
            $errors = [];
            $errors[] = ['code' => "403", 'message' => translate(strtolower($responseData['error']['message']))];
            return response()->json(['errors' => $errors], 403);
        }

        $seller = $this->vendorRepo->getFirstWhere(params: ['identity' => $request['phoneNumber']]);

        $check = DB::table('phone_or_email_verifications')
            ->where(['token' => $request['sessionInfo']])
            ->where('phone_or_email', 'like', "%{$request['phoneNumber']}%")
            ->first();

        if ($seller && isset($check)) {
            return response()->json(['message' => 'otp verified.'], 200);
        }

        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => 'invalid OTP']
        ]], 404);
    }

    public function checkVendorExistInfo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $seller = $this->vendorRepo->getFirstWhere(params: ['identity' => $request['phone']]);
        if ($seller) {
            return response()->json(['message' => translate('Vendor found')], 200);
        }

        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => translate('Vendor not found')]
        ]], 404);
    }
}