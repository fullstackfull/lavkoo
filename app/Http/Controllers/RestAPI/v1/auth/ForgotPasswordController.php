<?php

namespace App\Http\Controllers\RestAPI\v1\auth;

use App\Contracts\Repositories\CustomerRepositoryInterface;
use App\Services\Platform\PasswordPolicy;
use App\Contracts\Repositories\PasswordResetRepositoryInterface;
use App\Events\PasswordResetEvent;
use App\Http\Controllers\Controller;
use App\Models\PasswordReset;
use App\Services\CustomerPasswordResetService;
use App\Traits\CustomerTrait;
use App\Utils\Helpers;
use App\Utils\RequestText;
use App\Utils\SMSModule;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\ZenderSetting;
use App\Services\ZenderOtpService;
class ForgotPasswordController extends Controller
{
    use CustomerTrait;

    public function __construct(
        private readonly CustomerRepositoryInterface                 $customerRepo,
        private readonly PasswordResetRepositoryInterface            $passwordResetRepo,
        private readonly CustomerPasswordResetService                $customerPasswordResetService,
    )
    {
    }

    public function reset_password_request(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $verification_by = 'phone';
        $otp_interval_time = getWebConfig(name: 'otp_resend_time') ?? 1; //second

        $identity = RequestText::of($request['identity']);
        $password_verification_data = PasswordReset::where(['user_type' => 'customer', 'identity' => $identity])->latest()->first();
        if ($verification_by == 'email') {
            $customer = $this->customerPasswordResetService->findCustomer(identity: $identity, kind: CustomerPasswordResetService::EMAIL);
            if (isset($customer)) {
                if(isset($password_verification_data) &&  Carbon::parse($password_verification_data->created_at)->diffInSeconds() < $otp_interval_time){
                    $time= $otp_interval_time - Carbon::parse($password_verification_data->created_at)->diffInSeconds();

                    return response()->json(['message' => translate('please_try_again_after').' '.CarbonInterval::seconds($time)->cascade()->forHumans()], 200);
                }else {
                    $token = Str::random(120);
                    $this->customerPasswordResetService->issueCode(customer: $customer, kind: CustomerPasswordResetService::EMAIL, code: $token);

                    $reset_url = url('/') . '/customer/auth/reset-password?token=' . $token;

                    $emailServices_smtp = getWebConfig(name: 'mail_config');
                    if ($emailServices_smtp['status'] == 0) {
                        $emailServices_smtp = getWebConfig(name: 'mail_config_sendgrid');
                    }
                    if ($emailServices_smtp['status'] == 1) {
                        try{
                            $data = [
                                'userType' => 'customer',
                                'templateName' => 'forgot-password',
                                'vendorName' => $customer['f_name'],
                                'subject' => translate('password_reset'),
                                'title' => translate('password_reset'),
                                'passwordResetURL' => $reset_url,
                            ];
                            event(new PasswordResetEvent(email: $customer['email'],data: $data));
                            $response = 'Check your email';
                        } catch (\Exception $exception) {
                            return response()->json([
                                'message' => translate('email_is_not_configured'). translate('contact_with_the_administrator')
                            ], 403);
                        }
                    } else {
                        $response = translate('email_failed');
                    }
                    return response()->json(['message' => $response], 200);
                }
            }
        } elseif ($verification_by == 'phone') {
            $customer = $this->customerPasswordResetService->findCustomer(identity: $identity, kind: CustomerPasswordResetService::PHONE);
            $otp_resend_time = getWebConfig(name: 'otp_resend_time') > 0 ? getWebConfig(name: 'otp_resend_time') : 0;
            if (isset($customer)) {
                if(isset($password_verification_data) &&  Carbon::parse($password_verification_data->created_at)->diffInSeconds() < $otp_interval_time){
                    $time= $otp_interval_time - Carbon::parse($password_verification_data->created_at)->diffInSeconds();

                    return response()->json(['message' => translate('please_try_again_after').' '.CarbonInterval::seconds($time)->cascade()->forHumans()], 200);
                }else {
                    $token = (config('app.mode') == 'live') ? rand(100000, 999999) : 123456;
                    $this->customerPasswordResetService->issueCode(customer: $customer, kind: CustomerPasswordResetService::PHONE, code: $token);

                   // SMSModule::sendCentralizedSMS($customer->phone, $token);
$zender = ZenderSetting::first();

if ($zender && $zender->enabled) {

    $sent = app(ZenderOtpService::class)->send(
        $customer->phone,
        $token
    );

    if (!$sent) {
        return response()->json([
            'message' => translate('otp_sent_failed'),
            'resend_time' => $otp_resend_time,
        ], 200);
    }

} else {

    SMSModule::sendCentralizedSMS(
        $customer->phone,
        $token
    );
}
                    return response()->json([
                        'message' => translate('otp_sent_successfully'),
                        'resend_time'=> $otp_resend_time,
                    ], 200);
                }
            }
        }
        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => translate('user not found').'!']
        ]], 403);
    }

    public function tokenVerificationSubmit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email_or_phone' => 'required',
            'reset_token' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $identity = RequestText::of($request['email_or_phone']);
        $verificationData = $this->passwordResetRepo->getFirstWhere(params: ['user_type' => 'customer', 'identity' => $identity]);
        $verifyStatus = $this->checkPasswordResetOTPBlockTimeOrInvalid(verificationData: $verificationData, identity: $identity);
        if ($verifyStatus['status'] == 1) {
            return response()->json([
                'errors' => [
                    ['code' => $verifyStatus['code'], 'message' => $verifyStatus['message']]
                ]
            ], 403);
        }

        $verify = $this->customerPasswordResetService->findCustomerHoldingCode(identity: $identity, code: RequestText::of($request['reset_token']));
        if ($verify) {
            return response()->json(['message' => translate('otp_verified')], 200);
        }

        return response()->json([
            'errors' => [
                ['code' => 'token', 'message' => translate('OTP_is_not_matched')]
            ]
        ], 403);
    }
    public function reset_password_submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
            'otp' => 'required',
            'password' => 'required|same:confirm_password|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $customer = $this->customerPasswordResetService->findCustomerHoldingCode(identity: RequestText::of($request['identity']), code: RequestText::of($request['otp']));
        if (isset($customer)) {
            $this->customerPasswordResetService->resetPassword(customer: $customer, password: $request['password']);

            return response()->json(['message' => translate('password_changed_successfully')], 200);
        }

        return response()->json(['errors' => [
            ['code' => 'invalid', 'message' => translate('invalid_token')]
        ]], 400);
    }
}
