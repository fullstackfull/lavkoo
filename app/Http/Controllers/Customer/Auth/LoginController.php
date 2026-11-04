<?php

namespace App\Http\Controllers\Customer\Auth;

use App\Services\Web\CustomerAuthService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Utils\CartManager;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function __construct(
        private readonly CustomerAuthService $customerAuthService,
    )
    {
        $this->middleware('guest:customer', ['except' => ['logout']]);
    }

    public function submit(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'password' => 'required'
        ]);

        $user = User::where(['phone' => $request->user_id])->orWhere(['email' => $request->user_id])->first();
        $remember = (bool)$request['remember'];

        //login attempt check start
        $max_login_hit = getWebConfig(name: 'maximum_login_hit') ?? 5;
        $temp_block_time = getWebConfig(name: 'temporary_login_block_time') ?? 5; //seconds
        if (!isset($user)) {
            if ($request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => translate('credentials_doesnt_match'),
                    'redirect_url' => ''
                ]);
            } else {
                Toastr::error(translate('credentials_doesnt_match'));
                return back()->withInput();
            }
        }

        //phone or email verification check start
        $phone_verification = getLoginConfig(key: 'phone_verification');
        $email_verification = getLoginConfig(key: 'email_verification');
        if ($phone_verification && !$user->is_phone_verified) {
            if ($request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => translate('account_phone_not_verified'),
                    'redirect_url' => route('customer.auth.check-verification', ['identity' => base64_encode($user['phone']), 'type' => base64_encode('phone_verification')]),
                ]);
            }
            return redirect(route('customer.auth.check-verification', ['identity' => base64_encode($user['phone']), 'type' => base64_encode('phone_verification')]));
        }
        if ($email_verification && !$user->is_email_verified) {
            if ($request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => translate('account_email_not_verified'),
                    'redirect_url' => route('customer.auth.check-verification', ['identity' => base64_encode($user['email']), 'type' => base64_encode('email_verification')]),
                ]);
            }
            return redirect(route('customer.auth.check-verification', ['identity' => base64_encode($user['email']), 'type' => base64_encode('email_verification')]));
        }

        if (isset($user->temp_block_time) && Carbon::parse($user->temp_block_time)->DiffInSeconds() <= $temp_block_time) {
            $time = $temp_block_time - Carbon::parse($user->temp_block_time)->DiffInSeconds();

            if ($request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans(),
                    'redirect_url' => ''
                ]);
            } else {
                Toastr::error(translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans());
                return back()->withInput();
            }
        }

        if (isset($user) && auth('customer')->attempt(['email' => $user['email'], 'password' => $request['password']], $remember)) {

            if (!$user->is_active) {
                auth()->guard('customer')->logout();
                if ($request->ajax()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => translate('your_account_is_suspended'),
                    ]);
                } else {
                    Toastr::error(translate('your_account_is_suspended'));
                    return back()->withInput();
                }
            }

            Toastr::success(translate('welcome_to') . ' ' . getWebConfig(name: 'company_name') . '!');
            CartManager::cartListSessionToDatabase();

            $user->login_hit_count = 0;
            $user->is_temp_blocked = 0;
            $user->temp_block_time = null;
            $user->updated_at = now();
            $user->save();

            $redirect_url = "";
            $previous_url = url()->previous();

            if (
                strpos($previous_url, 'checkout-complete') !== false ||
                strpos($previous_url, 'offline-payment-checkout-complete') !== false ||
                strpos($previous_url, 'track-order') !== false
            ) {
                $redirect_url = route('home');
            }

            if ($request->ajax()) {
                return response()->json([
                    'status' => 'success',
                    'message' => translate('login_successful'),
                    'redirect_url' => $redirect_url,
                ]);
            } else {
                return back();
            }

        } else {

            //login attempt check start
            if (isset($user->temp_block_time) && Carbon::parse($user->temp_block_time)->diffInSeconds() <= $temp_block_time) {
                $time = $temp_block_time - Carbon::parse($user->temp_block_time)->diffInSeconds();

                $ajax_message = [
                    'status' => 'error',
                    'message' => translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans(),
                    'redirect_url' => ''
                ];
                Toastr::error(translate('please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans());

            } elseif ($user->is_temp_blocked == 1 && Carbon::parse($user->temp_block_time)->diffInSeconds() >= $temp_block_time) {

                $user->login_hit_count = 0;
                $user->is_temp_blocked = 0;
                $user->temp_block_time = null;
                $user->updated_at = now();
                $user->save();

                $ajax_message = [
                    'status' => 'error',
                    'message' => translate('credentials_doesnt_match'),
                    'redirect_url' => ''
                ];
                Toastr::error(translate('credentials_doesnt_match'));

            } elseif ($user->login_hit_count >= $max_login_hit && $user->is_temp_blocked == 0) {
                $user->is_temp_blocked = 1;
                $user->temp_block_time = now();
                $user->updated_at = now();
                $user->save();

                $time = $temp_block_time - Carbon::parse($user->temp_block_time)->diffInSeconds();

                $ajax_message = [
                    'status' => 'error',
                    'message' => translate('too_many_attempts._please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans(),
                    'redirect_url' => ''
                ];
                Toastr::error(translate('too_many_attempts._please_try_again_after_') . CarbonInterval::seconds($time)->cascade()->forHumans());
            } else {
                $ajax_message = [
                    'status' => 'error',
                    'message' => translate('credentials_doesnt_match'),
                    'redirect_url' => ''
                ];
                Toastr::error(translate('credentials_doesnt_match'));

                $user->login_hit_count += 1;
                $user->save();
            }

            if ($request->ajax()) {
                return response()->json($ajax_message);
            } else {
                return back()->withInput();
            }
        }
    }

    public function logout(Request $request)
    {
        $authAttemptRedirectUrl = $this->customerAuthService->getCustomerAuthReturnURL();
        auth()->guard('customer')->user()?->update(['cm_firebase_token_web' => null]);
        auth()->guard('customer')->logout();
        session()->forget('wish_list');
        session()->forget('customer_fcm_topic');
        Toastr::success(translate('come_back_soon') . '!');
        return redirect($authAttemptRedirectUrl);
    }

    public function getLoginModalView(Request $request): JsonResponse
    {
        return response()->json([
            'login_modal' => view(VIEW_FILE_NAMES['get_login_modal_data'])->render(),
            'register_modal' => view(VIEW_FILE_NAMES['get_register_modal_data'])->render(),
        ]);
    }
}
