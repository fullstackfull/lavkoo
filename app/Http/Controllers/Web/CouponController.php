<?php

namespace App\Http\Controllers\Web;

use App\Utils\Helpers;
use App\Http\Controllers\Controller;
use App\Services\Analytics\Analytics;
use App\Models\Coupon;
use App\Models\Order;
use App\Utils\CartManager;
use App\Utils\OrderManager;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function apply(Request $request): JsonResponse|RedirectResponse
    {
        self::removeCurrentCouponActivity();

        $result = OrderManager::getTotalCouponAmount(request: $request, couponCode: $request['code']);

        // A rejected coupon is the more useful half of this measurement: an expired code still
        // printed on a flyer shows up as a spike of rejections against one code, and nothing else
        // in the system would ever surface it.
        app(Analytics::class)->couponApplied(
            code: (string) $request['code'],
            accepted: (bool) $result['status'],
            discount: $result['status'] ? (float) ($result['discount'] ?? 0) : null,
            reason: $result['status'] ? null : 'rejected',
        );

        if ($result['status']) {
            if ($request->ajax()) {
                return response()->json([
                    'status' => 1,
                    'messages' => $result['messages']
                ]);
            }
            Toastr::success($result['messages']);
        }

        if ($request->ajax()) {
            return response()->json([
                'status' => 0,
                'messages' => ['0' => $result['messages'] ?? translate('invalid_coupon')]
            ]);
        }
        Toastr::error($result['messages'] ?? translate('invalid_coupon'));
        return back();
    }

    public function removeCoupon(Request $request): JsonResponse|RedirectResponse
    {
        self::removeCurrentCouponActivity();

        if ($request->ajax()) {
            return response()->json(['messages' => translate('coupon_removed')]);
        }
        Toastr::success(translate('coupon_removed'));
        return back();
    }

    function removeCurrentCouponActivity(): void
    {
        session()->forget('coupon_code');
        session()->forget('coupon_type');
        session()->forget('coupon_bearer');
        session()->forget('coupon_discount');
        session()->forget('coupon_seller_id');
    }
}
