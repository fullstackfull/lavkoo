<?php

namespace App\Http\Controllers\RestAPI\v2\delivery_man\auth;

use App\Events\DeliverymanPasswordResetEvent;
use App\Services\Platform\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Models\DeliveryMan;
use App\Models\PasswordReset;
use App\Utils\Helpers;
use App\Utils\SMSModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Gateways\Traits\SmsGateway;

class LoginController extends Controller
{
    public function login(Request $request):JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'password' => 'required|min:8'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        /**
         * checking if existing delivery man has a country code or not
         */

        $d_man = DeliveryMan::where(['phone' => $request->phone])->first();
        if ($d_man && isset($d_man->country_code) && ($d_man->country_code != $request->country_code)) {
            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => 'Invalid credential or account suspended']);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        if (isset($d_man) && $d_man['is_active'] == 1 && Hash::check($request->password, $d_man->password)) {
            $token = Str::random(50);
            $d_man->auth_token = $token;
            $d_man->save();
            return response()->json(['token' => $token], 200);
        } else {
            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => 'Invalid credential or account suspended']);
            return response()->json([
                'errors' => $errors
            ], 401);
        }
    }

    public function reset_password_request(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'identity' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        PasswordReset::where(['user_type' => 'delivery_man', 'identity' => $request['identity']])->delete();
        $deliveryMan = DeliveryMan::where(['phone' => $request['identity']])->orWhere(['email' => $request['identity']])->first();
        $verificationBy = getWebConfig(name: 'deliveryman_forgot_password_method') ?? 'phone';

        if (isset($deliveryMan)) {
            $otp = (config('app.mode') == 'live') ? rand(1000, 9999) : 1234;

            PasswordReset::insert([
                'identity' => $request['identity'],
                'token' => $otp,
                'user_type' => 'delivery_man',
                'created_at' => now(),
            ]);

            if ($verificationBy == 'email') {
                $emailServices_smtp = getWebConfig(name: 'mail_config');

                if ($emailServices_smtp['status'] == 0) {
                    $emailServices_smtp = getWebConfig(name: 'mail_config_sendgrid');
                }
                if ($emailServices_smtp['status'] == 1) {
                    try {
                        $data = [
                            'userType' => 'delivery-man',
                            'templateName' => 'reset-password-verification',
                            'deliveryManName' => $deliveryMan['f_name'],
                            'subject' => translate('OTP_Verification_for_password_reset'),
                            'title' => translate('OTP_Verification'),
                            'verificationCode' => $otp,
                        ];
                        event(new DeliverymanPasswordResetEvent(email: $deliveryMan['email'], data: $data));
                    } catch (\Exception $ex) {
                        return response()->json(['message' => translate('email_send_failed')], 403);
                    }
                    return response()->json(['message' => translate('OTP_sent_successfully.') . ' ' . translate('Please_check_your_email')], 200);
                } else {
                    return response()->json(['message' => translate('email_failed')], 403);
                }
            } elseif ($verificationBy == 'phone') {
                $phoneNumber = $deliveryMan->country_code ? '+' . $deliveryMan->country_code . $deliveryMan->phone : $deliveryMan->phone;
                SMSModule::sendCentralizedSMS($phoneNumber, $otp);
                return response()->json(['message' => translate('OTP_sent_successfully.') . ' ' . translate('Please_check_your_phone')], 200);
            }
            return response()->json(['message' => translate('OTP_sent_successfully.')], 200);
        }

        return response()->json(['errors' => [
            ['code' => 'not-found', 'message' => translate('user_not_found')]
        ]], 403);
    }

    public function otp_verification_submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'otp' => 'required',
            'identity' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $data = PasswordReset::where(['token' => $request['otp'], 'identity' => $request['identity'], 'user_type' => 'delivery_man'])->first();
        if (!$data) {
            return response()->json(['message' => translate('Invalid_OTP')], 403);
        }

        $timeDiff = $data->created_at->diffInMinutes(Carbon::now());

        if ($timeDiff > 2) {
            PasswordReset::where(['token' => $request['otp'], 'user_type' => 'delivery_man'])->delete();
            return response()->json(['message' => translate('OTP_expired')], 403);
        }

        $deliveryManPhone = DeliveryMan::where(['phone' => $request['identity']])->orWhere(['email' => $request['identity']])->first();
        return response()->json([
            'message' => translate('OTP_verified_successfully'),
            'phone' => $deliveryManPhone['phone'],
            'email' => $deliveryManPhone['email'],
        ], 200);
    }


    public function reset_password_submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required',
            'password' => 'same:confirm_password|' . app(PasswordPolicy::class)->ruleString(),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        DeliveryMan::where(['phone' => $request['phone']])
            ->update(['password' => bcrypt(str_replace(' ', '', $request['password']))]);

        PasswordReset::where(['identity' => $request['phone'], 'user_type' => 'delivery_man'])->delete();

        return response()->json(['message' => translate('Password_changed_successfully')], 200);

    }
}
