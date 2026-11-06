<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Order;
use App\Services\Fulfillment\CustomerFulfillmentPresenter;
use App\Services\Fulfillment\MixedProviderNotice;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Customer-facing fulfillment data for the mobile apps. Purely additive: new endpoints, no existing
 * response shape changes. Customers see shipments, statuses and tracking — never provider names.
 */
class FulfillmentController extends Controller
{
    /** GET /api/v1/cart/fulfillment-notice — should the app warn that the order may arrive in several shipments? */
    public function cartNotice(Request $request, MixedProviderNotice $notice): JsonResponse
    {
        $user = Helpers::getCustomerInformation($request);

        $productIds = Cart::query()
            ->when($user == 'offline', static fn ($query) => $query->where(['customer_id' => $request->guest_id, 'is_guest' => 1]))
            ->when($user != 'offline', static fn ($query) => $query->where(['customer_id' => $user->id, 'is_guest' => '0']))
            ->where('is_checked', 1)
            ->pluck('product_id');

        return response()->json(['mixed_provider_notice' => $notice->forProducts($productIds)], 200);
    }

    /** GET /api/v1/customer/order/fulfillments?order_id= — the shipments of one of the caller's own orders. */
    public function orderFulfillments(Request $request, CustomerFulfillmentPresenter $presenter): JsonResponse
    {
        $validator = Validator::make($request->all(), ['order_id' => 'required']);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        // Same ownership rule as getOrderById: an authenticated customer's own orders, or the guest's. A guest id is a number: MySQL
        // compares a numeric column with the text "abc" as 0, which would match an order stored with customer_id 0.
        $guestId = $request['guest_id'];
        $order = !auth('api')->check() && !(is_scalar($guestId) && ctype_digit((string) $guestId) && (int) $guestId > 0)
            ? null
            : Order::where('id', $request['order_id'])
                ->where(function ($query) use ($request) {
                    auth('api')->check()
                        ? $query->where('customer_id', auth('api')->id())
                        : $query->where(['customer_id' => (int) $request['guest_id'], 'is_guest' => 1]);
                })
                ->first(['id']);

        if (!$order) {
            return response()->json(['errors' => [['code' => 'order', 'message' => translate('not_found')]]], 404);
        }

        return response()->json(['fulfillments' => $presenter->forOrder((int) $order->id)], 200);
    }
}
