<?php

namespace App\Http\Controllers\RestAPI\v1;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Analytics;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Utils\CartManager;
use App\Utils\Helpers;
use App\Utils\OrderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    public function list(Request $request): array
    {
        $customer_id = $request->user() ? $request->user()->id : '0';

        $coupons = Coupon::with('seller.shop')
            ->withCount(['order' => function ($query) use ($customer_id) {
                $query->where(['customer_id' => $customer_id]);
            }])
            ->where(['status' => 1])
            ->whereIn('customer_id', [$customer_id, '0'])
            ->whereDate('start_date', '<=', now())
            ->whereDate('expire_date', '>=', now())
            ->select('coupons.*', DB::raw('DATE(expire_date) as plain_expire_date'))
            ->inRandomOrder()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        return [
            'total_size' => $coupons->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'coupons' => $coupons->items()
        ];
    }

    public function applicable_list(Request $request): JsonResponse
    {
        $coupons = collect();
        $customer_id = $request->user() ? $request->user()->id : '0';

        $cart_data = Cart::where(['customer_id' => $customer_id, 'is_guest' => 0, 'is_checked' => 1])->pluck('product_id');
        $productGroup = Product::whereIn('id', $cart_data)->select('id', 'added_by', 'user_id')->get();

        if ($cart_data->count() > 0 && $productGroup->count() > 0) {
            $couponQuery = Coupon::active()
                ->with('seller.shop')
                ->select('coupons.*', DB::raw('DATE(expire_date) as plain_expire_date'))
                ->withCount(['order' => function ($query) use ($customer_id) {
                    $query->where('customer_id', $customer_id);
                }])
                ->whereIn('customer_id', [$customer_id, '0'])
                ->whereDate('start_date', '<=', now())
                ->whereDate('expire_date', '>=', now())
                ->get();

            $adminCoupon = null;
            $vendorCoupon = null;

            if ($productGroup->where('added_by', 'admin')->count() > 0) {
                $adminCoupon = $couponQuery->where('coupon_bearer', 'inhouse');
            }


            if ($productGroup->where('added_by', 'seller')->count() > 0) {
                $sellerIds = $productGroup->pluck('user_id')->unique()->toArray();
                $sellerIds = array_merge($sellerIds, [0]);
                $vendorCoupon = $couponQuery->where('coupon_bearer', 'seller')->whereIn('seller_id', $sellerIds);
            }

            if ($adminCoupon) {
                $coupons = $coupons->merge($adminCoupon);
            }

            if ($vendorCoupon) {
                $coupons = $coupons->merge($vendorCoupon);
            }

            $coupons = $coupons->filter(function ($data) {
                return (($data->order_count < $data->limit) || empty($data->limit)) && ($data->start_date <= now() && $data->expire_date >= now());
            });

            $customer_order_count = Order::where('customer_id', $customer_id)->count();
            if ($customer_order_count > 0) {
                $coupons = $coupons->filter(function ($data) {
                    return $data->coupon_type != 'first_order';
                });
            }
        }

        return response()->json($coupons->values() ?? [], 200);
    }

    public function apply(Request $request): JsonResponse
    {
        $result = OrderManager::getTotalCouponAmount(request: $request, couponCode: $request['code']);

        // The same measurement the web coupon form records, so a code's rejection rate is the
        // code's, not the storefront's.
        app(Analytics::class)->couponApplied(
            code: (string) $request['code'],
            accepted: (bool) $result['status'],
            discount: $result['status'] ? (float) ($result['discount'] ?? 0) : null,
            reason: $result['status'] ? null : 'rejected',
        );

        if ($result['status']) {
            return response()->json([
                'coupon_discount' => $result['discount'],
                'coupon_type' => $result['coupon_type']
            ], 200);
        }
        return response()->json($result['messages'] ?? translate('invalid_coupon'), 202);
    }

    public function getSellerWiseCoupon(Request $request, $slug): array
    {
        $customerId = $request->user() ? $request->user()->id : '0';
        $shop = Shop::where('slug', $slug)->first();
        $sellerIds = ['0'];
        $coupons = Coupon::with('seller.shop')
            ->where(['status' => 1])
            ->whereDate('start_date', '<=', date('Y-m-d'))
            ->whereDate('expire_date', '>=', date('Y-m-d'))
            ->when($shop && $shop['author_type'] == 'admin', function ($query) use ($sellerIds) {
                return $query->whereNull('seller_id');
            })
            ->when($shop && $shop['author_type'] != 'admin', function ($query) use ($sellerIds, $shop) {
                $sellerIds[] = $shop['seller_id'];
                return $query->whereIn('seller_id', $sellerIds);
            })
            ->when($customerId == '0', function ($query) {
                return $query->where('customer_id', 0);
            })
            ->select('coupons.*', DB::raw('DATE(expire_date) as plain_expire_date'))
            ->inRandomOrder()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        return [
            'total_size' => $coupons->total(),
            'limit' => (int)$request['limit'],
            'offset' => (int)$request['offset'],
            'coupons' => $coupons->items()
        ];
    }
}
