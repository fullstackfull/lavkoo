<?php

namespace App\Http\Controllers\RestAPI\v2\delivery_man\auth;

use App\Events\DeliverymanPasswordResetEvent;
use App\Services\Platform\PasswordPolicy;
use App\Http\Controllers\Controller;
use App\Models\DeliveryMan;
use App\Models\PasswordReset;
use App\Utils\Helpers;
use App\Utils\RequestText;
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
    /** How long a delivery man has, once the code is verified, to choose the new password. */
    private const VERIFIED_CODE_MINUTES = 30;

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

        $identity = RequestText::of($request['identity']);
        PasswordReset::where(['user_type' => 'delivery_man', 'identity' => $identity])->delete();
        $deliveryMan = $identity === '' ? null : DeliveryMan::where(['phone' => $identity])->orWhere(['email' => $identity])->first();
        $verificationBy = getWebConfig(name: 'deliveryman_forgot_password_method') ?? 'phone';

        if (isset($deliveryMan)) {
            $otp = (config('app.mode') == 'live') ? rand(1000, 9999) : 1234;

            PasswordReset::insert([
                'identity' => $identity,
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

        $identity = RequestText::of($request['identity']);
        $code = RequestText::of($request['otp']);
        $data = $identity === '' || $code === '' ? null : PasswordReset::where(['token' => $code, 'identity' => $identity, 'user_type' => 'delivery_man'])->first();
        if (!$data) {
            return response()->json(['message' => translate('Invalid_OTP')], 403);
        }

        $timeDiff = $data->created_at->diffInMinutes(Carbon::now());

        if ($timeDiff > 2) {
            PasswordReset::where(['token' => $request['otp'], 'user_type' => 'delivery_man'])->delete();
            return response()->json(['message' => translate('OTP_expired')], 403);
        }

        $data->update(['expires_at' => now()->addMinutes(self::VERIFIED_CODE_MINUTES)]);

        $deliveryManPhone = DeliveryMan::where(['phone' => $identity])->orWhere(['email' => $identity])->first();
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

        $phone = RequestText::of($request['phone']);
        $deliveryMen = $phone === '' ? collect() : DeliveryMan::where(['phone' => $phone])->limit(2)->get();
        $deliveryMan = $deliveryMen->count() === 1 ? $deliveryMen->first() : null;
        $identities = $deliveryMan ? $this->identitiesOf(deliveryMan: $deliveryMan) : [];
        if (!PasswordReset::where('user_type', 'delivery_man')->whereIn('identity', $identities)->where('expires_at', '>', now())->exists()) {
            return response()->json(['message' => translate('Invalid_OTP')], 403);
        }

        DeliveryMan::whereKey($deliveryMan['id'])->update(['password' => bcrypt(str_replace(' ', '', $request['password']))]);

        PasswordReset::where('user_type', 'delivery_man')->whereIn('identity', $identities)->delete();

        return response()->json(['message' => translate('Password_changed_successfully')], 200);

    }

    /** What a delivery man may have typed to be sent a code: their phone or their e-mail. */
    private function identitiesOf(DeliveryMan $deliveryMan): array
    {
        return array_values(array_filter([$deliveryMan['phone'], $deliveryMan['email']], static fn (?string $identity): bool => $identity !== null && $identity !== ''));
    }
}
