<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Events\RefundEvent;
use App\Http\Controllers\Controller;
use App\Models\DeliveryMan;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\RefundRequest;
use App\Models\RefundStatus;
use App\Models\User;
use App\Utils\CustomerManager;
use App\Utils\Helpers;
use App\Utils\OrderManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\Marketplace\ReturnLogisticsService;

class RefundController extends Controller
{
    public function list(Request $request):JsonResponse
    {
        $seller = $request->seller;

        $startDate = null;
        $endDate = null;
        if (isset($request['date_type']) && $request['date_type'] == 'custom_date') {
            if (isset($request['start_date']) && !is_null($request['start_date']) && isset($request['end_date']) && !is_null($request['end_date'])) {
                $startFormatted = \Carbon\Carbon::parse($request['start_date'])->format('m/d/Y');
                $endFormatted = \Carbon\Carbon::parse($request['end_date'])->format('m/d/Y');
                $dateRange = $startFormatted . ' - ' . $endFormatted;
            } else {
                $dateRange = now()->subDays(6)->format('m/d/Y') . ' - ' . now()->format('m/d/Y');
            }

            list($startDate, $endDate) = explode(' - ', $dateRange);
            $startDate = Carbon::createFromFormat('m/d/Y', trim($startDate));
            $endDate = Carbon::createFromFormat('m/d/Y', trim($endDate));
            $startDate = $startDate->startOfDay();
            $endDate = $endDate->endOfDay();
        }

        $refund_list = RefundRequest::with('customer', 'product', 'orderDetails')
            ->with(['order' => function ($query) {
                $query->select('id', 'payment_method');
            }])
            ->whereHas('order', function ($query) use ($seller) {
                $query->where('seller_is', 'seller')->where('seller_id', $seller['id']);
            })
            ->when($request['search'], function ($query) use ($request) {
                $key = searchTerms($request['search']);
                foreach ($key as $value) {
                    $query->where('order_id', 'like', "%{$value}%");
                }
            })
            ->when(isset($request['date_type']) && $request['date_type'] == 'this_year', function ($query) {
                return $query->whereYear('created_at', date('Y'));
            })
            ->when(isset($request['date_type']) && $request['date_type'] == 'this_month', function ($query) {
                return $query->whereMonth('created_at', date('m'))
                    ->whereYear('created_at', date('Y'));
            })
            ->when(isset($request['date_type']) && $request['date_type'] == 'this_week', function ($query) {
                return $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]);
            })
            ->when(isset($request['date_type']) && $request['date_type'] == 'today', function ($query) {
                return $query->whereBetween('created_at', [Carbon::now()->startOfDay(), Carbon::now()->endOfDay()]);
            })
            ->when(isset($request['date_type']) && $request['date_type'] == 'custom_date' && !empty($startDate) && !empty($endDate), function ($query) use ($startDate, $endDate) {
                return $query->whereBetween('created_at', [$startDate, $endDate]);
            })
            ->latest()->get();
        return response()->json($refund_list);
    }

    public function getSingleItem(Request $request): JsonResponse
    {
        $seller = $request->seller;
        $refundList = RefundRequest::with('customer', 'product', 'orderDetails')
            ->with(['order' => function ($query) {
                $query->select('id', 'payment_method');
            }])
            ->whereHas('order', function ($query) use ($seller) {
                $query->where('seller_is', 'seller')->where('seller_id', $seller['id']);
            })
            ->where('id', $request['id'])
            ->first();

        return response()->json($refundList);
    }

    public function refund_details(Request $request):JsonResponse
    {
        $seller = $request->seller;
        // Ownership: scope the order line to this seller so one seller can't read another's line/refund detail.
        $order_details = OrderDetail::where('seller_id', $seller['id'])->find($request->order_details_id);
        if (!$order_details) {
            return response()->json(['errors' => [['code' => 'order-details', 'message' => translate('not_found')]]], 404);
        }
        $refund_request = RefundRequest::with('refundStatus')->where('order_details_id', $request->order_details_id)->get();

        $order = Order::find($order_details->order_id);


        $data = [];
        $subtotal = ($order_details->price * $order_details->qty) - $order_details->discount + $order_details->tax;
        $refundDetailsSummery = OrderManager::getRefundDetailsForSingleOrderDetails(orderDetailsId: $order_details['id']);

        $data['data'] = $seller;
        $data['product_price'] = $order_details->price;
        $data['quntity'] = $order_details->qty;
        $data['product_total_discount'] = $order_details->discount;
        $data['product_total_tax'] = $order_details->tax;
        $data['subtotal'] = $subtotal;
        $data['coupon_discount'] = $refundDetailsSummery['coupon_discount'];
        $data['refund_amount'] = $refundDetailsSummery['total_refundable_amount'];
        $data['refund_request'] = $refund_request;
        $data['referral_discount'] = $refundDetailsSummery['referral_discount'];
        $data['deliveryman_details'] = DeliveryMan::find($order->delivery_man_id);

        return response()->json($data, 200);


    }

    public function refund_status_update(Request $request):JsonResponse
    {
        $seller = $request->seller;
        $validator = Validator::make($request->all(), [
            'refund_status' => 'required',
            'refund_request_id' => 'required',
            'note' => 'required_if:refund_status,rejected',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)]);
        }

        $refund = RefundRequest::whereHas('order', function ($query) use ($seller) {
            $query->where('seller_is', 'seller')->where('seller_id', $seller['id']);
        })->find($request->refund_request_id);

        // Answered rather than fataled. A refund id belonging to another seller — or to nothing at
        // all — used to reach the next line and die on a null, so a cross-tenant probe got a 500 HTML
        // page instead of a not-found.
        if (!$refund) {
            return response()->json(['errors' => [
                ['code' => 'refund', 'message' => translate('refund_request_not_found')],
            ]], 404);
        }

        $user = User::find($refund->customer_id);

        $loyalty_point_status = getWebConfig(name: 'loyalty_point_status');

        if ($loyalty_point_status == 1) {
            $loyalty_point = CustomerManager::countLoyaltyPointForAmount($refund->order_details_id);

            if ($user->loyalty_point < $loyalty_point && $request->refund_status == 'approved') {
                return response()->json(['message' => 'Customer has not sufficient loyalty point to take refund for this order'], 403);
            }
        }

        if ($refund->change_by == 'admin') {

            return response()->json(['message' => 'refunded status can not be changed!! Admin already changed the status : ' . $refund->status . '!!'], 403);
        }
        if ($refund->status != 'refunded') {
            $orderDetails = OrderDetail::find($refund->order_details_id);
            $refund_status = new RefundStatus;
            $refund_status->refund_request_id = $refund->id;
            $refund_status->change_by = 'seller';
            $refund_status->change_by_id = $seller['id'];
            $refund_status->status = $request->refund_status;

            if ($request->refund_status == 'pending') {
                $orderDetails->refund_request = 1;
            } elseif ($request->refund_status == 'approved') {
                $orderDetails->refund_request = 2;
                $refund->approved_note = $request->note;

                $refund_status->message = $request->note;
            } elseif ($request->refund_status == 'rejected') {
                $orderDetails->refund_request = 3;
                $refund->rejected_note = $request->note;

                $refund_status->message = $request->note;
            }

            $orderDetails->save();

            $refund->status = $request->refund_status;
            $refund->change_by = 'seller';
            $refund->save();
            $refund_status->save();

            $order = Order::find($refund->order_id);

            // Approving a refund on a physical product means goods are coming back. Nothing recorded
            // that before, so nothing ever restocked them: the seller gave back the money and quietly
            // lost the units too. Opening the return here is what closes that loop — and it is
            // idempotent on the refund, so approving twice does not create a second one.
            if ($request->refund_status === 'approved') {
                app(ReturnLogisticsService::class)->openForApprovedRefund(
                    refund: $refund,
                    orderDetails: $orderDetails,
                    sellerId: $seller['id'],
                );
            }

            event(new RefundEvent(status: $request['refund_status'], order: $order, refund: $refund, orderDetails: $orderDetails));
            return response()->json(['message' => 'refund status updated successfully!'], 200);
        } else {
            return response()->json(['message' => 'refunded status can not be changed!!'], 403);
        }

    }
}
