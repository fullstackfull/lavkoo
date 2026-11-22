<?php

namespace App\Http\Controllers\RestAPI\v2\seller\auth;

use App\Events\PasswordResetEvent;
use App\Services\Platform\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Services\VendorPasswordResetService;
use App\Utils\Helpers;
use App\Utils\SMSModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Gateways\Traits\SmsGateway;

class ForgotPasswordController extends Controller
{
    public function __construct(
        private readonly VendorPasswordResetService $vendorPasswordResetService,
    )
    {
    }

    public function reset_password_request(Request $request):JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $identity = (string)$request['identity'];
        $verification_by = getWebConfig(name: 'vendor_forgot_password_method');
        DB::table('password_resets')->where('user_type','seller')->where('identity', $identity)->delete();

        if ($verification_by == 'email') {
            $seller = $this->vendorPasswordResetService->findSeller(identity: $identity, method: VendorPasswordResetService::EMAIL);
            if (isset($seller)) {
                $token = Str::random(120);
                DB::table('password_resets')->insert([
                    'identity' => $seller['email'],
                    'token' => $token,
                    'user_type'=>'seller',
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
                    event(new PasswordResetEvent(email: $seller['email'],data: $data));
                    $response = translate('check_your_email');
                }else{
                    $response= translate('email_failed');
                }
                return response()->json(['message' => $response], 200);
            }
        } elseif ($verification_by == 'phone') {
            $seller = $this->vendorPasswordResetService->findSeller(identity: $identity, method: VendorPasswordResetService::PHONE);
            if (isset($seller)) {
                $token = config('app.mode') == 'dev' ? 1234 : random_int(1000, 9999);
                DB::table('password_resets')->insert([
                    'identity' => $seller['phone'],
                    'token' => $token,
                    'user_type'=>'seller',
                    'created_at' => now(),
                ]);

                SMSModule::sendCentralizedSMS($seller->phone, $token);
                return response()->json(['message' => 'otp sent successfully.'], 200);
            }
        }
        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => 'user not found!']
        ]], 404);
    }

    public function otp_verification_submit(Request $request):JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
            'otp' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $seller = $this->vendorPasswordResetService->findSellerHoldingCode(
            identity: (string)$request['identity'],
            code: (string)$request['otp'],
            method: getWebConfig(name: 'vendor_forgot_password_method'),
        );

        if (isset($seller)) {
            return response()->json(['message' => 'otp verified.'], 200);
        }

        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => 'invalid OTP']
        ]], 404);
    }

    public function reset_password_submit(Request $request):JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
            'otp' => 'required',
            'password' => 'same:confirm_password|' . app(PasswordPolicy::class)->ruleString(),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $identity = (string)$request['identity'];
        $proof = (string)$request['otp'];

        $seller = $this->vendorPasswordResetService->findSellerHoldingCode(
            identity: $identity,
            code: $proof,
            method: getWebConfig(name: 'vendor_forgot_password_method'),
        );

        if (isset($seller)) {
            $this->vendorPasswordResetService->resetPassword(
                seller: $seller,
                identity: $identity,
                proof: $proof,
                password: $request['password'],
            );

            return response()->json(['message' => 'Password changed successfully.'], 200);
        }
        return response()->json(['errors' => [
            ['code' => 'invalid', 'message' => 'Invalid token.']
        ]], 400);
    }
}
